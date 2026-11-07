<?php

namespace App\Sync;

use App\Enums\ColumnType;
use App\Models\Mapping;
use App\Models\SourceRecord;
use App\Models\SourceTable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes source records into the target tables: each mapping takes its columns,
 * under their target names. Rows are found by _record_id.
 */
class TargetWriter
{
    public function upsertRecord(SourceRecord $record): void
    {
        foreach ($this->mappings($record->source_table_id) as $mapping) {
            DB::table($mapping->managedTable->name)->updateOrInsert(
                ['_record_id' => $record->id],
                $this->payload($record, $mapping),
            );
        }
    }

    public function markRemoved(SourceRecord $record): void
    {
        foreach ($this->mappings($record->source_table_id) as $mapping) {
            DB::table($mapping->managedTable->name)
                ->where('_record_id', $record->id)
                ->update(['_removed_at' => $record->removed_at ?? now(), '_synced_at' => now()]);
        }
    }

    /**
     * Writes every record of a source table (used after configuration changes).
     */
    public function projectAll(SourceTable $table, ?Mapping $only = null): int
    {
        $mappings = $only ? collect([$only->loadMissing('managedTable', 'columns.sourceColumn')]) : $this->mappings($table->id);
        $written = 0;

        foreach ($mappings as $mapping) {
            $updateColumns = [...$mapping->columns->pluck('target_column')->all(), '_source_table_id', '_synced_at', '_removed_at'];

            $table->records()->chunkById(500, function (Collection $records) use ($mapping, $updateColumns, &$written) {
                $rows = $records->map(fn (SourceRecord $record) => ['_record_id' => $record->id] + $this->payload($record, $mapping))->all();
                DB::table($mapping->managedTable->name)->upsert($rows, ['_record_id'], $updateColumns);
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
            $payload[$column->target_column] = $this->databaseValue($column->sourceColumn->type, $value);
        }

        return $payload + [
            '_source_table_id' => $record->source_table_id,
            '_synced_at' => now(),
            '_removed_at' => $record->removed_at,
        ];
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
