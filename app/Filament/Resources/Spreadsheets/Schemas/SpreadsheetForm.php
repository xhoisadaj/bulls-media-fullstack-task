<?php

namespace App\Filament\Resources\Spreadsheets\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SpreadsheetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('google_id')->label('Google spreadsheet ID')->disabled(),
                Toggle::make('is_active')->label('Include in the daily sync')->default(true),
            ]);
    }
}
