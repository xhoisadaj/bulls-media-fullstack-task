<?php

namespace App\Filament\Resources\Mappings\Pages;

use App\Enums\ColumnType;
use App\Filament\Resources\SourceTables\Schemas\SourceTableForm;
use App\Models\Mapping;
use App\Models\SourceColumn;
use App\Sync\Backfiller;
use App\Sync\SchemaException;
use App\Sync\SchemaManager;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
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
                $type = ColumnType::tryFrom(SourceTableForm::enumValue($column['target_type'] ?? null) ?? '') ?? $source->type ?? ColumnType::String;
                $plan[] = ['target' => (string) $column['target_column'], 'type' => $type];
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

    /**
     * Runs once the mapping is committed: the schema change (ALTER/CREATE TABLE commits implicitly
     * in MySQL, so it can't be inside the form's transaction), then the backfill in its own transaction.
     * The plan was validated before saving, so the schema change is not expected to fail here.
     */
    protected function applySchemaAndBackfill(Mapping $mapping): void
    {
        $trackedBefore = $this->trackedBefore;

        DB::afterCommit(function () use ($mapping, $trackedBefore): void {
            try {
                $changes = app(SchemaManager::class)->apply($mapping->refresh());
            } catch (SchemaException $e) {
                Notification::make()->title('The mapping was saved, but the database was not changed')->body(implode(' ', $e->errors))->danger()->persistent()->send();

                return;
            }

            $messages = app(Backfiller::class)->afterConfigChange($mapping->sourceTable->refresh(), $trackedBefore);

            Notification::make()
                ->title('Mapping saved')
                ->body(implode(' ', [...$changes, ...$messages]) ?: 'No database changes were needed.')
                ->success()
                ->persistent()
                ->send();
        });
    }
}
