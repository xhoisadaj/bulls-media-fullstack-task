<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EndRule: string implements HasLabel
{
    case FirstEmptyRow = 'first_empty_row';
    case EndOfSheet = 'end_of_sheet';
    case FixedRow = 'fixed_row';
    case BeforeNextHeader = 'before_next_header';

    public function getLabel(): string
    {
        return match ($this) {
            self::FirstEmptyRow => 'Stop at the first empty row',
            self::EndOfSheet => 'Read to the end of the sheet (skip empty rows)',
            self::FixedRow => 'Stop at a fixed row',
            self::BeforeNextHeader => 'Stop before the next table\'s header row',
        };
    }
}
