<?php

use App\Enums\IdentityMode;
use App\Filament\Resources\SourceTables\Pages\CreateSourceTable;
use App\Google\Contracts\SheetsGateway;
use App\Google\InMemorySheetsGateway;
use App\Models\SourceTable;
use App\Models\Spreadsheet;
use App\Models\Tab;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Wizard;
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

beforeEach(function () {
    config(['cache.default' => 'database']);
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel('admin');

    $rows = [[], [], ['', 'order_id', 'product_name', 'price'], ['', 1001, 'Shoes', 50], ['', 1002, 'Bag', 35]];
    app()->instance(SheetsGateway::class, (new InMemorySheetsGateway)->setTab('sheet-w', 'Orders', $rows));

    $spreadsheet = Spreadsheet::query()->create(['google_id' => 'sheet-w', 'name' => 'Wizard']);
    $this->tab = Tab::query()->create(['spreadsheet_id' => $spreadsheet->id, 'google_tab_id' => 0, 'name' => 'Orders']);
});

it('walks through the whole wizard and saves the detected position, columns and key', function () {
    $wizard = Livewire::test(CreateSourceTable::class)->fillForm(['tab_id' => $this->tab->id]);
    nextStep($wizard, 0)->assertHasNoFormErrors();
    $wizard->fillForm(['detected' => 'r3c2']);
    nextStep($wizard, 1)->assertHasNoFormErrors();

    expect($wizard->get('data.header_row'))->toBe(3)
        ->and(collect($wizard->get('data.columns'))->pluck('type')->values()->all())->toBe(['integer', 'string', 'integer']);

    $wizard->fillForm(['identity_mode' => IdentityMode::Key->value, 'key_headers' => ['order_id'], 'apply_mode' => 'review'])
        ->call('create')
        ->assertHasNoFormErrors();

    $table = SourceTable::query()->sole();

    expect($table->header_row)->toBe(3)
        ->and($table->start_column)->toBe(2)
        ->and($table->columns()->pluck('header')->all())->toBe(['order_id', 'product_name', 'price'])
        ->and($table->keyColumns()->pluck('header')->all())->toBe(['order_id'])
        ->and($this->tab->refresh()->is_active)->toBeTrue();
});

it('accepts a manually entered header cell', function () {
    $wizard = Livewire::test(CreateSourceTable::class)->fillForm(['tab_id' => $this->tab->id]);
    nextStep($wizard, 0);
    $wizard->fillForm(['detected' => 'manual', 'manual_cell' => 'B3']);
    nextStep($wizard, 1)->assertHasNoFormErrors();

    $wizard->fillForm(['identity_mode' => IdentityMode::Content->value, 'apply_mode' => 'auto'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SourceTable::query()->sole()->header_row)->toBe(3);
});

it('rejects a key that is not unique', function () {
    app()->instance(SheetsGateway::class, (new InMemorySheetsGateway)->setTab('sheet-w', 'Orders', [['order_id', 'price'], [1001, 50], [1001, 35]]));

    $wizard = Livewire::test(CreateSourceTable::class)->fillForm(['tab_id' => $this->tab->id]);
    nextStep($wizard, 0);
    $wizard->fillForm(['detected' => 'r1c1']);
    nextStep($wizard, 1);
    $wizard->fillForm(['identity_mode' => IdentityMode::Key->value, 'key_headers' => ['order_id'], 'apply_mode' => 'review'])
        ->call('create')
        ->assertHasFormErrors(['key_headers']);

    expect(SourceTable::query()->count())->toBe(0);
});
