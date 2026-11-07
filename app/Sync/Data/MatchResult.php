<?php

namespace App\Sync\Data;

/**
 * Facts only: which sheet row is which stored record. No decisions.
 */
final class MatchResult
{
    /** @var list<array{record: int, row: SheetRow}> */
    public array $unchanged = [];

    /** @var list<array{record: int, row: SheetRow}> */
    public array $modified = [];

    /** @var list<array{record: int, row: SheetRow}> */
    public array $restored = [];

    /** @var list<SheetRow> */
    public array $new = [];

    /** @var list<int> */
    public array $removed = [];

    /** @var list<array{row: SheetRow, reason: string}> */
    public array $flagged = [];

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'unchanged' => count($this->unchanged),
            'new' => count($this->new),
            'modified' => count($this->modified),
            'removed' => count($this->removed),
            'restored' => count($this->restored),
            'flagged' => count($this->flagged),
        ];
    }
}
