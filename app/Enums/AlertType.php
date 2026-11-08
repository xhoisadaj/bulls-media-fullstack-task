<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AlertType: string implements HasColor, HasLabel
{
    case ColumnAdded = 'column_added';
    case ColumnMissing = 'column_missing';
    case DuplicateHeader = 'duplicate_header';
    case TableNotFound = 'table_not_found';
    case TableAmbiguous = 'table_ambiguous';
    case HeaderMoved = 'header_moved';
    case TabMissing = 'tab_missing';
    case MassRemoval = 'mass_removal';
    case FileNotAccessible = 'file_not_accessible';
    case FallbackInUse = 'fallback_in_use';

    public function getLabel(): string
    {
        return match ($this) {
            self::ColumnAdded => 'New column in sheet',
            self::ColumnMissing => 'Mapped column missing',
            self::DuplicateHeader => 'Duplicate header',
            self::TableNotFound => 'Table not found',
            self::TableAmbiguous => 'Table ambiguous',
            self::HeaderMoved => 'Header row moved',
            self::TabMissing => 'Tab missing',
            self::MassRemoval => 'Mass removal blocked',
            self::FileNotAccessible => 'File not accessible',
            self::FallbackInUse => 'API key fallback in use',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ColumnAdded, self::HeaderMoved, self::FallbackInUse => 'info',
            default => 'danger',
        };
    }
}
