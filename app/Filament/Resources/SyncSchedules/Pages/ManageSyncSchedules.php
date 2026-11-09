<?php

namespace App\Filament\Resources\SyncSchedules\Pages;

use App\Enums\TriggerType;
use App\Filament\Resources\SyncSchedules\SyncScheduleResource;
use App\Jobs\SyncSpreadsheetJob;
use App\Models\Spreadsheet;
use App\Models\SyncSchedule;
use App\Support\Access;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageSyncSchedules extends ManageRecords
{
    protected static string $resource = SyncScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            self::syncAllAction(),
            self::timezoneAction(),
            CreateAction::make()->label('Add schedule'),
        ];
    }

    public static function runNow(SyncSchedule $schedule): void
    {
        $spreadsheets = $schedule->spreadsheets();
        $spreadsheets->each(fn (Spreadsheet $s) => SyncSpreadsheetJob::start($s, TriggerType::Manual));

        Notification::make()->title('Sync started for '.$spreadsheets->count().' file(s)')->success()->send();
    }

    /**
     * Syncs every active file now.
     */
    public static function syncAllAction(): Action
    {
        return Action::make('syncAllFiles')
            ->label('Sync all files')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (): bool => Access::canEdit())
            ->authorize(fn (): bool => Access::canEdit())
            ->action(function () {
                $spreadsheets = Access::spreadsheets()->where('is_active', true)->get();
                $spreadsheets->each(fn (Spreadsheet $s) => SyncSpreadsheetJob::start($s, TriggerType::Manual));

                Notification::make()
                    ->title($spreadsheets->isEmpty() ? 'No active files to sync' : 'Syncing '.$spreadsheets->count().' file(s)…')
                    ->body($spreadsheets->isEmpty() ? null : 'The results appear in Sync history in a few seconds.')
                    ->status($spreadsheets->isEmpty() ? 'warning' : 'success')
                    ->send();
            });
    }

    /**
     * The signed-in user's timezone: dates are shown in it and new schedules default to it.
     */
    public static function timezoneAction(): Action
    {
        return Action::make('timezone')
            ->label(fn () => 'Your timezone: '.(auth()->user()?->timezone ?? 'UTC'))
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->color('gray')
            ->modalHeading('Your timezone')
            ->modalDescription('Dates and times in the app are shown in this timezone, and new schedules use it by default.')
            ->fillForm(fn () => ['timezone' => auth()->user()?->timezone ?? 'UTC'])
            ->schema([
                Select::make('timezone')->options(SyncScheduleResource::timezones())->searchable()->required(),
            ])
            ->action(function (array $data, $livewire) {
                auth()->user()->update(['timezone' => $data['timezone']]);
                Notification::make()->title('Timezone set to '.$data['timezone'])->success()->send();
                $livewire->redirect(request()->header('Referer') ?: SyncScheduleResource::getUrl(), navigate: true);
            });
    }
}
