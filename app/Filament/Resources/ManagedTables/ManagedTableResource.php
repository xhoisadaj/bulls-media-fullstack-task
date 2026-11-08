<?php

namespace App\Filament\Resources\ManagedTables;

use App\Enums\ColumnType;
use App\Filament\Resources\ManagedTables\Pages\BrowseManagedTable;
use App\Filament\Resources\ManagedTables\Pages\ListManagedTables;
use App\Filament\Resources\SourceTables\Schemas\SourceTableForm;
use App\Models\ManagedTable;
use App\Sync\SchemaException;
use App\Sync\SchemaManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
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
                TextColumn::make('can_delete')->label('Delete')
                    ->state(fn (ManagedTable $record): string => app(SchemaManager::class)->dropTableProblems($record) === [] ? 'Empty, can be deleted' : 'In use')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'In use' ? 'gray' : 'warning')
                    ->tooltip(fn (ManagedTable $record): ?string => implode(' ', app(SchemaManager::class)->dropTableProblems($record)) ?: null),
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
                ActionGroup::make([
                    self::addColumnAction(),
                    self::removeColumnsAction(),
                    self::deleteTableAction(),
                ]),
            ])
            ->toolbarActions([
                BulkAction::make('deleteEmptyTables')
                    ->label('Delete selected empty tables')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->modalHeading('Delete the selected empty tables')
                    ->modalDescription('Only tables without rows and without mappings are deleted. Any other selected table is skipped and listed.')
                    ->schema([TextInput::make('current_password')->label('Your password')->password()->required()->currentPassword()])
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => self::deleteEmptyTables($records)),
            ])
            ->emptyStateHeading('No target tables yet')
            ->emptyStateDescription('Target tables are created when you save a mapping.');
    }

    /**
     * Adds an empty column with any name and type, e.g. one that a second file will fill later.
     */
    private static function addColumnAction(): Action
    {
        return Action::make('addColumn')
            ->label('Add column')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->visible(fn (ManagedTable $record): bool => Schema::hasTable($record->name))
            ->modalHeading(fn (ManagedTable $record): string => 'Add a column to '.$record->name)
            ->modalDescription('The column starts empty. Map a sheet column to it later to fill it.')
            ->schema([
                TextInput::make('name')->label('Column name')->required()->placeholder('e.g. discount'),
                Select::make('type')->options(ColumnType::class)->default(ColumnType::String)->required(),
            ])
            ->action(function (ManagedTable $record, array $data): void {
                try {
                    app(SchemaManager::class)->addEmptyColumn($record, trim($data['name']), ColumnType::from(SourceTableForm::enumValue($data['type'])), auth()->user());
                } catch (SchemaException $e) {
                    Notification::make()->title('The column was not added')->body(implode(' ', $e->errors))->danger()->send();

                    return;
                }

                Notification::make()->title('Added '.$record->name.'.'.trim($data['name']))->success()->send();
            });
    }

    /**
     * Removes columns that are empty and unmapped. Columns with data or a mapping are listed but can't be ticked.
     */
    private static function removeColumnsAction(): Action
    {
        return Action::make('removeColumns')
            ->label('Remove columns')
            ->icon(Heroicon::OutlinedMinusCircle)
            ->color('danger')
            ->visible(fn (ManagedTable $record): bool => Schema::hasTable($record->name))
            ->modalHeading(fn (ManagedTable $record): string => 'Remove columns from '.$record->name)
            ->modalDescription('Only empty columns that no mapping uses can be removed, so no data is lost. The sync\'s own columns (id, _record_id, …) are never listed.')
            ->schema(fn (ManagedTable $record): array => [
                CheckboxList::make('columns')
                    ->label('Columns')
                    ->options(fn (): array => collect(app(SchemaManager::class)->columnUsage($record))->mapWithKeys(fn ($u, $name) => [$name => $name.' ('.$u['type'].')'])->all())
                    ->descriptions(fn (): array => collect(app(SchemaManager::class)->columnUsage($record))->map(fn ($u) => $u['problem'] ?? 'empty, not mapped')->all())
                    ->disableOptionWhen(fn (string $value): bool => (app(SchemaManager::class)->columnUsage($record)[$value]['problem'] ?? null) !== null)
                    ->required(),
                TextInput::make('current_password')->label('Your password')->password()->required()->currentPassword(),
            ])
            ->action(function (ManagedTable $record, array $data): void {
                try {
                    app(SchemaManager::class)->dropColumns($record, array_values($data['columns']), auth()->user());
                } catch (SchemaException $e) {
                    Notification::make()->title('Nothing was removed')->body(implode(' ', $e->errors))->danger()->send();

                    return;
                }

                Notification::make()->title('Removed from '.$record->name.': '.implode(', ', $data['columns']))->success()->send();
            });
    }

    /**
     * Deletes the MySQL table, only when it has no rows and no mapping writes into it.
     */
    private static function deleteTableAction(): Action
    {
        return Action::make('deleteTable')
            ->label('Delete table')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->disabled(fn (ManagedTable $record): bool => app(SchemaManager::class)->dropTableProblems($record) !== [])
            ->tooltip(fn (ManagedTable $record): ?string => implode(' ', app(SchemaManager::class)->dropTableProblems($record)) ?: null)
            ->modalHeading(fn (ManagedTable $record): string => 'Delete table '.$record->name)
            ->modalDescription('The table is empty and no mapping uses it. It is dropped from the database together with its schema history.')
            ->schema(fn (ManagedTable $record): array => [
                TextInput::make('confirm_name')->label('Type the table name to confirm')->required()->in([$record->name])
                    ->validationMessages(['in' => 'Type "'.$record->name.'" exactly.']),
                TextInput::make('current_password')->label('Your password')->password()->required()->currentPassword(),
            ])
            ->action(function (ManagedTable $record): void {
                try {
                    app(SchemaManager::class)->dropTable($record, auth()->user());
                } catch (SchemaException $e) {
                    Notification::make()->title('The table was not deleted')->body(implode(' ', $e->errors))->danger()->send();

                    return;
                }

                Notification::make()->title('Table '.$record->name.' deleted')->success()->send();
            });
    }

    /**
     * @param  Collection<int, ManagedTable>  $tables
     */
    private static function deleteEmptyTables(Collection $tables): void
    {
        $deleted = [];
        $skipped = [];

        foreach ($tables as $table) {
            try {
                app(SchemaManager::class)->dropTable($table, auth()->user());
                $deleted[] = $table->name;
            } catch (SchemaException $e) {
                $skipped[] = $table->name.': '.implode(' ', $e->errors);
            }
        }

        Notification::make()
            ->title($deleted === [] ? 'No table was deleted' : 'Deleted: '.implode(', ', $deleted))
            ->body($skipped === [] ? null : 'Skipped (not empty or still mapped): '.implode(' · ', $skipped))
            ->color($skipped === [] ? 'success' : 'warning')
            ->send();
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
