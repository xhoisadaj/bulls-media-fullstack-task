<?php

use App\Enums\IdentityMode;
use App\Filament\Resources\SourceTables\Pages\CreateSourceTable;
use App\Google\Contracts\SheetsGateway;
use App\Google\InMemorySheetsGateway;
use App\Models\ManagedTable;
use App\Models\SourceTable;
use App\Models\Spreadsheet;
use App\Models\Tab;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Wizard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Advances the wizard from an explicit step. The current step lives in the browser,
 * so the generic goToNextWizardStep() helper would always start from step 0.
 */
function nextStep(Testable $component, int $from): Testable
{
    $wizard = $component->instance()->form->getComponent(fn ($c) => $c instanceof Wizard);

    return $component->call('callSchemaComponentMethod', $wizard->getKey(), 'nextStep', [$from]);
}

/**
 * Tab → table at the given option → save into a new database table. Leaves the wizard on the columns step.
 */
function throughTarget(Testable $wizard, int|string $tabId, string $detected, string $table = 'wizard_orders', array $extra = []): Testable
{
    $wizard->fillForm(['spreadsheet_id' => Tab::query()->find($tabId)->spreadsheet_id, 'tab_id' => $tabId]);
    nextStep($wizard, 0)->assertHasNoFormErrors();
    $wizard->fillForm(['detected' => $detected] + $extra);
    nextStep($wizard, 1)->assertHasNoFormErrors();
    $wizard->fillForm(['target_mode' => 'new', 'new_table_name' => $table]);

    return nextStep($wizard, 2)->assertHasNoFormErrors();
}

afterEach(function () {
    Schema::dropIfExists('wizard_orders');
    Schema::dropIfExists('sales_existing');
});

beforeEach(function () {
    config(['cache.default' => 'database']);
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel('admin');

    $rows = [[], [], ['', 'order_id', 'product_name', 'price'], ['', 1001, 'Shoes', 50], ['', 1002, 'Bag', 35]];
    app()->instance(SheetsGateway::class, (new InMemorySheetsGateway)->setTab('sheet-w', 'Orders', $rows));

    $spreadsheet = Spreadsheet::query()->create(['google_id' => 'sheet-w', 'name' => 'Wizard']);
    $this->tab = Tab::query()->create(['spreadsheet_id' => $spreadsheet->id, 'google_tab_id' => 0, 'name' => 'Orders']);
});

it('goes from sheet to database in one wizard and imports the first sync right away', function () {
    $wizard = throughTarget(Livewire::test(CreateSourceTable::class), $this->tab->id, 'r3c2');

    expect($wizard->get('data.header_row'))->toBe(3)
        ->and(collect($wizard->get('data.columns'))->pluck('type')->values()->all())->toBe(['integer', 'string', 'integer'])
        ->and(collect($wizard->get('data.columns'))->pluck('target')->values()->all())->toBe(['order_id', 'product_name', 'price']);

    $columns = $wizard->get('data.columns');
    $keys = array_keys($columns);
    $columns[$keys[1]]['target'] = 'name';
    $columns[$keys[2]]['save'] = false;

    $wizard->fillForm(['columns' => $columns, 'identity_mode' => IdentityMode::Key->value, 'key_headers' => ['order_id'], 'apply_mode' => 'review'])
        ->call('create')
        ->assertHasNoFormErrors();

    $table = SourceTable::query()->sole();
    $mapping = $table->mappings()->sole();

    expect($table->header_row)->toBe(3)
        ->and($table->start_column)->toBe(2)
        ->and($table->columns()->pluck('header')->all())->toBe(['order_id', 'product_name', 'price'])
        ->and($table->keyColumns()->pluck('header')->all())->toBe(['order_id'])
        ->and($this->tab->refresh()->is_active)->toBeTrue()
        ->and($mapping->managedTable->name)->toBe('wizard_orders')
        ->and($mapping->columns()->orderBy('id')->pluck('target_column')->all())->toBe(['order_id', 'name'])
        ->and(DB::table('wizard_orders')->orderBy('order_id')->pluck('name')->all())->toBe(['Shoes', 'Bag'])
        ->and(Schema::hasColumn('wizard_orders', 'price'))->toBeFalse();
});

it('adds a second file to an existing table: same names are matched, the rest must be picked', function () {
    throughTarget(Livewire::test(CreateSourceTable::class), $this->tab->id, 'r3c2', 'sales_existing')
        ->fillForm(['identity_mode' => IdentityMode::Content->value, 'apply_mode' => 'auto'])
        ->call('create')
        ->assertHasNoFormErrors();

    $second = Tab::query()->create(['spreadsheet_id' => $this->tab->spreadsheet_id, 'google_tab_id' => 1, 'name' => 'More']);
    app(SheetsGateway::class)->setTab('sheet-w', 'More', [['order_id', 'item', 'price', 'region'], [2001, 'Hat', 9, 'north']], 1);

    $wizard = Livewire::test(CreateSourceTable::class)->fillForm(['spreadsheet_id' => $second->spreadsheet_id, 'tab_id' => $second->id]);
    nextStep($wizard, 0);
    $wizard->fillForm(['detected' => 'r1c1']);
    nextStep($wizard, 1);
    $wizard->fillForm(['target_mode' => 'existing', 'managed_table_id' => ManagedTable::query()->where('name', 'sales_existing')->value('id')]);
    nextStep($wizard, 2)->assertHasNoFormErrors();

    $columns = collect($wizard->get('data.columns'));

    expect($columns->pluck('target_pick', 'header')->all())->toBe(['order_id' => 'order_id', 'item' => null, 'price' => 'price', 'region' => null])
        ->and($columns->firstWhere('header', 'price')['type'])->toBe('integer');

    $wizard->assertSee('Columns of sales_existing')->assertSee('product_name · varchar')
        ->fillForm(['identity_mode' => IdentityMode::Content->value, 'apply_mode' => 'auto'])
        ->call('create')
        ->assertHasFormErrors();

    $edited = $wizard->get('data.columns');

    foreach ($edited as $key => $column) {
        $edited[$key]['target_pick'] = match ($column['header']) {
            'item' => 'product_name',
            'region' => '__new__',
            default => $column['target_pick'],
        };
    }

    $wizard->fillForm(['columns' => $edited])
        ->call('create')
        ->assertHasNoFormErrors();

    $row = DB::table('sales_existing')->where('order_id', 2001)->first();

    expect($row->product_name)->toBe('Hat')
        ->and($row->region)->toBe('north')
        ->and(DB::table('sales_existing')->count())->toBe(3);
});

it('accepts a manually entered header cell', function () {
    throughTarget(Livewire::test(CreateSourceTable::class), $this->tab->id, 'manual', extra: ['manual_cell' => 'B3'])
        ->fillForm(['identity_mode' => IdentityMode::Content->value, 'apply_mode' => 'auto'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SourceTable::query()->sole()->header_row)->toBe(3);
});

it('rejects a key that is not unique', function () {
    app()->instance(SheetsGateway::class, (new InMemorySheetsGateway)->setTab('sheet-w', 'Orders', [['order_id', 'price'], [1001, 50], [1001, 35]]));

    throughTarget(Livewire::test(CreateSourceTable::class), $this->tab->id, 'r1c1')
        ->fillForm(['identity_mode' => IdentityMode::Key->value, 'key_headers' => ['order_id'], 'apply_mode' => 'review'])
        ->call('create')
        ->assertHasFormErrors(['key_headers']);

    expect(SourceTable::query()->count())->toBe(0);
});

it('shows a preview of the tab with the chosen table highlighted', function () {
    $wizard = Livewire::test(CreateSourceTable::class)->fillForm(['spreadsheet_id' => $this->tab->spreadsheet_id, 'tab_id' => $this->tab->id]);
    nextStep($wizard, 0);
    $wizard->fillForm(['detected' => 'r3c2']);

    $wizard->assertSee('Tab preview')
        ->assertSeeHtml('data-cell="B3"')
        ->assertSeeHtml('data-option="r3c2"')
        ->assertSeeHtml('ss-grid-head');
});

it('shows the first values of each column and saves system ID mode without any write access', function () {
    $wizard = throughTarget(Livewire::test(CreateSourceTable::class), $this->tab->id, 'r3c2');

    expect(collect($wizard->get('data.columns'))->pluck('samples', 'header')->all())
        ->toBe(['order_id' => ['1001', '1002'], 'product_name' => ['Shoes', 'Bag'], 'price' => ['50', '35']]);

    $wizard->assertSee('Shoes')
        ->fillForm(['identity_mode' => IdentityMode::SystemId->value, 'apply_mode' => 'review'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SourceTable::query()->sole()->identity_mode)->toBe(IdentityMode::SystemId);
});

it('only lists the tabs of the chosen spreadsheet, preselected when opened from a tab', function () {
    $other = Spreadsheet::query()->create(['google_id' => 'sheet-other', 'name' => 'Other file']);
    Tab::query()->create(['spreadsheet_id' => $other->id, 'google_tab_id' => 0, 'name' => 'Elsewhere']);

    $this->get(CreateSourceTable::getUrl(['tab' => $this->tab->id]))->assertOk();

    Livewire::withQueryParams(['tab' => $this->tab->id])
        ->test(CreateSourceTable::class)
        ->assertSchemaStateSet(['spreadsheet_id' => $this->tab->spreadsheet_id, 'tab_id' => $this->tab->id])
        ->assertFormFieldExists('tab_id', fn ($field) => array_values($field->getOptions()) === ['Orders']);
});
