<?php

namespace App\Jobs;

use App\Enums\TriggerType;
use App\Models\Spreadsheet;
use App\Sync\SyncRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Syncs one spreadsheet. One job per file, so a failing file never stops the others.
 */
class SyncSpreadsheetJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly Spreadsheet $spreadsheet,
        public readonly TriggerType $trigger = TriggerType::Manual,
        public readonly bool $allowMassRemoval = false,
    ) {}

    /**
     * Queues a sync and marks the spreadsheet as syncing until the job has finished, so the
     * screens can show a "syncing" state and refresh by themselves.
     */
    public static function start(Spreadsheet $spreadsheet, TriggerType $trigger = TriggerType::Manual, bool $allowMassRemoval = false): void
    {
        Cache::put($spreadsheet->syncingCacheKey(), true, now()->addMinutes(15));
        static::dispatch($spreadsheet, $trigger, $allowMassRemoval);
    }

    public function handle(SyncRunner $runner): void
    {
        try {
            $runner->run($this->spreadsheet, $this->trigger, $this->allowMassRemoval);
        } finally {
            Cache::forget($this->spreadsheet->syncingCacheKey());
        }
    }
}
