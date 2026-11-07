<?php

namespace App\Sync;

use App\Enums\ColumnType;
use App\Models\ManagedTable;
use App\Models\Mapping;
use App\Models\SchemaHistory;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The only place that runs CREATE TABLE / ALTER TABLE. Add-only: it never drops,
 * retypes or renames, and it only touches tables the sync created itself.
 */
class SchemaManager
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** Columns every managed table has; mappings can't use these names. */
    public const SYSTEM_COLUMNS = ['id', '_record_id', '_source_table_id', '_synced_at', '_removed_at'];

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

            if (isset($existing[$target]) && ! in_array($existing[$target], self::COMPATIBLE[$column['type']->value], true)) {
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

        $plan = $mapping->columns->map(fn ($c) => ['target' => $c->target_column, 'type' => $c->sourceColumn->type])->values()->all();

        if ($errors = $this->validatePlan($table, $plan)) {
            throw new SchemaException($errors);
        }

        $changes = [];

        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->id();
                $blueprint->unsignedBigInteger('_record_id')->unique();
                $blueprint->unsignedBigInteger('_source_table_id')->index();
                $blueprint->timestamp('_synced_at')->nullable();
                $blueprint->timestamp('_removed_at')->nullable()->index();
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
