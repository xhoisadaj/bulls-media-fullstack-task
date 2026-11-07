<?php

namespace App\Filament\Resources\Mappings\Pages;

use App\Enums\ColumnType;
use App\Models\Mapping;
use App\Models\SourceColumn;
use App\Sync\Backfiller;
use App\Sync\SchemaException;
use App\Sync\SchemaManager;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

/**
 * Shared by create and edit: validate the plan before saving, then create the table or
 * columns (ALTER TABLE, add-only) and backfill existing rows.
 */
trait MappingSaving
{
    /** @var list<int> */
    protected array $trackedBefore = [];

    /**
     * @param  array<int|string, array<string, mixed>>  $columns
     */
    protected function validatePlan(string $table, array $columns): void
    {
        $plan = [];

        foreach ($columns as $column) {
            $source = SourceColumn::query()->find($column['source_column_id'] ?? null);

            if ($source !== null && filled($column['target_column'] ?? null)) {
                $plan[] = ['target' => (string) $column['target_column'], 'type' => $source->type ?? ColumnType::String];
            }
        }

        if ($plan === []) {
            throw ValidationException::withMessages(['data.columns' => 'Add at least one column.']);
        }

        $errors = app(SchemaManager::class)->validatePlan($table, $plan);

        if ($errors !== []) {
            throw ValidationException::withMessages(['data.columns' => $errors]);
        }
    }

    protected function applySchemaAndBackfill(Mapping $mapping): void
    {
        try {
            $changes = app(SchemaManager::class)->apply($mapping->refresh());
        } catch (SchemaException $e) {
            Notification::make()->title('The database was not changed')->body(implode(' ', $e->errors))->danger()->persistent()->send();

            throw new Halt;
        }

        $messages = app(Backfiller::class)->afterConfigChange($mapping->sourceTable->refresh(), $this->trackedBefore);

        Notification::make()
            ->title('Mapping saved')
            ->body(implode(' ', [...$changes, ...$messages]) ?: 'No database changes were needed.')
            ->success()
            ->persistent()
            ->send();
    }
}
