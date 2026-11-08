<?php

namespace App\Filament\Resources\Spreadsheets\Tables;

use App\Filament\Support\SyncActions;
use App\Models\Spreadsheet;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class SpreadsheetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('semibold'),
                TextColumn::make('google_id')->label('Google ID')->limit(18)->copyable()->toggleable(),
                TextColumn::make('active_tabs_count')
                    ->counts(['tabs as active_tabs_count' => fn ($q) => $q->where('is_active', true)])
                    ->label('Active tabs'),
                TextColumn::make('last_sync_status')->label('Last sync')->badge()->placeholder('Never'),
                TextColumn::make('last_synced_at')->label('Synced')->since()->sortable()->placeholder('—'),
                ToggleColumn::make('is_active')->label('Daily sync'),
            ])
            ->defaultSort('name')
            ->recordActions([
                SyncActions::syncNow(),
                EditAction::make()->label('Tabs & settings'),
                ActionGroup::make([
                    Action::make('open')
                        ->label('Open in Google Sheets')
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url(fn (Spreadsheet $record) => $record->url(), shouldOpenInNewTab: true),
                    SyncActions::syncAnyway(),
                ]),
                DeleteAction::make()
                    ->modalDescription('The spreadsheet, its tab and table settings, and its stored records are removed. Target tables keep their data.'),
            ])
            ->emptyStateHeading('No spreadsheets yet')
            ->emptyStateDescription('Add a sheet by its URL, or fetch the list from your Google account.');
    }
}
