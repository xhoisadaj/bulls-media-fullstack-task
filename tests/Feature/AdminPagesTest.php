<?php

use App\Filament\Pages\GoogleConnection;
use App\Filament\Resources\ManagedTables\Pages\BrowseManagedTable;
use App\Filament\Resources\ManagedTables\Pages\ListManagedTables;
use App\Filament\Resources\Mappings\MappingResource;
use App\Filament\Resources\Mappings\Pages\CreateMapping;
use App\Filament\Resources\Mappings\Pages\EditMapping;
use App\Filament\Resources\Mappings\Pages\ListMappings;
use App\Filament\Resources\Mappings\Schemas\MappingForm;
use App\Filament\Resources\PendingChanges\Pages\ListPendingChanges;
use App\Filament\Resources\SourceTables\Pages\CreateSourceTable;
use App\Filament\Resources\SourceTables\Pages\EditSourceTable;
use App\Filament\Resources\SourceTables\Pages\ListSourceTables;
use App\Filament\Resources\Spreadsheets\Pages\EditSpreadsheet;
use App\Filament\Resources\Spreadsheets\Pages\ListSpreadsheets;
use App\Filament\Resources\StructureAlerts\Pages\ListStructureAlerts;
use App\Filament\Resources\SyncRuns\Pages\ListSyncRuns;
use App\Google\Contracts\SheetsGateway;
use App\Jobs\SyncSpreadsheetJob;
use App\Models\CredentialAudit;
use App\Models\GoogleSetting;
use App\Models\ManagedTable;
use App\Models\Mapping;
use App\Models\PendingChange;
use App\Models\SourceTable;
use App\Models\Spreadsheet;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    config(['sync.demo_path' => storage_path('framework/testing/demo')]);
    Artisan::call('sheets:demo', ['--fresh' => true]);
    $this->actingAs(User::factory()->create(['password' => 'secret-password']));
    Filament::setCurrentPanel('admin');
});

afterEach(function () {
    // Demo writes target tables outside the test transaction.
    foreach (ManagedTable::query()->pluck('name') as $table) {
        DB::statement('DROP TABLE IF EXISTS `'.$table.'`');
    }
});

it('renders every admin screen with the demo data', function (string $page, ?Closure $params = null) {
    Livewire::test($page, $params ? $params() : [])->assertOk();
})->with([
    'spreadsheets' => [ListSpreadsheets::class],
    'spreadsheet edit' => [EditSpreadsheet::class, fn () => ['record' => Spreadsheet::query()->first()->getRouteKey()]],
    'source tables' => [ListSourceTables::class],
    'source table edit' => [EditSourceTable::class, fn () => ['record' => SourceTable::query()->first()->getRouteKey()]],
    'source table wizard' => [CreateSourceTable::class],
    'mappings' => [ListMappings::class],
    'mapping create' => [CreateMapping::class],
    'mapping edit' => [EditMapping::class, fn () => ['record' => Mapping::query()->first()->getRouteKey()]],
    'review inbox' => [ListPendingChanges::class],
    'alerts' => [ListStructureAlerts::class],
    'sync history' => [ListSyncRuns::class],
    'target tables' => [ListManagedTables::class],
    'browse sales' => [BrowseManagedTable::class, fn () => ['record' => ManagedTable::query()->where('name', 'sales')->first()->getRouteKey()]],
    'google connection' => [GoogleConnection::class],
]);

it('imports the demo right away and approves later changes from the review inbox', function () {
    expect(PendingChange::query()->pending()->count())->toBe(0)
        ->and(DB::table('sales')->count())->toBe(6 + 4 + 3);

    Artisan::call('sheets:demo-change', ['scenario' => 'delete']);
    app()->forgetScopedInstances();
    app()->forgetInstance(SheetsGateway::class);
    Artisan::call('sheets:sync', ['--now' => true]);

    expect(PendingChange::query()->pending()->count())->toBe(1);

    Livewire::test(ListPendingChanges::class)->callAction('approveAll');

    expect(PendingChange::query()->pending()->count())->toBe(0)
        ->and(DB::table('sales')->count())->toBe(6 + 4 + 3 - 1);
});

it('creates a mapping into a new table and backfills it', function () {
    $table = SourceTable::query()->where('name', 'London orders')->first();
    $notes = $table->columns()->where('header', 'notes')->first();

    Livewire::test(CreateMapping::class)
        ->fillForm([
            'source_table_id' => $table->id,
            'target_mode' => 'new',
            'new_table_name' => 'order_notes',
            'columns' => [['source_column_id' => $notes->id, 'target_column' => 'note']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(DB::table('order_notes')->whereNotNull('note')->pluck('note')->sort()->values()->all())->toBe(['blue', 'gift wrap']);
});

it('rejects a mapping into an application table', function () {
    $table = SourceTable::query()->first();

    Livewire::test(CreateMapping::class)
        ->fillForm([
            'source_table_id' => $table->id,
            'target_mode' => 'new',
            'new_table_name' => 'users',
            'columns' => [['source_column_id' => $table->columns()->first()->id, 'target_column' => 'email']],
        ])
        ->call('create')
        ->assertHasErrors();

    expect(Mapping::query()->whereHas('managedTable', fn ($q) => $q->where('name', 'users'))->exists())->toBeFalse();
});

it('saves the API key encrypted and only after the password is confirmed', function () {
    Livewire::test(GoogleConnection::class)
        ->callAction('setApiKey', ['api_key' => 'AIzaSyTestKey-1234567890abcdef', 'current_password' => 'wrong'])
        ->assertHasActionErrors(['current_password']);

    Livewire::test(GoogleConnection::class)
        ->callAction('setApiKey', ['api_key' => 'AIzaSyTestKey-1234567890abcdef', 'current_password' => 'secret-password'])
        ->assertHasNoActionErrors();

    $raw = DB::table('google_settings')->value('api_key');

    expect($raw)->not->toContain('AIzaSy')
        ->and(GoogleSetting::current()->secret('api_key'))->toBe('AIzaSyTestKey-1234567890abcdef')
        ->and(GoogleSetting::current()->toArray())->not->toHaveKey('api_key')
        ->and(CredentialAudit::query()->where('credential', 'api_key')->exists())->toBeTrue();
});

it('fills the client ID and secret from a pasted client JSON and reloads the page after saving', function () {
    $json = json_encode(['web' => ['client_id' => '123-abc.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-test-secret', 'redirect_uris' => ['http://localhost:8000/google/callback']]]);

    Livewire::test(GoogleConnection::class)
        ->mountAction('editClient')
        ->fillForm(['client_json' => $json])
        ->assertSchemaStateSet(['client_id' => '123-abc.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-test-secret'])
        ->fillForm(['current_password' => 'secret-password'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertRedirect(GoogleConnection::getUrl());

    expect(GoogleSetting::current()->oauth_client_id)->toBe('123-abc.apps.googleusercontent.com')
        ->and(GoogleSetting::current()->hasOAuthClient())->toBeTrue();
});

it('serves the full admin pages over HTTP', function () {
    foreach (['/admin', '/admin/spreadsheets', '/admin/source-tables', '/admin/mappings', '/admin/pending-changes', '/admin/structure-alerts', '/admin/sync-runs', '/admin/managed-tables', '/admin/google-connection'] as $url) {
        $this->get($url)->assertOk();
    }

    $this->get('/admin')->assertSee('.ss-table', escape: false);
});

it('rejects an OAuth callback whose state does not match', function () {
    $this->withSession(['google_oauth_state' => 'expected'])
        ->get('/google/callback?state=forged&code=abc')
        ->assertRedirect('/admin/google-connection');

    expect(GoogleSetting::current()->secret('refresh_token'))->toBeNull();
});

it('maps a second file into an existing table by picking its columns', function () {
    $paris = SourceTable::query()->where('name', 'Paris orders')->first();
    $returns = SourceTable::query()->where('name', 'Returns')->first();
    $sales = ManagedTable::query()->where('name', 'sales')->first();

    $matched = MappingForm::mapMatching('sales', $paris->id, []);
    $targets = collect($matched['columns'])->pluck('target_column')->all();

    expect($matched['count'])->toBe(2)->and($targets)->toBe(['amount', 'order_id'])
        ->and(collect($matched['columns'])->pluck('target_type')->all())->toBe(['integer', 'integer'])
        ->and(array_keys(MappingForm::existingColumns('sales')))->toEqualCanonicalizing(['order_id', 'order_date', 'name', 'product_price', 'amount'])
        ->and(MappingForm::typeHint('sales', $returns->columns()->where('header', 'reason')->value('id'), 'my_reason'))->toBe('Recommended: String (up to 255 chars)')
        ->and(MappingForm::typeHint('sales', $returns->columns()->where('header', 'reason')->value('id'), 'amount'))->toBe('Set by the existing column (bigint).')
        ->and((string) MappingForm::typeNote('sales', $returns->columns()->where('header', 'return_date')->value('id'), 'returned_on', 'string'))
        ->toContain('New column, added to sales')->toContain('Values are converted to String')
        ->and((string) MappingForm::overview('sales', $paris->id, $matched['columns']))
        ->toContain('← amount')->toContain('not filled by this file');

    Livewire::test(CreateMapping::class)
        ->fillForm(['source_table_id' => $paris->id, 'target_mode' => 'existing', 'managed_table_id' => $sales->id])
        ->assertSee('Columns of sales')
        ->assertSee('Map matching columns');
});

it('filters a target table by the file its rows came from', function () {
    $london = SourceTable::query()->where('name', 'London orders')->first();

    Livewire::test(BrowseManagedTable::class, ['record' => ManagedTable::query()->where('name', 'sales')->first()->getRouteKey()])
        ->filterTable('source', $london->id)
        ->assertCountTableRecords($london->records()->whereNull('removed_at')->count());
});

it('saves a column under any name and with a type other than the recommended one', function () {
    $london = SourceTable::query()->where('name', 'London orders')->first();

    Livewire::test(CreateMapping::class)
        ->fillForm([
            'source_table_id' => $london->id,
            'target_mode' => 'new',
            'new_table_name' => 'order_texts',
            'columns' => [
                ['source_column_id' => $london->columns()->where('header', 'date')->value('id'), 'target_column' => 'sold_on_text', 'target_type' => 'string'],
                ['source_column_id' => $london->columns()->where('header', 'price')->value('id'), 'target_column' => 'whole_price', 'target_type' => 'integer'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $types = collect(Schema::getColumns('order_texts'))->pluck('type_name', 'name');
    $row = DB::table('order_texts')->orderByRaw('CHAR_LENGTH(id), id')->first();

    expect($types['sold_on_text'])->toBe('varchar')->and($types['whole_price'])->toBe('bigint')
        ->and($row->sold_on_text)->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and(Mapping::query()->latest('id')->first()->columns()->pluck('target_type')->map->value->all())->toBe(['string', 'integer']);
});

it('shows a syncing state on the table page while a sync is queued, and polls until it is done', function () {
    $sales = ManagedTable::query()->where('name', 'sales')->first();
    $spreadsheet = Spreadsheet::query()->where('google_id', 'demo-shop-london')->first();
    $page = fn () => Livewire::test(BrowseManagedTable::class, ['record' => $sales->getRouteKey()]);

    Cache::put($spreadsheet->syncingCacheKey(), true, 60);
    $page()->assertSee('Syncing the data from Google')->assertSeeHtml('wire:poll');

    Cache::forget($spreadsheet->syncingCacheKey());
    $page()->assertDontSee('Syncing the data from Google')->assertDontSeeHtml('wire:poll');
});

it('clears the syncing flag when the queued sync has run', function () {
    $spreadsheet = Spreadsheet::query()->where('google_id', 'demo-shop-london')->first();

    SyncSpreadsheetJob::start($spreadsheet);

    expect($spreadsheet->isSyncing())->toBeFalse()
        ->and($spreadsheet->syncRuns()->exists())->toBeTrue();
});

it('opens the existing mapping when the sheet table already saves into the chosen table, and hides used columns', function () {
    $london = SourceTable::query()->where('name', 'London orders')->first();
    $sales = ManagedTable::query()->where('name', 'sales')->first();
    $mapping = Mapping::query()->where('source_table_id', $london->id)->where('managed_table_id', $sales->id)->first();

    Livewire::test(CreateMapping::class)
        ->fillForm(['source_table_id' => $london->id, 'target_mode' => 'existing'])
        ->set('data.managed_table_id', $sales->id)
        ->assertRedirect(MappingResource::getUrl('edit', ['record' => $mapping]));

    $used = $mapping->columns()->pluck('source_column_id')->all();
    $options = MappingForm::columnOptions($london->id, exclude: $used);

    expect(array_intersect(array_keys($options), $used))->toBe([])
        ->and($options)->toContain('notes');
});
