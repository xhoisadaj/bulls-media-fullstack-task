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
            self::SystemId => 'System ID (written to the sheet)',
            self::Content => 'Content only (no key)',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Key => 'One or more columns that are unique per row (order_id, email). Detects new, modified, removed and restored rows.',
            self::SystemId => 'For sheets without a unique column: the app writes a short ID into a new _sync_id column of the sheet, so an edited row is still recognised. Needs Google connected with write access.',
            self::Content => 'The row\'s values are its identity. An edited row shows as removed + new, because nothing proves it is the same row.',
        };
    }

    public function usesKey(): bool
    {
        return $this !== self::Content;
    }
}
