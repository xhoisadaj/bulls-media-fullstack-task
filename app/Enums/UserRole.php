<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasColor, HasDescription, HasLabel
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Admin => 'Everything: users, Google connection, schedules, all files, deleting and changing database tables.',
            self::Editor => 'Works on the files shared with them: sheet tables, column mappings, sync, approving changes, exports.',
            self::Viewer => 'Only looks at the data of the files shared with them, and exports it.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Editor => 'warning',
            self::Viewer => 'gray',
        };
    }
}
