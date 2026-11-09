<?php

namespace App\Console\Commands;

use App\Enums\TriggerType;
use App\Jobs\SyncSpreadsheetJob;
use App\Models\SyncSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sheets:run-schedules')]
#[Description('Start the syncs whose schedule is due this minute (run every minute by the scheduler)')]
class RunSyncSchedulesCommand extends Command
{
    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $started = [];

        foreach (SyncSchedule::query()->active()->get() as $schedule) {
            if (! $schedule->isDue($now)) {
                continue;
            }

            $schedule->forceFill(['last_triggered_at' => $now])->save();

            foreach ($schedule->spreadsheets() as $spreadsheet) {
                // Two schedules due at the same minute sync a file once.
                if (! isset($started[$spreadsheet->id])) {
                    SyncSpreadsheetJob::start($spreadsheet, TriggerType::Schedule);
                    $started[$spreadsheet->id] = true;
                }
            }
        }

        $this->components->info(count($started).' sync(s) started.');

        return self::SUCCESS;
    }
}
