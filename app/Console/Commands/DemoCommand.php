<?php

namespace App\Console\Commands;

use App\Enums\ApplyMode;
use App\Enums\ColumnType;
use App\Enums\EndRule;
use App\Enums\IdentityMode;
use App\Enums\TriggerType;
use App\Google\SpreadsheetImporter;
use App\Models\GoogleSetting;
use App\Models\ManagedTable;
use App\Models\Mapping;
use App\Models\SourceColumn;
use App\Models\SourceTable;
use App\Models\Spreadsheet;
use App\Sync\Backfiller;
use App\Sync\SchemaManager;
use App\Sync\SyncRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('sheets:demo {--fresh : Remove the demo spreadsheets and restore the original demo data first}')]
#[Description('Load the demo setup: 3 spreadsheets read from JSON (no Google account needed), with tables, mappings and a first sync')]
class DemoCommand extends Command
{
    public function handle(): int
    {
        $path = (string) config('sync.demo_path');

        if ($this->option('fresh') || ! File::isDirectory($path) || File::files($path) === []) {
            File::ensureDirectoryExists($path);
            File::copyDirectory(database_path('demo'), $path);
            Spreadsheet::query()->where('google_id', 'like', 'demo-%')->get()->each->delete();
        }

        GoogleSetting::current()->update(['use_demo_data' => true]);
        app()->forgetScopedInstances();
        $importer = app(SpreadsheetImporter::class);
        $runner = app(SyncRunner::class);

        if (Spreadsheet::query()->where('google_id', 'like', 'demo-%')->exists()) {
            $this->components->info('Demo already loaded. Use --fresh to start over.');

            return self::SUCCESS;
        }

        $sales = $this->definitionSales();

        // 1. Shop London: key = order_id, auto mode, "notes" column not mapped.
        $london = $this->sourceTable($importer->import('demo-shop-london'), 0, 'London orders', 1, 1, IdentityMode::Key, ApplyMode::Auto, [
            'order_id' => ColumnType::Integer, 'date' => ColumnType::Date, 'product_name' => ColumnType::String,
            'price' => ColumnType::Decimal, 'amount' => ColumnType::Integer, 'notes' => ColumnType::String,
        ], keys: ['order_id']);
        $this->map($london, 'sales', $sales);

        // 2. Shop Paris: headers at H99, columns in another order, extra "region" column, review mode.
        $paris = $this->sourceTable($importer->import('demo-shop-paris'), 0, 'Paris orders', 99, 8, IdentityMode::Key, ApplyMode::Review, [
            'product_name' => ColumnType::String, 'date' => ColumnType::Date, 'amount' => ColumnType::Integer,
            'price' => ColumnType::Decimal, 'region' => ColumnType::String, 'order_id' => ColumnType::Integer,
        ], keys: ['order_id']);
        $this->map($paris, 'sales', $sales);

        // 3. Online orders: no ID column (content mode), one sheet → two tables; a returns table stacked below.
        $onlineSheet = $importer->import('demo-online-orders');
        $online = $this->sourceTable($onlineSheet, 0, 'Online orders', 1, 1, IdentityMode::Content, ApplyMode::Review, [
            'date' => ColumnType::Date, 'product_name' => ColumnType::String, 'price' => ColumnType::Decimal, 'amount' => ColumnType::Integer,
            'customer_name' => ColumnType::String, 'customer_email' => ColumnType::String, 'city' => ColumnType::String,
        ]);
        $this->map($online, 'sales', ['date' => 'order_date', 'product_name' => 'name', 'price' => 'product_price', 'amount' => 'amount']);
        $this->map($online, 'customers', ['customer_name' => 'name', 'customer_email' => 'email', 'city' => 'city']);

        $returns = $this->sourceTable($onlineSheet, 0, 'Returns', 9, 1, IdentityMode::Content, ApplyMode::Auto, [
            'return_date' => ColumnType::Date, 'product_name' => ColumnType::String, 'reason' => ColumnType::String,
        ], endRule: EndRule::EndOfSheet);
        $this->map($returns, 'returns', ['return_date' => 'return_date', 'product_name' => 'product', 'reason' => 'reason']);

        foreach (Spreadsheet::query()->where('google_id', 'like', 'demo-%')->get() as $spreadsheet) {
            $run = $runner->run($spreadsheet, TriggerType::Manual);
            $this->components->twoColumnDetail($spreadsheet->name, $run->status->value.' · '.collect($run->counts)->filter()->map(fn ($v, $k) => "$k $v")->implode(', '));
        }

        $this->newLine();
        $this->components->info('Demo loaded. The first sync of every table is imported right away; from now on, changes in review-mode tables (Paris, Online) wait in the review inbox.');
        $this->components->bulletList([
            'Simulate sheet edits with: php artisan sheets:demo-change {edit|sort|delete|restore|add-column|rename-column|clear|reset}',
            'Then click "Sync now" (or run php artisan sheets:sync --now) and look at the review inbox and alerts.',
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function definitionSales(): array
    {
        return ['order_id' => 'order_id', 'date' => 'order_date', 'product_name' => 'name', 'price' => 'product_price', 'amount' => 'amount'];
    }

    /**
     * @param  array<string, ColumnType>  $columns
     * @param  list<string>  $keys
     */
    private function sourceTable(Spreadsheet $spreadsheet, int $googleTabId, string $name, int $headerRow, int $startColumn, IdentityMode $mode, ApplyMode $apply, array $columns, array $keys = [], EndRule $endRule = EndRule::FirstEmptyRow): SourceTable
    {
        $tab = $spreadsheet->tabs()->where('google_tab_id', $googleTabId)->firstOrFail();
        $tab->update(['is_active' => true]);

        $table = SourceTable::query()->create([
            'tab_id' => $tab->id, 'name' => $name, 'header_row' => $headerRow, 'start_column' => $startColumn,
            'header_names' => array_keys($columns), 'end_rule' => $endRule, 'identity_mode' => $mode, 'apply_mode' => $apply,
        ]);

        $ids = [];
        $position = 0;

        foreach ($columns as $header => $type) {
            $ids[$header] = SourceColumn::query()->create([
                'source_table_id' => $table->id, 'header' => $header, 'type' => $type, 'position' => $startColumn + $position++,
            ])->id;
        }

        $table->update(['key_column_ids' => array_map(fn ($k) => $ids[$k], $keys)]);

        return $table;
    }

    /**
     * @param  array<string, string>  $columns  header => target column
     */
    private function map(SourceTable $table, string $target, array $columns): void
    {
        $before = $table->trackedColumns()->pluck('id')->all();
        $mapping = Mapping::query()->create([
            'source_table_id' => $table->id,
            'managed_table_id' => ManagedTable::query()->firstOrCreate(['name' => $target])->id,
        ]);

        foreach ($columns as $header => $targetColumn) {
            $mapping->columns()->create([
                'source_column_id' => $table->columns()->where('header', $header)->value('id'),
                'target_column' => $targetColumn,
            ]);
        }

        app(SchemaManager::class)->apply($mapping);
        app(Backfiller::class)->afterConfigChange($table->refresh(), $before);
    }
}
