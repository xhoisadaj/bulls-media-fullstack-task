<?php

namespace App\Console\Commands;

use App\Enums\TriggerType;
use App\Jobs\SyncSpreadsheetJob;
use App\Models\Spreadsheet;
use App\Sync\SyncRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sheets:sync
    {--file= : Sync only this spreadsheet (ID in this app or Google ID)}
    {--now : Run in this process instead of queueing one job per file}
    {--scheduled : Mark the runs as triggered by the daily schedule}
    {--allow-mass-removal : Apply even when more than half of a table\'s rows would be removed}')]
#[Description('Sync the tracked Google spreadsheets into the database')]
class SyncSheetsCommand extends Command
{
    public function handle(SyncRunner $runner): int
    {
        $trigger = $this->option('scheduled') ? TriggerType::Schedule : TriggerType::Manual;

        $spreadsheets = Spreadsheet::query()
            ->where('is_active', true)
            ->when($this->option('file'), fn ($q, $file) => $q->where(fn ($q) => $q->where('id', $file)->orWhere('google_id', $file)))
            ->get();

        if ($spreadsheets->isEmpty()) {
            $this->components->warn('No active spreadsheets to sync.');

            return self::SUCCESS;
        }

        foreach ($spreadsheets as $spreadsheet) {
            if ($this->option('now')) {
                $run = $runner->run($spreadsheet, $trigger, (bool) $this->option('allow-mass-removal'));
                $this->components->twoColumnDetail($spreadsheet->name, $run->status->value.' '.json_encode($run->counts));

                foreach ($run->errors ?? [] as $error) {
                    $this->components->bulletList([$error['message']]);
                }
            } else {
                SyncSpreadsheetJob::dispatch($spreadsheet, $trigger, (bool) $this->option('allow-mass-removal'));
                $this->components->info('Queued sync for "'.$spreadsheet->name.'".');
            }
        }

        return self::SUCCESS;
    }
}
