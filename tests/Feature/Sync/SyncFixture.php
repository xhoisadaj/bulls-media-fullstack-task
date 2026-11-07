<?php

namespace Tests\Feature\Sync;

use App\Enums\ApplyMode;
use App\Enums\ColumnType;
use App\Enums\EndRule;
use App\Enums\IdentityMode;
use App\Google\Contracts\SheetsGateway;
use App\Google\InMemorySheetsGateway;
use App\Models\ManagedTable;
use App\Models\Mapping;
use App\Models\SourceColumn;
use App\Models\SourceTable;
use App\Models\Spreadsheet;
use App\Models\Tab;
use App\Sync\Backfiller;
use App\Sync\SchemaManager;

/**
 * Builds a spreadsheet + tab + source table + mapping around an in-memory sheet.
 */
final class SyncFixture
{
    public InMemorySheetsGateway $gateway;

    public Spreadsheet $spreadsheet;

    public SourceTable $table;

    public Mapping $mapping;

    /**
     * @param  list<list<mixed>>  $rows  header row first
     * @param  array<string, ColumnType>  $types  header => type
     * @param  array<string, string>  $map  header => target column
     * @param  list<string>  $keys  key headers (key mode)
     */
    public static function make(
        array $rows,
        array $types,
        array $map,
        string $target = 'sales',
        IdentityMode $mode = IdentityMode::Content,
        array $keys = [],
        ApplyMode $apply = ApplyMode::Auto,
        int $headerRow = 1,
        int $startColumn = 1,
    ): self {
        $fixture = new self;
        $fixture->gateway = new InMemorySheetsGateway;
        $fixture->gateway->setTab('sheet-1', 'Sales', $rows, 0, 'Shop London');
        app()->instance(SheetsGateway::class, $fixture->gateway);

        $fixture->spreadsheet = Spreadsheet::query()->create(['google_id' => 'sheet-1', 'name' => 'Shop London']);
        $tab = Tab::query()->create(['spreadsheet_id' => $fixture->spreadsheet->id, 'google_tab_id' => 0, 'name' => 'Sales', 'is_active' => true]);

        $fixture->table = SourceTable::query()->create([
            'tab_id' => $tab->id,
            'name' => 'Orders',
            'header_row' => $headerRow,
            'start_column' => $startColumn,
            'header_names' => array_keys($types),
            'end_rule' => EndRule::FirstEmptyRow,
            'identity_mode' => $mode,
            'apply_mode' => $apply,
        ]);

        $columns = [];
        $position = 1;

        foreach ($types as $header => $type) {
            $columns[$header] = SourceColumn::query()->create([
                'source_table_id' => $fixture->table->id, 'header' => $header, 'type' => $type, 'position' => $position++,
            ]);
        }

        if ($keys !== []) {
            $fixture->table->update(['key_column_ids' => array_map(fn ($k) => $columns[$k]->id, $keys)]);
        }

        $managed = ManagedTable::query()->firstOrCreate(['name' => $target]);
        $fixture->mapping = Mapping::query()->create(['source_table_id' => $fixture->table->id, 'managed_table_id' => $managed->id]);

        foreach ($map as $header => $targetColumn) {
            $fixture->mapping->columns()->create(['source_column_id' => $columns[$header]->id, 'target_column' => $targetColumn]);
        }

        app(SchemaManager::class)->apply($fixture->mapping);
        app(Backfiller::class)->afterConfigChange($fixture->table->refresh(), []);

        return $fixture;
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    public function sheet(array $rows): self
    {
        $this->gateway->setTab('sheet-1', 'Sales', $rows, 0);

        return $this;
    }

    public function column(string $header): SourceColumn
    {
        return $this->table->columns()->where('header', $header)->firstOrFail();
    }
}
