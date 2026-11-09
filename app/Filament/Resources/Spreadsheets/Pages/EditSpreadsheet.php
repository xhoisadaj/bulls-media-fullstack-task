<?php

namespace App\Filament\Resources\Spreadsheets\Pages;

use App\Filament\Resources\Spreadsheets\SpreadsheetResource;
use App\Filament\Support\SyncActions;
use App\Google\Exceptions\GoogleAccessException;
use App\Google\SpreadsheetImporter;
use App\Support\Access;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditSpreadsheet extends EditRecord
{
    protected static string $resource = SpreadsheetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SyncActions::syncNow(),
            Action::make('refreshTabs')
                ->label('Refresh tabs')
                ->visible(fn (): bool => Access::canEdit())
                ->authorize(fn (): bool => Access::canEdit())
                ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                ->color('gray')
                ->action(function () {
                    try {
                        app(SpreadsheetImporter::class)->refreshTabs($this->record);
                        Notification::make()->title('Tabs refreshed')->success()->send();
                    } catch (GoogleAccessException $e) {
                        Notification::make()->title('Could not read the tabs')->body($e->getMessage())->danger()->send();
                    }
                }),
            ActionGroup::make([
                Action::make('open')
                    ->label('Open in Google Sheets')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn () => $this->record->url(), shouldOpenInNewTab: true),
                SyncActions::syncAnyway(),
            ]),
            DeleteAction::make()
                ->label('Delete spreadsheet')
                ->button()
                ->modalDescription('The spreadsheet, its tab and table settings, and its stored records are removed. Target tables keep their data.'),
        ];
    }
}
