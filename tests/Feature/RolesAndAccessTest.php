<?php

use App\Enums\UserRole;
use App\Exports\TableExporter;
use App\Filament\Pages\GoogleConnection;
use App\Filament\Resources\ManagedTables\Pages\BrowseManagedTable;
use App\Filament\Resources\ManagedTables\Pages\ListManagedTables;
use App\Filament\Resources\PendingChanges\Pages\ListPendingChanges;
use App\Filament\Resources\SourceTables\Pages\CreateSourceTable;
use App\Filament\Resources\Spreadsheets\Pages\EditSpreadsheet;
use App\Filament\Resources\Spreadsheets\Pages\ListSpreadsheets;
use App\Filament\Resources\SyncRuns\Pages\ListSyncRuns;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Resources\Users\UserResource;
use App\Google\Contracts\SheetsGateway;
use App\Models\ManagedTable;
use App\Models\PendingChange;
use App\Models\SourceTable;
use App\Models\Spreadsheet;
use App\Models\SyncRun;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    config(['sync.demo_path' => storage_path('framework/testing/demo')]);
    Artisan::call('sheets:demo', ['--fresh' => true]);
    Filament::setCurrentPanel('admin');

    $this->london = Spreadsheet::query()->where('google_id', 'demo-shop-london')->first();
    $this->paris = Spreadsheet::query()->where('google_id', 'demo-shop-paris')->first();
    $this->sales = ManagedTable::query()->where('name', 'sales')->first();
});

afterEach(function () {
    foreach (ManagedTable::query()->pluck('name') as $table) {
        DB::statement('DROP TABLE IF EXISTS `'.$table.'`');
    }
});

function userWith(UserRole $role, Spreadsheet ...$spreadsheets): User
{
    $user = User::factory()->create(['role' => $role]);
    $user->spreadsheets()->sync(array_map(fn (Spreadsheet $s) => $s->id, $spreadsheets));

    return $user;
}

it('shows an editor only the spreadsheets shared with them, and everything that belongs to them', function () {
    $this->actingAs(userWith(UserRole::Editor, $this->london));
    $londonTable = SourceTable::query()->where('name', 'London orders')->first();

    Livewire::test(ListSpreadsheets::class)
        ->assertCanSeeTableRecords([$this->london])
        ->assertCanNotSeeTableRecords([$this->paris])
        ->assertActionHidden('fetchFromGoogle')
        ->assertActionHidden('addByUrl');

    Livewire::test(ListSyncRuns::class)
        ->assertCanSeeTableRecords(SyncRun::query()->where('spreadsheet_id', $this->london->id)->get())
        ->assertCanNotSeeTableRecords(SyncRun::query()->where('spreadsheet_id', $this->paris->id)->get());

    $londonRows = $londonTable->records()->whereNull('removed_at')->count();

    Livewire::test(BrowseManagedTable::class, ['record' => $this->sales->getRouteKey()])
        ->assertCountTableRecords($londonRows);

    $export = json_decode(file_get_contents(app(TableExporter::class)->export($this->sales, 'json', BrowseManagedTable::visibleSourceTables(null))), true);
    expect($export)->toHaveCount($londonRows);

    $this->get(EditSpreadsheet::getUrl(['record' => $this->paris]))->assertNotFound();
    $this->get(GoogleConnection::getUrl())->assertForbidden();
    $this->get(ManageUsers::getUrl())->assertForbidden();
    $this->get('/admin/sync-schedules')->assertForbidden();
    $this->get(CreateSourceTable::getUrl())->assertOk();

    Livewire::test(ListManagedTables::class)->assertTableActionHidden('addColumn', $this->sales);
});

it('lets a viewer look and export, but not sync, approve or set up tables', function () {
    $this->actingAs(userWith(UserRole::Viewer, $this->paris));
    $change = PendingChange::query()->pending()->whereHas('sourceTable.tab', fn ($q) => $q->where('spreadsheet_id', $this->paris->id))->first();

    Livewire::test(ListSpreadsheets::class)
        ->assertCanSeeTableRecords([$this->paris])
        ->assertTableActionHidden('syncNow', $this->paris)
        ->assertActionHidden('syncAll');

    if ($change !== null) {
        Livewire::test(ListPendingChanges::class)->assertTableActionHidden('approve', $change);
    }

    $this->get(CreateSourceTable::getUrl())->assertForbidden();
    Livewire::test(ListManagedTables::class)->callTableAction('export_csv', $this->sales)->assertFileDownloaded();
});

it('lets an admin add users with a role and their spreadsheets, but never remove the last admin', function () {
    $admin = userWith(UserRole::Admin);
    User::query()->whereKeyNot($admin->id)->delete();
    $this->actingAs($admin);

    Livewire::test(ManageUsers::class)
        ->callAction('create', [
            'name' => 'Mira', 'email' => 'mira@example.com', 'password' => 'secret-pass-1',
            'timezone' => 'Europe/Tirane', 'role' => UserRole::Editor->value, 'spreadsheets' => [$this->london->id],
        ])
        ->assertHasNoActionErrors();

    $mira = User::query()->where('email', 'mira@example.com')->first();

    expect($mira->role)->toBe(UserRole::Editor)
        ->and($mira->spreadsheets()->pluck('spreadsheets.id')->all())->toBe([$this->london->id])
        ->and(Hash::check('secret-pass-1', $mira->password))->toBeTrue()
        ->and(UserResource::wouldRemoveLastAdmin($admin, 'viewer'))->toBeTrue()
        ->and($admin->can('delete', $admin))->toBeFalse()
        ->and($admin->can('delete', $mira))->toBeTrue();
});

it('notifies admins and the users a spreadsheet is shared with, nobody else', function () {
    $admin = userWith(UserRole::Admin);
    $londonEditor = userWith(UserRole::Editor, $this->london);
    $parisViewer = userWith(UserRole::Viewer, $this->paris);

    Artisan::call('sheets:demo-change', ['scenario' => 'delete']);
    app()->forgetScopedInstances();
    app()->forgetInstance(SheetsGateway::class);
    Artisan::call('sheets:sync', ['--file' => $this->london->id, '--now' => true]);

    expect($admin->notifications()->count())->toBeGreaterThan(0)
        ->and($londonEditor->notifications()->count())->toBeGreaterThan(0)
        ->and($parisViewer->notifications()->count())->toBe(0);
});
