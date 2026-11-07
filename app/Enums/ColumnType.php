<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ColumnType: string implements HasLabel
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Date = 'date';
    case DateTime = 'datetime';
    case Boolean = 'boolean';
    case ImageUrl = 'image_url';

    public function getLabel(): string
    {
        return match ($this) {
            self::String => 'String (up to 255 chars)',
            self::Text => 'Text (long)',
            self::Integer => 'Integer',
            self::Decimal => 'Decimal',
            self::Date => 'Date',
            self::DateTime => 'Date & time',
            self::Boolean => 'Boolean',
            self::ImageUrl => 'Image URL (=IMAGE formula)',
        };
    }

    /**
     * Whether the value is read from the displayed text instead of the raw cell value.
     * Text keeps leading zeros and exactly what people see in the sheet.
     */
    public function readsDisplayedValue(): bool
    {
        return in_array($this, [self::String, self::Text], true);
    }
}
