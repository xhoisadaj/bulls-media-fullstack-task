<?php

namespace App\Jobs;

use App\Enums\TriggerType;
use App\Models\Spreadsheet;
use App\Sync\SyncRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

    public function handle(SyncRunner $runner): void
    {
        $runner->run($this->spreadsheet, $this->trigger, $this->allowMassRemoval);
    }
}
