<?php

namespace App\Filament\Resources\SourceTables\Tables;

use App\Enums\SourceTableStatus;
use App\Filament\Resources\Mappings\MappingResource;
use App\Filament\Support\ExportActions;
use App\Models\SourceTable;
use App\Models\Tab;
use App\Sync\TableSetup;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SourceTablesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tab.spreadsheet'))
            ->columns([
                TextColumn::make('name')->weight('semibold')->searchable()
                    ->description(fn (SourceTable $record) => $record->tab->spreadsheet->name.' / '.$record->tab->name),
                TextColumn::make('position')->label('Headers at')
                    ->state(fn (SourceTable $record) => TableSetup::position($record->header_row, $record->start_column)),
                TextColumn::make('identity_mode')->label('Identity')->badge()->color('gray'),
                TextColumn::make('apply_mode')->label('Mode')->badge()->color('gray'),
                TextColumn::make('status')->badge()->tooltip(fn (SourceTable $record) => $record->status_reason),
                TextColumn::make('records_count')->counts(['records' => fn ($q) => $q->whereNull('removed_at')])->label('Rows stored'),
                TextColumn::make('mappings_count')->counts('mappings')->label('Mappings'),
            ])
            ->filters([
                SelectFilter::make('tab_id')->label('Tab')
                    ->options(fn () => Tab::query()->with('spreadsheet')->get()->mapWithKeys(fn (Tab $t) => [$t->id => $t->spreadsheet->name.' / '.$t->name])),
                SelectFilter::make('status')->options(SourceTableStatus::class),
            ])
            ->recordActions([
                Action::make('editColumns')
                    ->label('Add or change columns')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->visible(fn (SourceTable $record): bool => $record->mappings()->exists())
                    ->url(fn (SourceTable $record) => MappingResource::getUrl('edit', ['record' => $record->mappings()->orderBy('id')->first()])),
                Action::make('addMapping')
                    ->label(fn (SourceTable $record): string => $record->mappings()->exists() ? 'Save into another table' : 'Save into a table')
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->color('gray')
                    ->url(fn (SourceTable $record) => MappingResource::getUrl('create', ['source_table' => $record->id])),
                ExportActions::group(
                    fn (SourceTable $record) => [$record->mappings()->orderBy('id')->firstOrFail()->managedTable, $record->id],
                    fn (SourceTable $record): bool => $record->mappings()->exists(),
                )->tooltip('This sheet table\'s rows, from the database table it saves into'),
                EditAction::make(),
                DeleteAction::make()->modalDescription('Its stored records and pending changes are removed. Target tables keep their data.'),
            ])
            ->emptyStateHeading('No tables set up yet')
            ->emptyStateDescription('Open a spreadsheet, enable a tab, and click "Add a sheet table".');
    }
}
