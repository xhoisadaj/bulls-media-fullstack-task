<?php

namespace App\Filament\Resources\Mappings\Pages;

use App\Filament\Resources\Mappings\MappingResource;
use App\Models\ManagedTable;
use App\Models\Mapping;
use App\Models\SourceTable;
use App\Sync\SchemaManager;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateMapping extends CreateRecord
{
    use MappingSaving;

    protected static string $resource = MappingResource::class;

    public function mount(): void
    {
        parent::mount();

        if ($sourceTable = request()->integer('source_table')) {
            $this->form->fill([
                'source_table_id' => $sourceTable,
                'is_active' => true,
                'target_mode' => ManagedTable::query()->exists() ? 'existing' : 'new',
            ]);
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $raw = $this->data;
        $table = ($raw['target_mode'] ?? 'new') === 'new'
            ? trim((string) ($raw['new_table_name'] ?? ''))
            : (string) ManagedTable::query()->find($data['managed_table_id'] ?? null)?->name;

        $this->validatePlan($table, $raw['columns'] ?? []);

        if (($raw['target_mode'] ?? 'new') === 'new') {
            if (app(SchemaManager::class)->isManaged($table)) {
                throw ValidationException::withMessages(['data.new_table_name' => 'Table "'.$table.'" already exists. Choose "An existing table" instead.']);
            }

            $data['managed_table_id'] = ManagedTable::query()->create(['name' => $table, 'created_by' => auth()->id()])->id;
        }

        if (Mapping::query()->where('source_table_id', $data['source_table_id'])->where('managed_table_id', $data['managed_table_id'])->exists()) {
            throw ValidationException::withMessages(['data.managed_table_id' => 'This source table is already mapped to that table. Edit the existing mapping instead.']);
        }

        $this->trackedBefore = SourceTable::query()->findOrFail($data['source_table_id'])->trackedColumns()->pluck('id')->all();

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->applySchemaAndBackfill($this->record);
    }
}
