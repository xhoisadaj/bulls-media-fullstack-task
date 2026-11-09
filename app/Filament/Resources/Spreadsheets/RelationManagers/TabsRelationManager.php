<?php

namespace App\Filament\Resources\Spreadsheets\RelationManagers;

use App\Filament\Resources\SourceTables\SourceTableResource;
use App\Models\Tab;
use App\Support\Access;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class TabsRelationManager extends RelationManager
{
    protected static string $relationship = 'tabs';

    protected static ?string $title = 'Tabs';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->description('Enable the tabs to sync, then set up the table(s) inside each tab.')
            ->columns([
                TextColumn::make('name')->weight('semibold'),
                ToggleColumn::make('is_active')->label('Sync this tab')->disabled(fn (): bool => ! Access::canEdit()),
                IconColumn::make('is_present')->label('In sheet')->boolean(),
                TextColumn::make('source_tables_count')->counts('sourceTables')->label('Tables'),
                TextColumn::make('google_tab_id')->label('Tab ID')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('openTable')
                    ->label('Open sheet table')
                    ->icon(Heroicon::OutlinedTableCells)
                    ->visible(fn (Tab $record): bool => $record->sourceTables()->count() === 1)
                    ->url(fn (Tab $record) => SourceTableResource::getUrl('edit', ['record' => $record->sourceTables()->first()])),
                Action::make('viewTables')
                    ->label(fn (Tab $record): string => 'Open sheet tables ('.$record->sourceTables()->count().')')
                    ->icon(Heroicon::OutlinedTableCells)
                    ->visible(fn (Tab $record): bool => $record->sourceTables()->count() > 1)
                    ->url(fn (Tab $record) => SourceTableResource::getUrl('index', ['filters' => ['tab_id' => ['value' => $record->id]]])),
                Action::make('setUpTable')
                    ->label(fn (Tab $record): string => $record->sourceTables()->exists() ? 'Add another table' : 'Add a sheet table')
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->color(fn (Tab $record): string => $record->sourceTables()->exists() ? 'gray' : 'primary')
                    ->visible(fn (Tab $record): bool => Access::canEdit() && $record->is_present)
                    ->tooltip(fn (Tab $record): ?string => $record->sourceTables()->exists() ? 'Only for a second table in the same tab (e.g. a table stacked below the first one).' : null)
                    ->url(fn (Tab $record) => SourceTableResource::getUrl('create', ['tab' => $record->id])),
            ])
            ->paginated(false);
    }
}
