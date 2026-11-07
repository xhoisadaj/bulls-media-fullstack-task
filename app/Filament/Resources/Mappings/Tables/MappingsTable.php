<?php

namespace App\Filament\Resources\Mappings\Tables;

use App\Models\Mapping;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class MappingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('sourceTable.tab.spreadsheet', 'managedTable', 'columns.sourceColumn'))
            ->columns([
                TextColumn::make('sourceTable.name')->label('From')
                    ->description(fn (Mapping $record) => $record->sourceTable->tab->spreadsheet->name.' / '.$record->sourceTable->tab->name),
                TextColumn::make('managedTable.name')->label('To table')->badge()->color('primary'),
                TextColumn::make('columns_summary')->label('Columns')->wrap()
                    ->state(fn (Mapping $record) => $record->columns->map(fn ($c) => $c->sourceColumn->header.' → '.$c->target_column)->implode(', ')),
                ToggleColumn::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalDescription('Syncing into this table stops. The table and its data stay.'),
            ])
            ->emptyStateHeading('No mappings yet')
            ->emptyStateDescription('A mapping says which sheet columns go to which database table and columns.');
    }
}
