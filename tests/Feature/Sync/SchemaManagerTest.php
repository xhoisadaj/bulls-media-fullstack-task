<?php

use App\Enums\ColumnType;
use App\Models\ManagedTable;
use App\Sync\SchemaManager;
use Illuminate\Support\Facades\Schema;

it('rejects unsafe or reserved names', function (string $name) {
    expect(app(SchemaManager::class)->nameError($name))->not->toBeNull();
})->with(['Product Price', 'select', '2024_sales', 'price;DROP TABLE x', '_hidden', str_repeat('a', 65)]);

it('accepts valid names', function () {
    expect(app(SchemaManager::class)->nameError('product_price'))->toBeNull();
});

it('refuses to use application tables as targets', function () {
    $errors = app(SchemaManager::class)->validatePlan('users', [['target' => 'email', 'type' => ColumnType::String]]);

    expect($errors[0])->toContain('belongs to the application');
});

it('detects type conflicts with existing columns and duplicate targets', function () {
    ManagedTable::query()->create(['name' => 'sales']);
    Schema::create('sales', function ($table) {
        $table->id();
        $table->bigInteger('amount')->nullable();
    });

    $errors = app(SchemaManager::class)->validatePlan('sales', [
        ['target' => 'amount', 'type' => ColumnType::Decimal],
        ['target' => 'name', 'type' => ColumnType::String],
        ['target' => 'name', 'type' => ColumnType::String],
        ['target' => 'id', 'type' => ColumnType::Integer],
    ]);

    expect(implode(' ', $errors))
        ->toContain('already exists as bigint')
        ->toContain('used twice')
        ->toContain('reserved for the sync');
});
