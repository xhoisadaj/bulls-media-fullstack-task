<?php

namespace App\Filament\Support;

use App\Enums\TriggerType;
use App\Jobs\SyncSpreadsheetJob;
use App\Models\Spreadsheet;
use App\Support\Access;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * "Sync now" and "Sync anyway" actions shared by the spreadsheet screens.
 */
final class SyncActions
{
    public static function syncNow(string $name = 'syncNow'): Action
    {
        return Action::make($name)
            ->label('Sync now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (): bool => Access::canEdit())
            ->authorize(fn (): bool => Access::canEdit())
            ->action(function (Spreadsheet $record) {
                SyncSpreadsheetJob::start($record, TriggerType::Manual);

                Notification::make()
                    ->title('Sync started for "'.$record->name.'"')
                    ->body('Results appear in Sync history and the review inbox in a few seconds.')
                    ->success()
                    ->send();
            });
    }

    public static function syncAnyway(): Action
    {
        return Action::make('syncAnyway')
            ->label('Sync anyway (allow mass removal)')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('danger')
            ->visible(fn (): bool => Access::canEdit())
            ->authorize(fn (): bool => Access::canEdit())
            ->requiresConfirmation()
            ->modalDescription('Use this only when you really removed more than half of a table\'s rows. Removals still wait for approval in the review inbox.')
            ->action(function (Spreadsheet $record) {
                SyncSpreadsheetJob::start($record, TriggerType::Manual, allowMassRemoval: true);
                Notification::make()->title('Sync started (mass removal allowed)')->warning()->send();
            });
    }
}
