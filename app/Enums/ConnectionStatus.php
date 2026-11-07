<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ConnectionStatus: string implements HasColor, HasLabel
{
    case NotSet = 'not_set';
    case Connected = 'connected';
    case Valid = 'valid';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Invalid = 'invalid';
    case Unreadable = 'unreadable';

    public function getLabel(): string
    {
        return match ($this) {
            self::NotSet => 'Not set',
            self::Unreadable => 'Can\'t be read, re-enter',
            default => ucfirst($this->value),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Connected, self::Valid => 'success',
            self::NotSet => 'gray',
            self::Expired => 'warning',
            default => 'danger',
        };
    }

    public function isUsable(): bool
    {
        return in_array($this, [self::Connected, self::Valid], true);
    }
}
