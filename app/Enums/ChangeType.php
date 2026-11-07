<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ChangeType: string implements HasColor, HasLabel
{
    case New = 'new';
    case Modified = 'modified';
    case Removed = 'removed';
    case Restored = 'restored';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New, self::Restored => 'success',
            self::Modified => 'warning',
            self::Removed => 'danger',
        };
    }

    /**
     * Removals always need a human, even in auto mode.
     */
    public function canAutoApply(): bool
    {
        return $this !== self::Removed;
    }
}
