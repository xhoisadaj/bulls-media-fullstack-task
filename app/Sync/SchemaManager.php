<?php

namespace App\Sync;

use App\Enums\ColumnType;
use App\Models\ManagedTable;
use App\Models\Mapping;
use App\Models\MappingColumn;
use App\Models\SchemaHistory;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The only place that runs CREATE / ALTER / DROP TABLE, and only on tables the sync created itself.
 * Syncs only ever add. Removing is an admin action, allowed only for what is provably empty and
 * unmapped, so no data can be lost. It never retypes or renames.
 */
class SchemaManager
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** The one column every managed table has: our row ID (R-101). Mappings can't use this name. */
    public const SYSTEM_COLUMNS = ['id'];

    /** MySQL reserved words that would make awkward or invalid identifiers. */
    public const RESERVED = [
        'add', 'all', 'alter', 'and', 'as', 'asc', 'between', 'by', 'case', 'check', 'column', 'constraint',
        'create', 'cross', 'current_date', 'current_time', 'current_timestamp', 'database', 'default', 'delete',
        'desc', 'distinct', 'drop', 'else', 'exists', 'false', 'for', 'foreign', 'from', 'grant', 'group',
        'having', 'in', 'index', 'inner', 'insert', 'interval', 'into', 'is', 'join', 'key', 'keys', 'left',
        'like', 'limit', 'lock', 'match', 'not', 'null', 'on', 'or', 'order', 'outer', 'primary', 'range',
        'references', 'rename', 'replace', 'right', 'select', 'set', 'show', 'table', 'then', 'to', 'true',
        'union', 'unique', 'update', 'usage', 'use', 'using', 'values', 'when', 'where', 'with', 'year',
        'rank', 'row', 'rows', 'window', 'function', 'system', 'user',
    ];

    /** Accepted MySQL type names per column type, to detect conflicts with existing columns. */
    private const COMPATIBLE = [
        'string' => ['varchar', 'char'],
        'text' => ['text', 'mediumtext', 'longtext'],
        'integer' => ['bigint', 'int', 'integer', 'mediumint', 'smallint'],
        'decimal' => ['decimal', 'double', 'float'],
        'date' => ['date'],
        'datetime' => ['datetime', 'timestamp'],
        'boolean' => ['tinyint', 'boolean', 'bool'],
        'image_url' => ['text', 'mediumtext', 'longtext', 'varchar'],
    ];

    public function nameError(string $name, string $what = 'Name'): ?string
    {
        if (! preg_match(self::NAME_PATTERN, $name)) {
            return $what.' "'.$name.'" must start with a lowercase letter and use only a-z, 0-9 and _ (max 64 characters). Try "'.$this->suggestName($name).'".';
        }

        if (in_array($name, self::RESERVED, true)) {
            return $what.' "'.$name.'" is a reserved SQL word. Try "'.$name.'_value".';
        }

        return null;
    }

    public function suggestName(string $name): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', $name), '_'));

        if ($slug === '' || ! ctype_alpha($slug[0])) {
            $slug = 'col_'.$slug;
        }

        return substr($slug, 0, 64);
    }

    public function isManaged(string $table): bool
    {
        return ManagedTable::query()->where('name', $table)->exists();
    }

    /**
     * Every reason a mapping plan can't be applied. Empty means it's safe.
     *
     * @param  list<array{target: string, type: ColumnType}>  $columns
     * @return list<string>
     */
    public function validatePlan(string $table, array $columns, ?int $ignoreMappingId = null): array
    {
        $errors = [];

        if ($error = $this->nameError($table, 'Table name')) {
            $errors[] = $error;
        } elseif (Schema::hasTable($table) && ! $this->isManaged($table)) {
            $errors[] = 'Table "'.$table.'" belongs to the application and can\'t be used as a target. Choose another name.';
        }

        $seen = [];
        $existing = Schema::hasTable($table) ? $this->existingTypes($table) : [];

        foreach ($columns as $column) {
            $target = $column['target'];

            if ($error = $this->nameError($target, 'Column')) {
                $errors[] = $error;

                continue;
            }

            if (in_array($target, self::SYSTEM_COLUMNS, true)) {
                $errors[] = 'Column "'.$target.'" is reserved for the sync system.';

                continue;
            }

            if (isset($seen[$target])) {
                $errors[] = 'Column "'.$target.'" is used twice in this mapping.';

                continue;
            }

            $seen[$target] = true;

            if (isset($existing[$target]) && ! $this->fits($existing[$target], $column['type'])) {
                $errors[] = 'Column "'.$table.'.'.$target.'" already exists as '.$existing[$target].', which doesn\'t fit type '.$column['type']->value.'. Map it to a new column instead.';
            }
        }

        return $errors;
    }

    /**
     * Creates the target table and adds missing columns for a mapping.
     *
     * @return list<string> human-readable list of changes made
     *
     * @throws SchemaException
     */
    public function apply(Mapping $mapping, ?User $user = null): array
    {
        $mapping->loadMissing('managedTable', 'columns.sourceColumn');
        $table = $mapping->managedTable->name;

        // New columns are added in the sheet's column order.
        $plan = $mapping->columns
            ->sortBy(fn ($c) => [$c->sourceColumn->position, $c->id])
            ->map(fn ($c) => ['target' => $c->target_column, 'type' => $c->targetType()])
            ->values()
            ->all();

        if ($errors = $this->validatePlan($table, $plan)) {
            throw new SchemaException($errors);
        }

        $changes = [];

        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->string('id', 32)->primary();
            });

            $this->log($mapping->managedTable->id, 'create_table', null, null, $user);
            $changes[] = 'Created table '.$table;
        }

        $existing = $this->existingTypes($table);

        foreach ($plan as $column) {
            if (isset($existing[$column['target']])) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $this->addColumn($blueprint, $column['target'], $column['type']);
            });

            $this->log($mapping->managedTable->id, 'add_column', $column['target'], $column['type']->value, $user);
            $changes[] = 'Added column '.$table.'.'.$column['target'].' ('.$column['type']->value.')';
        }

        return $changes;
    }

    /**
     * The table's own columns (not the sync's system columns), with what would block removing each.
     *
     * @return array<string, array{type: string, filled: int, mapped_by: list<string>, problem: ?string}>
     */
    public function columnUsage(ManagedTable $table): array
    {
        if (! Schema::hasTable($table->name)) {
            return [];
        }

        $mappedBy = MappingColumn::query()
            ->whereHas('mapping', fn ($q) => $q->where('managed_table_id', $table->id))
            ->with('mapping.sourceTable')
            ->get()
            ->groupBy('target_column')
            ->map(fn ($columns) => $columns->map(fn (MappingColumn $c) => $c->mapping->sourceTable->name)->unique()->values()->all());

        $usage = [];

        foreach ($this->existingTypes($table->name) as $column => $type) {
            if (in_array($column, self::SYSTEM_COLUMNS, true)) {
                continue;
            }

            $filled = DB::table($table->name)->whereNotNull($column)->count();
            $mapped = $mappedBy->get($column, []);

            $usage[$column] = [
                'type' => $type,
                'filled' => $filled,
                'mapped_by' => $mapped,
                'problem' => match (true) {
                    $mapped !== [] => 'mapped by '.implode(', ', $mapped).'; remove it from the mapping first',
                    $filled > 0 => $filled.' row(s) have data',
                    default => null,
                },
            ];
        }

        return $usage;
    }

    /**
     * Adds an empty column chosen by the admin (no sheet column fills it yet).
     *
     * @throws SchemaException
     */
    public function addEmptyColumn(ManagedTable $table, string $column, ColumnType $type, ?User $user = null): void
    {
        $errors = array_filter([
            Schema::hasTable($table->name) ? null : 'Table '.$table->name.' doesn\'t exist yet.',
            $this->nameError($column, 'Column'),
            in_array($column, self::SYSTEM_COLUMNS, true) ? 'Column "'.$column.'" is reserved for the sync system.' : null,
            Schema::hasTable($table->name) && Schema::hasColumn($table->name, $column) ? 'Column "'.$column.'" already exists in '.$table->name.'.' : null,
        ]);

        if ($errors !== []) {
            throw new SchemaException(array_values($errors));
        }

        Schema::table($table->name, fn (Blueprint $blueprint) => $this->addColumn($blueprint, $column, $type));
        $this->log($table->id, 'add_column', $column, $type->value, $user);
    }

    /**
     * Drops empty, unmapped columns. All or nothing: any problem and nothing is dropped.
     *
     * @param  list<string>  $columns
     *
     * @throws SchemaException
     */
    public function dropColumns(ManagedTable $table, array $columns, ?User $user = null): void
    {
        $usage = $this->columnUsage($table);
        $errors = [];

        foreach ($columns as $column) {
            if (in_array($column, self::SYSTEM_COLUMNS, true)) {
                $errors[] = 'Column "'.$column.'" is needed by the sync and can\'t be removed.';
            } elseif (! isset($usage[$column])) {
                $errors[] = 'Column "'.$column.'" doesn\'t exist in '.$table->name.'.';
            } elseif ($usage[$column]['problem'] !== null) {
                $errors[] = 'Column "'.$column.'" can\'t be removed: '.$usage[$column]['problem'].'.';
            }
        }

        if ($columns === [] || $errors !== []) {
            throw new SchemaException($errors ?: ['Choose at least one column.']);
        }

        Schema::table($table->name, fn (Blueprint $blueprint) => $blueprint->dropColumn($columns));

        foreach ($columns as $column) {
            $this->log($table->id, 'drop_column', $column, $usage[$column]['type'], $user);
        }
    }

    /**
     * Why the table can't be deleted. Empty means it's safe: no mapping writes into it and it holds no rows.
     *
     * @return list<string>
     */
    public function dropTableProblems(ManagedTable $table): array
    {
        $problems = [];
        $mappings = $table->mappings()->with('sourceTable')->get();

        if ($mappings->isNotEmpty()) {
            $problems[] = 'Mappings still write into it ('.$mappings->map(fn (Mapping $m) => $m->sourceTable->name)->implode(', ').'). Delete them first.';
        }

        if (Schema::hasTable($table->name) && ($rows = DB::table($table->name)->count()) > 0) {
            $problems[] = 'It holds '.$rows.' row(s).';
        }

        return $problems;
    }

    /**
     * Drops an empty, unmapped table and forgets it (its schema history goes with it, so the drop is logged).
     *
     * @throws SchemaException
     */
    public function dropTable(ManagedTable $table, ?User $user = null): void
    {
        if ($problems = $this->dropTableProblems($table)) {
            throw new SchemaException($problems);
        }

        Schema::dropIfExists($table->name);
        Log::notice('Target table dropped', ['table' => $table->name, 'user_id' => $user?->id ?? auth()->id()]);
        $table->delete();
    }

    /**
     * Whether values of a sheet column type can be saved in an existing MySQL column type.
     */
    public function fits(string $mysqlType, ColumnType $type): bool
    {
        return in_array(strtolower($mysqlType), self::COMPATIBLE[$type->value], true);
    }

    /**
     * The column type that matches an existing MySQL column type (varchar → String, bigint → Integer, …).
     */
    public function columnTypeOf(string $mysqlType): ?ColumnType
    {
        foreach (self::COMPATIBLE as $type => $names) {
            if ($type !== ColumnType::ImageUrl->value && in_array(strtolower($mysqlType), $names, true)) {
                return ColumnType::from($type);
            }
        }

        return null;
    }

    /**
     * The columns a mapping can write to in an existing table (system columns left out).
     *
     * @return array<string, string> column name => MySQL type name, in table order
     */
    public function targetColumns(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return array_diff_key($this->existingTypes($table), array_flip(self::SYSTEM_COLUMNS));
    }

    /**
     * @return array<string, string> column name => MySQL type name
     */
    public function existingTypes(string $table): array
    {
        $types = [];

        foreach (Schema::getColumns($table) as $column) {
            $types[$column['name']] = strtolower((string) $column['type_name']);
        }

        return $types;
    }

    private function addColumn(Blueprint $blueprint, string $name, ColumnType $type): void
    {
        match ($type) {
            ColumnType::String => $blueprint->string($name)->nullable(),
            ColumnType::Text, ColumnType::ImageUrl => $blueprint->text($name)->nullable(),
            ColumnType::Integer => $blueprint->bigInteger($name)->nullable(),
            ColumnType::Decimal => $blueprint->decimal($name, 20, ValueNormalizer::DECIMAL_SCALE)->nullable(),
            ColumnType::Date => $blueprint->date($name)->nullable(),
            ColumnType::DateTime => $blueprint->dateTime($name)->nullable(),
            ColumnType::Boolean => $blueprint->boolean($name)->nullable(),
        };
    }

    private function log(int $managedTableId, string $action, ?string $column, ?string $type, ?User $user): void
    {
        SchemaHistory::query()->create([
            'managed_table_id' => $managedTableId,
            'action' => $action,
            'column_name' => $column,
            'column_type' => $type,
            'user_id' => $user?->id ?? auth()->id(),
        ]);
    }
}
