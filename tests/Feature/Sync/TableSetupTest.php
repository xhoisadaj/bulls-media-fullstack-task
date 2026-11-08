<?php

use App\Google\Contracts\SheetsGateway;
use App\Google\InMemorySheetsGateway;
use App\Models\Spreadsheet;
use App\Models\Tab;
use App\Sync\TableSetup;

it('reads tab values back from the database cache (which does not restore objects)', function () {
    config(['cache.default' => 'database']);

    $gateway = (new InMemorySheetsGateway)->setTab('sheet-x', 'Orders', [['order_id', 'price'], [1001, 50], [1002, 35]]);
    app()->instance(SheetsGateway::class, $gateway);

    $spreadsheet = Spreadsheet::query()->create(['google_id' => 'sheet-x', 'name' => 'X']);
    $tab = Tab::query()->create(['spreadsheet_id' => $spreadsheet->id, 'google_tab_id' => 0, 'name' => 'Orders']);

    $setup = app(TableSetup::class);
    $first = $setup->detect($tab);
    $second = $setup->detect($tab);

    expect($second)->toHaveCount(1)
        ->and($second[0]->headers)->toBe($first[0]->headers)
        ->and(array_column($setup->columns($tab, 1, 1), 'type'))->toBe(['integer', 'integer']);
});

it('builds a tab preview that marks the chosen table and reaches past it', function () {
    $rows = array_fill(0, 120, []);
    $rows[109] = ['', '', 'order_id', 'price'];
    $rows[110] = ['', '', 1001, 50];
    $rows[111] = ['', '', 1002, 35];
    app()->instance(SheetsGateway::class, (new InMemorySheetsGateway)->setTab('sheet-p', 'Orders', $rows));

    $spreadsheet = Spreadsheet::query()->create(['google_id' => 'sheet-p', 'name' => 'P']);
    $tab = Tab::query()->create(['spreadsheet_id' => $spreadsheet->id, 'google_tab_id' => 0, 'name' => 'Orders']);

    $setup = app(TableSetup::class);
    $plain = $setup->preview($tab);
    $marked = $setup->preview($tab, [110, 3]);

    expect($plain['rows'])->toHaveCount(100)
        ->and($plain['mark'])->toBeNull()
        ->and($plain['tables'])->toBe(['C110' => 'r110c3'])
        ->and($marked['rows'])->toHaveCount(117)
        ->and($marked['rows'][109])->toBe(['', '', 'order_id', 'price'])
        ->and($marked['mark'])->toBe(['header_row' => 109, 'start_column' => 2, 'end_column' => 3, 'last_row' => 111]);
});
