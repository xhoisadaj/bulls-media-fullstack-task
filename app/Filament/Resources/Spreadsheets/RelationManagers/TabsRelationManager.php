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
                Action::make('setUpTable')
                    ->visible(fn (): bool => Access::canEdit())
                    ->label('Add a sheet table')
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->visible(fn (Tab $record) => $record->is_present)
                    ->url(fn (Tab $record) => SourceTableResource::getUrl('create', ['tab' => $record->id])),
                Action::make('viewTables')
                    ->label('View tables')
                    ->color('gray')
                    ->visible(fn (Tab $record) => $record->sourceTables()->exists())
                    ->url(fn (Tab $record) => SourceTableResource::getUrl('index', ['filters' => ['tab_id' => ['value' => $record->id]]])),
            ])
            ->paginated(false);
    }
}
