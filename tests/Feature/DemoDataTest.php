<?php

use App\Demo\DemoData;
use App\Enums\UserRole;
use App\Filament\Resources\Spreadsheets\Pages\ListSpreadsheets;
use App\Google\InMemorySheetsGateway;
use App\Google\RoutingSheetsGateway;
use App\Models\ManagedTable;
use App\Models\Spreadsheet;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    config(['sync.demo_path' => storage_path('framework/testing/demo')]);
    Filament::setCurrentPanel('admin');
});

afterEach(function () {
    foreach (['sales', 'demo_sales', 'demo_customers', 'demo_returns'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('reads demo spreadsheets from JSON and every other one from Google', function () {
    $googleCalls = 0;
    $google = (new InMemorySheetsGateway)->setTab('real-sheet', 'Data', [['a'], [1]]);
    $gateway = new RoutingSheetsGateway(
        (new InMemorySheetsGateway)->setTab('demo-x', 'Demo', [['b'], [2]]),
        function () use ($google, &$googleCalls) {
            $googleCalls++;

            return $google;
        },
    );

    expect($gateway->getSpreadsheet('demo-x')->title)->not->toBe('')
        ->and($googleCalls)->toBe(0)
        ->and(array_keys($gateway->readTabs('real-sheet', ['Data'])))->toBe(['Data'])
        ->and($googleCalls)->toBe(1);
});

it('loads and removes the demo without touching real spreadsheets and tables', function () {
    $this->actingAs(User::factory()->create());
    $real = Spreadsheet::query()->create(['google_id' => 'real-sheet-id', 'name' => 'My real file']);
    $sales = ManagedTable::query()->create(['name' => 'sales']);
    Schema::create('sales', fn ($t) => $t->string('id', 32)->primary());
    DB::table('sales')->insert(['id' => 'R-999']);

    Livewire::test(ListSpreadsheets::class)->callAction('loadDemo');

    expect(DemoData::isLoaded())->toBeTrue()
        ->and(DB::table('demo_sales')->count())->toBe(6 + 4 + 3)
        ->and(Schema::hasTable('demo_customers'))->toBeTrue()
        ->and(Schema::getColumnListing('sales'))->toBe(['id']);

    Livewire::test(ListSpreadsheets::class)->callAction('removeDemo');

    expect(DemoData::isLoaded())->toBeFalse()
        ->and(Schema::hasTable('demo_sales'))->toBeFalse()
        ->and(ManagedTable::query()->where('name', 'like', 'demo_%')->exists())->toBeFalse()
        ->and($real->exists)->toBeTrue()
        ->and(Spreadsheet::query()->whereKey($real->id)->exists())->toBeTrue()
        ->and($sales->fresh())->not->toBeNull()
        ->and(DB::table('sales')->pluck('id')->all())->toBe(['R-999']);
});

it('only shows the demo buttons to admins', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Editor]));

    Livewire::test(ListSpreadsheets::class)->assertActionHidden('loadDemo');
});
