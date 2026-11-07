<?php

namespace App\Sync\Data;

final class ChangeSetOutcome
{
    /** @var array<string, int> */
    public array $counts = [
        'unchanged' => 0, 'new' => 0, 'modified' => 0, 'removed' => 0, 'restored' => 0,
        'pending_created' => 0, 'auto_applied' => 0, 'superseded' => 0, 'ignored_rejected' => 0,
    ];

    public bool $blocked = false;

    public ?string $blockedReason = null;
}
