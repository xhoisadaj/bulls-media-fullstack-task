<?php

namespace App\Filament\Resources\ManagedTables;

use App\Filament\Resources\ManagedTables\Pages\BrowseManagedTable;
use App\Filament\Resources\ManagedTables\Pages\ListManagedTables;
use App\Models\ManagedTable;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use UnitEnum;

/**
 * Database tables created and owned by the sync, with their data and schema history.
 */
class ManagedTableResource extends Resource
{
    protected static ?string $model = ManagedTable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Activity';

    protected static ?string $navigationLabel = 'Target tables';

    protected static ?string $modelLabel = 'target table';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->weight('semibold')->fontFamily('mono'),
                TextColumn::make('rows')->label('Rows (active)')
                    ->state(fn (ManagedTable $record) => Schema::hasTable($record->name) ? DB::table($record->name)->whereNull('_removed_at')->count() : 'not created'),
                TextColumn::make('mappings_count')->counts('mappings')->label('Mappings'),
                TextColumn::make('columns')->label('Columns')->wrap()
                    ->state(fn (ManagedTable $record) => Schema::hasTable($record->name) ? implode(', ', Schema::getColumnListing($record->name)) : '—'),
            ])
            ->recordActions([
                Action::make('browse')
                    ->label('Browse data')
                    ->icon(Heroicon::OutlinedTableCells)
                    ->url(fn (ManagedTable $record) => self::getUrl('browse', ['record' => $record])),
                Action::make('history')
                    ->label('Schema history')
                    ->color('gray')
                    ->modalHeading(fn (ManagedTable $record) => 'Schema history of '.$record->name)
                    ->modalContent(fn (ManagedTable $record) => view('filament.schema-history', ['history' => $record->history()->with('user')->latest('id')->get()]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->emptyStateHeading('No target tables yet')
            ->emptyStateDescription('Target tables are created when you save a mapping.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListManagedTables::route('/'),
            'browse' => BrowseManagedTable::route('/{record}/browse'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
