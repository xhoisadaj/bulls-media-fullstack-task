<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * How a sheet row is recognised as "the same row" between syncs.
 */
enum IdentityMode: string implements HasDescription, HasLabel
{
    case Key = 'key';
    case SystemId = 'system_id';
    case Content = 'content';

    public function getLabel(): string
    {
        return match ($this) {
            self::Key => 'Key columns',
            self::SystemId => 'System ID (kept by the app)',
            self::Content => 'Content only (no key)',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Key => 'One or more columns that are unique per row (order_id, email). Detects new, modified, removed and restored rows.',
            self::SystemId => 'Recommended without a unique column. Each row gets an ID in our database; nothing is written to the sheet. An edited row is recognised when it stays in its place (same rows above and below); those changes always wait for review.',
            self::Content => 'The row\'s values are its identity, nothing else. An edited row always shows as removed + new.',
        };
    }

    public function usesKey(): bool
    {
        return $this !== self::Content;
    }
}
