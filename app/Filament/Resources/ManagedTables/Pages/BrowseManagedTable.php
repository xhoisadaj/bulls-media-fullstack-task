<?php

namespace App\Filament\Resources\ManagedTables\Pages;

use App\Filament\Resources\ManagedTables\ManagedTableResource;
use App\Models\ManagedTable;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only view of a target table's rows, straight from MySQL.
 */
class BrowseManagedTable extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ManagedTableResource::class;

    protected string $view = 'filament.browse-table';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        /** @var ManagedTable $record */
        $record = $this->getRecord();

        return 'Table '.$record->name;
    }

    public function table(Table $table): Table
    {
        /** @var ManagedTable $record */
        $record = $this->getRecord();
        $name = $record->name;
        $columns = Schema::hasTable($name) ? Schema::getColumnListing($name) : [];

        return $table
            ->records(function (int $page, int $recordsPerPage, array $filters) use ($name, $columns): LengthAwarePaginator {
                if ($columns === []) {
                    return new LengthAwarePaginator([], 0, $recordsPerPage, $page);
                }

                $removed = $filters['removed']['value'] ?? null;

                $query = DB::table($name)->orderBy('id')
                    ->when($removed === '0' || $removed === null, fn ($q) => $q->whereNull('_removed_at'))
                    ->when($removed === '1', fn ($q) => $q->whereNotNull('_removed_at'));

                $paginator = $query->paginate($recordsPerPage, ['*'], 'page', $page);

                return new LengthAwarePaginator(
                    collect($paginator->items())->map(fn ($row) => (array) $row)->keyBy('id')->all(),
                    $paginator->total(),
                    $recordsPerPage,
                    $page,
                );
            })
            ->columns(array_map(
                fn (string $column) => TextColumn::make($column)->fontFamily(str_starts_with($column, '_') ? 'mono' : null)
                    ->toggleable(isToggledHiddenByDefault: in_array($column, ['_source_table_id', '_synced_at'], true)),
                $columns,
            ))
            ->filters([
                TernaryFilter::make('removed')->label('Removed rows')->placeholder('Hide removed')->trueLabel('Only removed')->falseLabel('Hide removed'),
            ])
            ->paginated([25, 50, 100]);
    }
}
