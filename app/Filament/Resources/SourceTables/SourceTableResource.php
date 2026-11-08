<?php

namespace App\Filament\Resources\SourceTables;

use App\Filament\Resources\SourceTables\Pages\CreateSourceTable;
use App\Filament\Resources\SourceTables\Pages\EditSourceTable;
use App\Filament\Resources\SourceTables\Pages\ListSourceTables;
use App\Filament\Resources\SourceTables\RelationManagers\ColumnsRelationManager;
use App\Filament\Resources\SourceTables\Schemas\SourceTableForm;
use App\Filament\Resources\SourceTables\Tables\SourceTablesTable;
use App\Models\SourceTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SourceTableResource extends Resource
{
    protected static ?string $model = SourceTable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Sources';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'sheet table';

    protected static ?string $navigationLabel = 'Sheet tables';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return SourceTableForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SourceTablesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ColumnsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSourceTables::route('/'),
            'create' => CreateSourceTable::route('/create'),
            'edit' => EditSourceTable::route('/{record}/edit'),
        ];
    }
}
