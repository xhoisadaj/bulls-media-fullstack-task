<?php

namespace App\Sync;

use App\Enums\ColumnType;
use App\Models\Mapping;
use App\Models\MappingColumn;
use App\Models\SourceRecord;
use App\Models\SourceTable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes source records into the target tables: each mapping takes its columns, under their
 * target names. A target row is only our ID (R-101) plus the mapped columns; a removed row is deleted.
 */
class TargetWriter
{
    public function __construct(private readonly ValueNormalizer $normalizer) {}

    public function upsertRecord(SourceRecord $record): void
    {
        if ($record->isRemoved()) {
            $this->markRemoved($record);

            return;
        }

        foreach ($this->mappings($record->source_table_id) as $mapping) {
            DB::table($mapping->managedTable->name)->updateOrInsert(['id' => $record->code()], $this->payload($record, $mapping));
        }
    }

    public function markRemoved(SourceRecord $record): void
    {
        foreach ($this->mappings($record->source_table_id) as $mapping) {
            DB::table($mapping->managedTable->name)->where('id', $record->code())->delete();
        }
    }

    /**
     * Writes every current record of a source table (used after configuration changes).
     */
    public function projectAll(SourceTable $table, ?Mapping $only = null): int
    {
        $mappings = $only ? collect([$only->loadMissing('managedTable', 'columns.sourceColumn')]) : $this->mappings($table->id);
        $written = 0;

        foreach ($mappings as $mapping) {
            $updateColumns = $mapping->columns->pluck('target_column')->all();

            $table->records()->whereNull('removed_at')->chunkById(500, function (Collection $records) use ($mapping, $updateColumns, &$written) {
                $rows = $records->map(fn (SourceRecord $record) => ['id' => $record->code()] + $this->payload($record, $mapping))->all();
                DB::table($mapping->managedTable->name)->upsert($rows, ['id'], $updateColumns);
                $written += count($rows);
            });
        }

        return $written;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SourceRecord $record, Mapping $mapping): array
    {
        $payload = [];

        foreach ($mapping->columns as $column) {
            $value = $record->data[(string) $column->source_column_id] ?? null;
            $payload[$column->target_column] = $this->convert($column, $value);
        }

        return $payload;
    }

    /**
     * The stored (normalized) sheet value as the mapping column's database type. When the mapping
     * chose another type than the sheet column's, the value is converted; a value that doesn't
     * convert is saved empty (the original stays in the source record).
     */
    public function convert(MappingColumn $column, mixed $value): mixed
    {
        $type = $column->targetType();

        if ($value !== null && $type !== $column->sourceColumn->type) {
            $converted = $this->normalizer->normalize($type, (string) $value, (string) $value);
            $value = $converted->ok ? $converted->value : null;
        }

        return $this->databaseValue($type, $value);
    }

    private function databaseValue(ColumnType $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            ColumnType::Integer, ColumnType::Boolean => (int) $value,
            default => $value,
        };
    }

    /**
     * @return Collection<int, Mapping>
     */
    private function mappings(int $sourceTableId): Collection
    {
        return Mapping::query()
            ->where('source_table_id', $sourceTableId)
            ->where('is_active', true)
            ->with('managedTable', 'columns.sourceColumn')
            ->get();
    }
}
