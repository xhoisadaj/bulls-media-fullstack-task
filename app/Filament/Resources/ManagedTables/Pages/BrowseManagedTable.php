<?php

namespace App\Filament\Resources\ManagedTables\Pages;

use App\Filament\Resources\ManagedTables\ManagedTableResource;
use App\Models\ManagedTable;
use App\Models\SourceRecord;
use App\Models\SourceTable;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;

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

    /**
     * True while a sync of any spreadsheet that writes into this table is queued or running.
     */
    public function isSyncing(): bool
    {
        /** @var ManagedTable $record */
        $record = $this->getRecord();

        return $record->mappings()->with('sourceTable.tab.spreadsheet')->get()
            ->contains(fn ($mapping) => $mapping->sourceTable?->tab?->spreadsheet?->isSyncing());
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! $this->isSyncing()) {
            return null;
        }

        return new HtmlString(Blade::render(
            '<span style="display:inline-flex;align-items:center;gap:0.5rem"><x-filament::loading-indicator style="width:1.1rem;height:1.1rem" /> Syncing the data from Google… the rows appear here by themselves.</span>'
        ));
    }

    public function table(Table $table): Table
    {
        /** @var ManagedTable $record */
        $record = $this->getRecord();
        $name = $record->name;
        $columns = Schema::hasTable($name) ? array_values(array_unique(['id', ...Schema::getColumnListing($name)])) : [];

        return $table
            ->records(function (int $page, int $recordsPerPage, array $filters) use ($name, $columns): LengthAwarePaginator {
                if ($columns === []) {
                    return new LengthAwarePaginator([], 0, $recordsPerPage, $page);
                }

                $source = $filters['source']['value'] ?? null;

                // IDs are R-101, R-102, …: order by length first so R-1000 comes after R-999.
                $query = DB::table($name)->orderByRaw('CHAR_LENGTH(id), id')
                    ->when(filled($source), fn ($q) => $q->whereIn('id', SourceRecord::query()
                        ->where('source_table_id', $source)
                        ->pluck('id')
                        ->map(fn (int $id) => SourceRecord::codeFor($id))
                        ->all()));

                $paginator = $query->paginate($recordsPerPage, ['*'], 'page', $page);

                return new LengthAwarePaginator(
                    collect($paginator->items())->map(fn ($row) => (array) $row)->keyBy('id')->all(),
                    $paginator->total(),
                    $recordsPerPage,
                    $page,
                );
            })
            ->columns(array_map(
                fn (string $column) => TextColumn::make($column)->fontFamily($column === 'id' ? 'mono' : null),
                $columns,
            ))
            ->filters([
                SelectFilter::make('source')
                    ->label('From file')
                    ->options(fn (): array => SourceTable::query()
                        ->whereHas('mappings', fn ($q) => $q->where('managed_table_id', $record->id))
                        ->with('tab.spreadsheet')
                        ->get()
                        ->mapWithKeys(fn (SourceTable $table) => [$table->id => $table->label()])
                        ->all()),
            ])
            ->poll(fn (): ?string => $this->isSyncing() ? '2s' : null)
            ->emptyStateHeading(fn (): string => $this->isSyncing() ? 'Syncing…' : 'No rows yet')
            ->emptyStateDescription(fn (): ?string => $this->isSyncing() ? 'The data is being read from Google. This page updates by itself.' : 'Rows appear here after a sync.')
            ->paginated([25, 50, 100]);
    }
}
