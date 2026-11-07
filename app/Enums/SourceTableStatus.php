<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SourceTableStatus: string implements HasColor, HasLabel
{
    case Ok = 'ok';
    case Broken = 'broken';

    public function getLabel(): string
    {
        return $this === self::Ok ? 'OK' : 'Broken';
    }

    public function getColor(): string
    {
        return $this === self::Ok ? 'success' : 'danger';
    }
}
