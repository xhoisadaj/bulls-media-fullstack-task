<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum ApplyMode: string implements HasDescription, HasLabel
{
    case Review = 'review';
    case Auto = 'auto';

    public function getLabel(): string
    {
        return match ($this) {
            self::Review => 'Review',
            self::Auto => 'Auto',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Review => 'Every change waits in the review inbox for approval.',
            self::Auto => 'New, modified and restored rows are applied automatically. Removals still wait for approval.',
        };
    }
}
