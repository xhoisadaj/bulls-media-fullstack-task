<?php

namespace App\Filament\Resources\Mappings;

use App\Filament\Resources\Mappings\Pages\CreateMapping;
use App\Filament\Resources\Mappings\Pages\EditMapping;
use App\Filament\Resources\Mappings\Pages\ListMappings;
use App\Filament\Resources\Mappings\Schemas\MappingForm;
use App\Filament\Resources\Mappings\Tables\MappingsTable;
use App\Models\Mapping;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MappingResource extends Resource
{
    protected static ?string $model = Mapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Sources';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return MappingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MappingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMappings::route('/'),
            'create' => CreateMapping::route('/create'),
            'edit' => EditMapping::route('/{record}/edit'),
        ];
    }
}
