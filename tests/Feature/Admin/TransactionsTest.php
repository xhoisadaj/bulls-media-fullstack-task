<?php

use App\Enums\ColumnType;
use App\Enums\IdentityMode;
use App\Enums\UserRole;
use App\Filament\Resources\Mappings\Pages\CreateMapping;
use App\Filament\Resources\SourceTables\Pages\CreateSourceTable;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Google\Contracts\SheetsGateway;
use App\Google\InMemorySheetsGateway;
use App\Models\ManagedTable;
use App\Models\Mapping;
use App\Models\MappingColumn;
use App\Models\SourceRecord;
use App\Models\SourceTable;
use App\Models\Spreadsheet;
use App\Models\Tab;
use App\Models\User;
use App\Sync\Backfiller;
use App\Sync\Fingerprinter;
use App\Sync\SyncRunner;
use App\Sync\TargetWriter;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Wizard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Sync\SyncFixture;

/**
 * Forces a failure halfway through a save and checks that nothing partial was written.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel('admin');
    // In the browser the panel is booted by its middleware on every request; that is what puts
    // every action in a database transaction. Livewire tests skip middleware, so boot it here.
    Filament::bootCurrentPanel();
});

/**
 * Advances the wizard from an explicit step (the current step lives in the browser).
 */
function txNextStep(Testable $component, int $from): Testable
{
    $wizard = $component->instance()->form->getComponent(fn ($c) => $c instanceof Wizard);

    return $component->call('callSchemaComponentMethod', $wizard->getKey(), 'nextStep', [$from]);
}

afterEach(function () {
    foreach (['tx_orders', 'sales'] as $table) {
        Schema::dropIfExists($table);
    }
});

/**
 * Makes the second mapping column fail to save.
 */
function failOnSecondMappingColumn(): void
{
    $saved = 0;
    MappingColumn::creating(function () use (&$saved) {
        if (++$saved === 2) {
            throw new RuntimeException('Simulated failure halfway');
        }
    });
}

it('saves nothing when creating a user fails halfway (user + their spreadsheets)', function () {
    $file = Spreadsheet::query()->create(['google_id' => 'g-1', 'name' => 'File']);
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'insert into `spreadsheet_user`')) {
            throw new RuntimeException('Simulated failure halfway');
        }
    });

    expect(fn () => Livewire::test(ManageUsers::class)->callAction('create', [
        'name' => 'Mira', 'email' => 'mira@example.com', 'password' => 'secret-pass-1',
        'timezone' => 'UTC', 'role' => UserRole::Editor->value, 'spreadsheets' => [$file->id],
    ]))->toThrow(RuntimeException::class);

    expect(User::query()->where('email', 'mira@example.com')->exists())->toBeFalse();
});

it('saves nothing when creating a mapping fails halfway: no mapping, no table, no ALTER', function () {
    $spreadsheet = Spreadsheet::query()->create(['google_id' => 'g-2', 'name' => 'File']);
    $tab = Tab::query()->create(['spreadsheet_id' => $spreadsheet->id, 'google_tab_id' => 0, 'name' => 'Orders']);
    $table = SourceTable::query()->create(['tab_id' => $tab->id, 'name' => 'Orders', 'header_row' => 1, 'start_column' => 1, 'header_names' => ['a', 'b'], 'end_rule' => 'first_empty_row', 'identity_mode' => 'content', 'apply_mode' => 'review']);
    $a = $table->columns()->create(['header' => 'a', 'type' => 'string', 'position' => 1]);
    $b = $table->columns()->create(['header' => 'b', 'type' => 'string', 'position' => 2]);
    failOnSecondMappingColumn();

    expect(fn () => Livewire::test(CreateMapping::class)
        ->fillForm(['source_table_id' => $table->id, 'target_mode' => 'new', 'new_table_name' => 'tx_orders', 'columns' => [
            ['source_column_id' => $a->id, 'target_column' => 'a', 'target_type' => 'string'],
            ['source_column_id' => $b->id, 'target_column' => 'b', 'target_type' => 'string'],
        ]])
        ->call('create'))->toThrow(RuntimeException::class);

    expect(Mapping::query()->count())->toBe(0)
        ->and(MappingColumn::query()->count())->toBe(0)
        ->and(ManagedTable::query()->where('name', 'tx_orders')->exists())->toBeFalse()
        ->and(Schema::hasTable('tx_orders'))->toBeFalse();
});

it('saves nothing when the sheet table wizard fails halfway: no sheet table, no columns, no table', function () {
    app()->instance(SheetsGateway::class, (new InMemorySheetsGateway)->setTab('g-3', 'Orders', [['a', 'b'], [1, 2]]));
    $spreadsheet = Spreadsheet::query()->create(['google_id' => 'g-3', 'name' => 'File']);
    $tab = Tab::query()->create(['spreadsheet_id' => $spreadsheet->id, 'google_tab_id' => 0, 'name' => 'Orders']);
    failOnSecondMappingColumn();

    $wizard = Livewire::test(CreateSourceTable::class)->fillForm(['spreadsheet_id' => $spreadsheet->id, 'tab_id' => $tab->id]);
    txNextStep($wizard, 0);
    $wizard->fillForm(['detected' => 'r1c1']);
    txNextStep($wizard, 1);
    $wizard->fillForm(['target_mode' => 'new', 'new_table_name' => 'tx_orders']);
    txNextStep($wizard, 2);

    expect(fn () => $wizard->fillForm(['identity_mode' => IdentityMode::Content->value, 'apply_mode' => 'review'])->call('create'))
        ->toThrow(RuntimeException::class);

    expect(SourceTable::query()->count())->toBe(0)
        ->and(DB::table('source_columns')->count())->toBe(0)
        ->and(Mapping::query()->count())->toBe(0)
        ->and(Schema::hasTable('tx_orders'))->toBeFalse()
        ->and($tab->refresh()->is_active)->toBeFalse();
});

it('leaves stored rows and the target table as they were when a backfill fails halfway', function () {
    $fixture = SyncFixture::make(
        [['name', 'amount'], ['Xhoi', 20], ['Ana', 35]],
        ['name' => ColumnType::String, 'amount' => ColumnType::Integer],
        ['name' => 'name', 'amount' => 'amount'],
    );
    app(SyncRunner::class)->run($fixture->spreadsheet->refresh());
    $before = SourceRecord::query()->orderBy('id')->pluck('fingerprint')->all();

    $fixture->column('amount')->update(['compare' => false]);
    app()->instance(TargetWriter::class, Mockery::mock(TargetWriter::class, function ($mock) {
        $mock->shouldReceive('projectAll')->andThrow(new RuntimeException('Simulated failure halfway'));
    }));

    expect(fn () => app(Backfiller::class)->afterConfigChange($fixture->table->refresh(), [$fixture->column('name')->id, $fixture->column('amount')->id]))
        ->toThrow(RuntimeException::class);

    expect(SourceRecord::query()->orderBy('id')->pluck('fingerprint')->all())->toBe($before)
        ->and($fixture->table->refresh()->hash_version)->not->toBe(app(Fingerprinter::class)->hashVersion([$fixture->column('name')->id]));
});
