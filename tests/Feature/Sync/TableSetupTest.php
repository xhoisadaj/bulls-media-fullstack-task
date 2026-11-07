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
