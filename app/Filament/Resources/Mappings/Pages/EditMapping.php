<?php

namespace App\Filament\Resources\Mappings\Pages;

use App\Filament\Resources\Mappings\MappingResource;
use App\Google\Exceptions\GoogleAccessException;
use App\Sync\SheetReader;
use App\Sync\TabFetcher;
use App\Sync\TargetWriter;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class EditMapping extends EditRecord
{
    use MappingSaving;

    protected static string $resource = MappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->modalHeading('What this mapping writes (first 10 rows of the sheet)')
                ->modalWidth(Width::SevenExtraLarge)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('filament.mapping-preview', $this->previewData())),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->validatePlan($this->record->managedTable->name, $this->data['columns'] ?? []);
        $this->trackedBefore = $this->record->sourceTable->trackedColumns()->pluck('id')->all();

        return $data;
    }

    protected function afterSave(): void
    {
        $this->applySchemaAndBackfill($this->record);
    }

    /**
     * @return array{columns: list<string>, rows: list<list<mixed>>, error: ?string, problems: list<string>}
     */
    private function previewData(): array
    {
        $mapping = $this->record->load('columns.sourceColumn', 'sourceTable.tab');
        $columns = $mapping->columns->pluck('target_column')->all();

        try {
            $values = app(TabFetcher::class)->fetch($mapping->sourceTable->tab, withFormulas: true);
        } catch (GoogleAccessException $e) {
            return ['columns' => $columns, 'rows' => [], 'error' => $e->getMessage(), 'problems' => []];
        }

        $read = app(SheetReader::class)->read($mapping->sourceTable, $values);

        if (! $read->canSync()) {
            return ['columns' => $columns, 'rows' => [], 'error' => $read->report?->blockingReason() ?? 'The table was not found in the tab.', 'problems' => []];
        }

        $rows = [];

        foreach (array_slice($read->rows, 0, 10) as $row) {
            $rows[] = $mapping->columns->map(fn ($c) => app(TargetWriter::class)->convert($c, $row->values[$c->source_column_id] ?? null))->all();
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'error' => null,
            'problems' => array_map(fn ($p) => 'Row '.$p['row'].', '.$p['column'].': '.$p['error'], array_slice($read->problems, 0, 10)),
        ];
    }
}
