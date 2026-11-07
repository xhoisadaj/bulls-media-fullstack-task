<?php

namespace App\Filament\Resources\Spreadsheets;

use App\Filament\Resources\Spreadsheets\Pages\EditSpreadsheet;
use App\Filament\Resources\Spreadsheets\Pages\ListSpreadsheets;
use App\Filament\Resources\Spreadsheets\RelationManagers\TabsRelationManager;
use App\Filament\Resources\Spreadsheets\Schemas\SpreadsheetForm;
use App\Filament\Resources\Spreadsheets\Tables\SpreadsheetsTable;
use App\Models\Spreadsheet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SpreadsheetResource extends Resource
{
    protected static ?string $model = Spreadsheet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Sources';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return SpreadsheetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SpreadsheetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TabsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSpreadsheets::route('/'),
            'edit' => EditSpreadsheet::route('/{record}/edit'),
        ];
    }
}
