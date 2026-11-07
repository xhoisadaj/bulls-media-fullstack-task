<?php

use App\Filament\Pages\GoogleConnection;
use App\Filament\Resources\ManagedTables\Pages\BrowseManagedTable;
use App\Filament\Resources\ManagedTables\Pages\ListManagedTables;
use App\Filament\Resources\Mappings\Pages\CreateMapping;
use App\Filament\Resources\Mappings\Pages\EditMapping;
use App\Filament\Resources\Mappings\Pages\ListMappings;
use App\Filament\Resources\PendingChanges\Pages\ListPendingChanges;
use App\Filament\Resources\SourceTables\Pages\CreateSourceTable;
use App\Filament\Resources\SourceTables\Pages\EditSourceTable;
use App\Filament\Resources\SourceTables\Pages\ListSourceTables;
use App\Filament\Resources\Spreadsheets\Pages\EditSpreadsheet;
use App\Filament\Resources\Spreadsheets\Pages\ListSpreadsheets;
use App\Filament\Resources\StructureAlerts\Pages\ListStructureAlerts;
use App\Filament\Resources\SyncRuns\Pages\ListSyncRuns;
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
use Illuminate\Support\Facades\DB;
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

it('approves pending changes from the review inbox', function () {
    $pending = PendingChange::query()->pending()->count();
    expect($pending)->toBeGreaterThan(0);

    Livewire::test(ListPendingChanges::class)->callAction('approveAll');

    expect(PendingChange::query()->pending()->count())->toBe(0)
        ->and(DB::table('sales')->whereNull('_removed_at')->count())->toBe(6 + 4 + 3);
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

it('serves the full admin pages over HTTP', function () {
    foreach (['/admin', '/admin/spreadsheets', '/admin/source-tables', '/admin/mappings', '/admin/pending-changes', '/admin/structure-alerts', '/admin/sync-runs', '/admin/managed-tables', '/admin/google-connection'] as $url) {
        $this->get($url)->assertOk();
    }
});

it('rejects an OAuth callback whose state does not match', function () {
    $this->withSession(['google_oauth_state' => 'expected'])
        ->get('/google/callback?state=forged&code=abc')
        ->assertRedirect('/admin/google-connection');

    expect(GoogleSetting::current()->secret('refresh_token'))->toBeNull();
});
