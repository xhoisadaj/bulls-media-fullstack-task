<?php

use App\Enums\EndRule;
use App\Sync\Data\TableSpec;
use App\Sync\TableDetector;
use App\Sync\TableLocator;

function grid(int $headerRow, int $startColumn, array $rows): array
{
    $grid = array_fill(0, $headerRow, []);

    foreach ($rows as $row) {
        $grid[] = array_merge(array_fill(0, $startColumn, ''), $row);
    }

    return $grid;
}

it('finds headers that do not start at A1', function () {
    $grid = grid(98, 7, [
        ['order_id', 'product_name', 'price', 'amount'],
        [1001, 'Shoes', 50, 2],
        [1002, 'Bag', 35, 1],
    ]);

    $located = (new TableLocator)->locate($grid, new TableSpec(['order_id', 'product_name', 'price', 'amount'], 98, 7));

    expect($located->found)->toBeTrue()
        ->and($located->headerRow)->toBe(98)
        ->and($located->startColumn)->toBe(7)
        ->and($located->columnOf('price'))->toBe(9)
        ->and($located->dataRows)->toBe([99, 100])
        ->and($located->moved)->toBeFalse();
});

it('finds the table again when rows are inserted above and columns to the left', function () {
    $grid = grid(101, 8, [
        ['order_id', 'product_name', 'price'],
        [1001, 'Shoes', 50],
    ]);

    $located = (new TableLocator)->locate($grid, new TableSpec(['order_id', 'product_name', 'price'], 98, 7));

    expect($located->found)->toBeTrue()->and($located->headerRow)->toBe(101)->and($located->moved)->toBeTrue();
});

it('matches headers ignoring case and extra spaces', function () {
    $located = (new TableLocator)->locate([[' Price ', 'AMOUNT'], [1, 2]], new TableSpec(['price', 'amount'], 0, 0));

    expect($located->columnOf('price'))->toBe(0)->and($located->columnOf('amount'))->toBe(1);
});

it('reports duplicate headers', function () {
    $located = (new TableLocator)->locate([['date', 'price', 'price'], ['x', 1, 2]], new TableSpec(['date', 'price'], 0, 0));

    expect($located->headers['price'])->toBe([1, 2])->and($located->columnOf('price'))->toBeNull();
});

it('stops at the first empty row or reads to the end, depending on the end rule', function () {
    $grid = [['a', 'b'], [1, 2], [], [3, 4]];

    $first = (new TableLocator)->locate($grid, new TableSpec(['a', 'b'], 0, 0, EndRule::FirstEmptyRow));
    $all = (new TableLocator)->locate($grid, new TableSpec(['a', 'b'], 0, 0, EndRule::EndOfSheet));
    $fixed = (new TableLocator)->locate($grid, new TableSpec(['a', 'b'], 0, 0, EndRule::FixedRow, endRow: 1));

    expect($first->dataRows)->toBe([1])->and($all->dataRows)->toBe([1, 3])->and($fixed->dataRows)->toBe([1]);
});

it('stops before the next table header in a stacked layout', function () {
    $grid = [
        ['order', 'amount'], [1, 10], [2, 20],
        ['return', 'reason'], [5, 'broken'],
    ];

    $located = (new TableLocator)->locate($grid, new TableSpec(['order', 'amount'], 0, 0, EndRule::BeforeNextHeader, otherTableHeaders: [['return', 'reason']]));

    expect($located->dataRows)->toBe([1, 2]);
});

it('keeps side-by-side tables apart', function () {
    $grid = [
        ['date', 'sales', '', 'item', 'stock'],
        ['2025-01-01', 10, '', 'Bag', 3],
    ];

    $sales = (new TableLocator)->locate($grid, new TableSpec(['date', 'sales'], 0, 0));
    $stock = (new TableLocator)->locate($grid, new TableSpec(['item', 'stock'], 0, 3));

    expect($sales->endColumn)->toBe(1)->and($stock->startColumn)->toBe(3)->and(array_keys($stock->headers))->toBe(['item', 'stock']);
});

it('reports ambiguity when two identical tables are equally close', function () {
    $grid = [
        ['date', 'price'], [1, 2], [], [], ['date', 'price'], [3, 4],
    ];

    $located = (new TableLocator)->locate($grid, new TableSpec(['date', 'price'], 2, 0));

    expect($located->found)->toBeFalse()->and($located->ambiguous)->toBeTrue();
});

it('picks the closest of two identical tables', function () {
    $grid = [['date', 'price'], [1, 2], [], [], ['date', 'price'], [3, 4]];

    expect((new TableLocator)->locate($grid, new TableSpec(['date', 'price'], 4, 0))->headerRow)->toBe(4);
});

it('reports a column with data but no header', function () {
    $located = (new TableLocator)->locate([['date', 'product', '', 'amount'], ['x', 'Bag', 'oops', 1]], new TableSpec(['date', 'product', 'amount'], 0, 0));

    expect($located->unnamedColumnsWithData)->toBe([2])->and($located->columnOf('amount'))->toBe(3);
});

it('detects stacked and side-by-side tables', function () {
    $grid = [
        [], ['Orders'],
        ['order_id', 'amount', '', 'item', 'stock'],
        [1, 10, '', 'Bag', 3],
        [2, 20, '', 'Belt', 5],
        [],
        ['return_id', 'reason'],
        [9, 'broken'],
    ];

    $tables = (new TableDetector(new TableLocator))->detect($grid);

    expect($tables)->toHaveCount(3)
        ->and($tables[0]->headers)->toBe(['order_id', 'amount'])
        ->and($tables[0]->dataRowCount)->toBe(2)
        ->and($tables[1]->headers)->toBe(['item', 'stock'])
        ->and($tables[2]->range())->toBe('A7:B');
});
