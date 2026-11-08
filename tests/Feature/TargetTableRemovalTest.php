<?php

use App\Enums\ColumnType;
use App\Filament\Resources\ManagedTables\Pages\ListManagedTables;
use App\Models\ManagedTable;
use App\Models\User;
use App\Sync\SchemaException;
use App\Sync\SchemaManager;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Feature\Sync\SyncFixture;

/**
 * A target table like the sync creates, with the given extra columns.
 *
 * @param  list<string>  $columns
 */
function targetTable(string $name, array $columns): ManagedTable
{
    Schema::dropIfExists($name);
    Schema::create($name, function ($table) use ($columns) {
        $table->string('id', 32)->primary();

        foreach ($columns as $column) {
            $table->string($column)->nullable();
        }
    });

    return ManagedTable::query()->create(['name' => $name]);
}

beforeEach(function () {
    $this->actingAs(User::factory()->create(['password' => 'secret-password']));
    Filament::setCurrentPanel('admin');
});

afterEach(function () {
    foreach (['tmp_orders', 'tmp_empty_a', 'tmp_empty_b', 'tmp_full', 'people'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('removes only empty, unmapped columns and logs it', function () {
    $table = targetTable('tmp_orders', ['note', 'colour', 'size']);
    DB::table('tmp_orders')->insert(['id' => 'R-101', 'size' => 'XL']);

    $usage = app(SchemaManager::class)->columnUsage($table);

    expect(array_keys($usage))->toBe(['note', 'colour', 'size'])
        ->and($usage['note']['problem'])->toBeNull()
        ->and($usage['size']['problem'])->toBe('1 row(s) have data');

    app(SchemaManager::class)->dropColumns($table, ['note', 'colour']);

    expect(Schema::getColumnListing('tmp_orders'))->not->toContain('note')->not->toContain('colour')->toContain('size')
        ->and($table->history()->pluck('column_name', 'action')->all())->toBe(['drop_column' => 'colour']);
});

it('refuses columns with data, system columns and mapped columns, and then drops nothing', function () {
    $fixture = SyncFixture::make([['name', 'amount'], ['Xhoi', 20]], ['name' => ColumnType::String, 'amount' => ColumnType::Integer], ['name' => 'name'], target: 'people');
    Schema::table('people', fn ($t) => $t->string('spare')->nullable());
    $table = $fixture->mapping->managedTable;

    expect(fn () => app(SchemaManager::class)->dropColumns($table, ['spare', 'name', 'id']))
        ->toThrow(fn (SchemaException $e) => expect(implode(' ', $e->errors))->toContain('mapped by Orders')->toContain('needed by the sync'));

    expect(Schema::getColumnListing('people'))->toContain('spare');
});

it('deletes a table only when it is empty and unmapped', function () {
    $empty = targetTable('tmp_empty_a', ['note']);
    $full = targetTable('tmp_full', ['note']);
    DB::table('tmp_full')->insert(['id' => 'R-101']);

    expect(app(SchemaManager::class)->dropTableProblems($full)[0])->toContain('1 row(s)');

    app(SchemaManager::class)->dropTable($empty);

    expect(Schema::hasTable('tmp_empty_a'))->toBeFalse()->and(ManagedTable::query()->whereKey($empty->id)->exists())->toBeFalse()
        ->and(fn () => app(SchemaManager::class)->dropTable($full))->toThrow(SchemaException::class);
});

it('removes columns from the target tables screen after the password is confirmed', function () {
    $table = targetTable('tmp_orders', ['note', 'size']);

    Livewire::test(ListManagedTables::class)
        ->callTableAction('removeColumns', $table, ['columns' => ['note'], 'current_password' => 'wrong'])
        ->assertHasTableActionErrors(['current_password']);

    Livewire::test(ListManagedTables::class)
        ->callTableAction('removeColumns', $table, ['columns' => ['note'], 'current_password' => 'secret-password'])
        ->assertHasNoTableActionErrors();

    expect(Schema::getColumnListing('tmp_orders'))->not->toContain('note')->toContain('size');
});

it('deletes several empty tables at once and skips tables with rows', function () {
    $a = targetTable('tmp_empty_a', []);
    $b = targetTable('tmp_empty_b', []);
    $full = targetTable('tmp_full', []);
    DB::table('tmp_full')->insert(['id' => 'R-101']);

    Livewire::test(ListManagedTables::class)
        ->callTableBulkAction('deleteEmptyTables', [$a, $b, $full], ['current_password' => 'secret-password'])
        ->assertNotified('Deleted: tmp_empty_a, tmp_empty_b');

    expect(Schema::hasTable('tmp_empty_a'))->toBeFalse()->and(Schema::hasTable('tmp_empty_b'))->toBeFalse()
        ->and(Schema::hasTable('tmp_full'))->toBeTrue();
});

it('adds an empty column with any name and type, and rejects reserved or existing names', function () {
    $table = targetTable('tmp_orders', ['note']);

    Livewire::test(ListManagedTables::class)
        ->callTableAction('addColumn', $table, ['name' => 'discount', 'type' => 'decimal'])
        ->assertNotified('Added tmp_orders.discount');

    expect(collect(Schema::getColumns('tmp_orders'))->firstWhere('name', 'discount')['type_name'])->toBe('decimal')
        ->and($table->history()->where('action', 'add_column')->value('column_name'))->toBe('discount')
        ->and(fn () => app(SchemaManager::class)->addEmptyColumn($table, 'note', ColumnType::String))->toThrow(SchemaException::class)
        ->and(fn () => app(SchemaManager::class)->addEmptyColumn($table, 'id', ColumnType::String))->toThrow(SchemaException::class);
});
