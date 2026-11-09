<?php

namespace App\Filament\Resources\SyncRuns\Pages;

use App\Filament\Resources\SyncRuns\SyncRunResource;
use App\Filament\Resources\SyncSchedules\Pages\ManageSyncSchedules;
use App\Filament\Resources\SyncSchedules\SyncScheduleResource;
use App\Models\SyncSchedule;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSyncRuns extends ListRecords
{
    protected static string $resource = SyncRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ManageSyncSchedules::syncAllAction(),
            CreateAction::make('addSchedule')
                ->label('Add schedule')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->model(SyncSchedule::class)
                ->schema(SyncScheduleResource::formComponents())
                ->modalHeading('Add a sync schedule')
                ->successNotificationTitle('Schedule saved'),
            Action::make('schedules')
                ->label(fn () => 'Schedules ('.SyncSchedule::query()->active()->count().' active)')
                ->color('gray')
                ->url(SyncScheduleResource::getUrl()),
            ManageSyncSchedules::timezoneAction(),
        ];
    }
}
