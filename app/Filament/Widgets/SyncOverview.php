<?php

namespace App\Filament\Widgets;

use App\Enums\ConnectionStatus;
use App\Filament\Pages\GoogleConnection;
use App\Filament\Resources\PendingChanges\PendingChangeResource;
use App\Filament\Resources\StructureAlerts\StructureAlertResource;
use App\Filament\Resources\SyncRuns\SyncRunResource;
use App\Models\GoogleSetting;
use App\Models\PendingChange;
use App\Models\Spreadsheet;
use App\Models\StructureAlert;
use App\Models\SyncRun;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SyncOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $settings = GoogleSetting::current();
        $pending = PendingChange::query()->pending()->count();
        $alerts = StructureAlert::query()->open()->count();
        $lastRun = SyncRun::query()->latest('id')->first();

        $connection = match (true) {
            $settings->use_demo_data => ['Demo data', 'warning'],
            $settings->oauth_status === ConnectionStatus::Connected => ['OAuth: '.($settings->oauth_email ?? 'connected'), 'success'],
            $settings->secret('api_key') !== null => ['API key only', 'warning'],
            default => ['Not connected', 'danger'],
        };

        return [
            Stat::make('Waiting for review', $pending)
                ->description($pending > 0 ? 'Open the review inbox' : 'Nothing to review')
                ->color($pending > 0 ? 'warning' : 'success')
                ->url(PendingChangeResource::getUrl()),
            Stat::make('Open alerts', $alerts)
                ->description($alerts > 0 ? 'Sheet layout or access problems' : 'All clear')
                ->color($alerts > 0 ? 'danger' : 'success')
                ->url(StructureAlertResource::getUrl()),
            Stat::make('Tracked spreadsheets', Spreadsheet::query()->where('is_active', true)->count())
                ->description('Daily sync at '.config('sync.daily_at')),
            Stat::make('Last sync', $lastRun?->started_at?->diffForHumans() ?? 'Never')
                ->description($lastRun ? ($lastRun->spreadsheet?->name.': '.$lastRun->status->value) : 'Run "Sync now" on a spreadsheet')
                ->color($lastRun?->status->getColor() ?? 'gray')
                ->url(SyncRunResource::getUrl()),
            Stat::make('Google connection', $connection[0])
                ->color($connection[1])
                ->description('Settings → Google connection')
                ->url(GoogleConnection::getUrl()),
        ];
    }
}
