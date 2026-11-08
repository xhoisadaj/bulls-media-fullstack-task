<?php

use App\Enums\AlertType;
use App\Enums\ApplyMode;
use App\Enums\ChangeStatus;
use App\Enums\ChangeType;
use App\Enums\ColumnType;
use App\Enums\IdentityMode;
use App\Enums\SourceTableStatus;
use App\Enums\SyncStatus;
use App\Google\Exceptions\GoogleAccessException;
use App\Models\ManagedTable;
use App\Models\Mapping;
use App\Models\PendingChange;
use App\Models\SchemaHistory;
use App\Models\StructureAlert;
use App\Models\User;
use App\Sync\Backfiller;
use App\Sync\ChangeApplier;
use App\Sync\SchemaManager;
use App\Sync\SyncRunner;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\SyncFixture;

function orders(array $rows): array
{
    return [['order_id', 'product_name', 'price', 'amount'], ...$rows];
}

function syncNow(SyncFixture $fixture, bool $allowMassRemoval = false)
{
    return app(SyncRunner::class)->run($fixture->spreadsheet->refresh(), allowMassRemoval: $allowMassRemoval);
}

function keyFixture(array $rows, ApplyMode $apply = ApplyMode::Auto): SyncFixture
{
    return SyncFixture::make(
        orders($rows),
        ['order_id' => ColumnType::Integer, 'product_name' => ColumnType::String, 'price' => ColumnType::Decimal, 'amount' => ColumnType::Integer],
        ['order_id' => 'order_id', 'product_name' => 'name', 'price' => 'product_price', 'amount' => 'amount'],
        mode: IdentityMode::Key,
        keys: ['order_id'],
        apply: $apply,
    );
}

it('creates the target table with renamed columns and syncs rows (requirement #5)', function () {
    $fixture = keyFixture([[1001, 'Shoes', 50, 2], [1002, 'Bag', 35, 1]]);

    $run = syncNow($fixture);

    expect($run->status)->toBe(SyncStatus::Success)
        ->and(DB::table('sales')->orderBy('order_id')->get(['order_id', 'name', 'product_price', 'amount'])->map(fn ($r) => (array) $r)->all())
        ->toBe([
            ['order_id' => 1001, 'name' => 'Shoes', 'product_price' => '50.000000', 'amount' => 2],
            ['order_id' => 1002, 'name' => 'Bag', 'product_price' => '35.000000', 'amount' => 1],
        ])
        ->and(SchemaHistory::query()->where('action', 'add_column')->count())->toBe(4);
});

it('is idempotent: syncing twice changes nothing', function () {
    $fixture = keyFixture([[1001, 'Shoes', 50, 2]]);
    syncNow($fixture);

    $second = syncNow($fixture);

    expect($second->counts['unchanged'])->toBe(1)
        ->and($second->counts['new'])->toBe(0)
        ->and(DB::table('sales')->count())->toBe(1);
});

it('ignores re-sorted rows and detects a modification by key', function () {
    $fixture = keyFixture([[1001, 'Xhoi', 20, 1], [1002, 'Ana', 35, 1]]);
    syncNow($fixture);

    $fixture->sheet(orders([[1002, 'Ana', 40, 1], [1001, 'Xhoi', 20, 1], [1004, 'Mira', 55, 1]]));
    $run = syncNow($fixture);

    $modified = PendingChange::query()->where('type', ChangeType::Modified)->sole();

    expect($run->counts)->toMatchArray(['unchanged' => 1, 'modified' => 1, 'new' => 1])
        ->and($modified->status)->toBe(ChangeStatus::Approved)
        ->and($modified->changed_columns)->toBe([(string) $fixture->column('price')->id])
        ->and(DB::table('sales')->where('order_id', 1002)->value('product_price'))->toBe('40.000000');
});

it('shows an edit as removed + new in content mode, and keeps removals pending even in auto mode', function () {
    $fixture = SyncFixture::make(
        [['name', 'city', 'amount'], ['Xhoi', 'Tirana', 20], ['Ana', 'Durres', 35]],
        ['name' => ColumnType::String, 'city' => ColumnType::String, 'amount' => ColumnType::Integer],
        ['name' => 'name', 'city' => 'city', 'amount' => 'amount'],
        target: 'people',
    );
    syncNow($fixture);

    $fixture->sheet([['name', 'city', 'amount'], ['Ana', 'Durres', 40], ['Xhoi', 'Tirana', 20]]);
    $run = syncNow($fixture);

    expect($run->counts)->toMatchArray(['unchanged' => 1, 'new' => 1, 'removed' => 1])
        ->and(PendingChange::query()->where('type', ChangeType::Removed)->sole()->status)->toBe(ChangeStatus::Pending)
        ->and(DB::table('people')->whereNull('_removed_at')->count())->toBe(3);
});

it('imports the first sync right away in review mode, then queues changes until approved', function () {
    $fixture = keyFixture([[1001, 'Shoes', 50, 2]], ApplyMode::Review);

    $first = syncNow($fixture);

    expect($first->counts)->toMatchArray(['new' => 1, 'auto_applied' => 1])
        ->and(DB::table('sales')->value('name'))->toBe('Shoes')
        ->and(PendingChange::query()->pending()->count())->toBe(0);

    $fixture->sheet(orders([[1001, 'Shoes', 60, 2], [1002, 'Bag', 35, 1]]));
    syncNow($fixture);

    expect(PendingChange::query()->pending()->count())->toBe(2)
        ->and(DB::table('sales')->count())->toBe(1)
        ->and(DB::table('sales')->value('product_price'))->toBe('50.000000');

    app(ChangeApplier::class)->approve(PendingChange::query()->pending()->where('type', 'new')->sole(), User::factory()->create());

    expect(DB::table('sales')->count())->toBe(2);
});

it('does not raise a rejected change again until the row changes again', function () {
    $fixture = keyFixture([[1001, 'Shoes', 50, 2]], ApplyMode::Review);
    $user = User::factory()->create();
    syncNow($fixture);
    app(ChangeApplier::class)->approve(PendingChange::query()->sole(), $user);

    $fixture->sheet(orders([[1001, 'Shoes', 60, 2]]));
    syncNow($fixture);
    app(ChangeApplier::class)->reject(PendingChange::query()->pending()->sole(), $user);

    syncNow($fixture);
    expect(PendingChange::query()->pending()->count())->toBe(0);

    $fixture->sheet(orders([[1001, 'Shoes', 70, 2]]));
    syncNow($fixture);
    expect(PendingChange::query()->pending()->sole()->new[(string) $fixture->column('price')->id])->toBe('70');
});

it('supersedes a pending change when the row changes again before review', function () {
    $fixture = keyFixture([[1001, 'Shoes', 50, 2]], ApplyMode::Review);
    syncNow($fixture);
    app(ChangeApplier::class)->approve(PendingChange::query()->sole(), User::factory()->create());

    $fixture->sheet(orders([[1001, 'Shoes', 60, 2]]));
    syncNow($fixture);
    $fixture->sheet(orders([[1001, 'Shoes', 70, 2]]));
    syncNow($fixture);

    expect(PendingChange::query()->where('status', ChangeStatus::Superseded)->count())->toBe(1)
        ->and(PendingChange::query()->pending()->sole()->old[(string) $fixture->column('price')->id])->toBe('50');
});

it('blocks a mass removal and changes nothing', function () {
    $fixture = keyFixture([[1, 'a', 1, 1], [2, 'b', 1, 1], [3, 'c', 1, 1], [4, 'd', 1, 1]]);
    syncNow($fixture);

    $fixture->sheet(orders([]));
    $run = syncNow($fixture);

    expect(PendingChange::query()->where('type', ChangeType::Removed)->count())->toBe(0)
        ->and($run->status)->toBe(SyncStatus::Failed)
        ->and(StructureAlert::query()->where('type', AlertType::MassRemoval)->exists())->toBeTrue();

    syncNow($fixture, allowMassRemoval: true);
    expect(PendingChange::query()->where('type', ChangeType::Removed)->count())->toBe(4);
});

it('restores a removed row that comes back', function () {
    $fixture = keyFixture([[1001, 'Shoes', 50, 2], [1002, 'Bag', 35, 1]]);
    syncNow($fixture);
    $fixture->sheet(orders([[1001, 'Shoes', 50, 2]]));
    syncNow($fixture);
    app(ChangeApplier::class)->approve(PendingChange::query()->pending()->sole(), User::factory()->create());

    expect(DB::table('sales')->where('order_id', 1002)->value('_removed_at'))->not->toBeNull();

    $fixture->sheet(orders([[1001, 'Shoes', 50, 2], [1002, 'Bag', 35, 1]]));
    syncNow($fixture);

    expect(PendingChange::query()->where('type', ChangeType::Restored)->sole()->status)->toBe(ChangeStatus::Approved)
        ->and(DB::table('sales')->where('order_id', 1002)->value('_removed_at'))->toBeNull();
});

it('finds the table after rows are inserted above it and raises an info alert', function () {
    $fixture = keyFixture([[1001, 'Shoes', 50, 2]]);
    syncNow($fixture);

    $fixture->sheet([[], [], ['notes'], ...orders([[1001, 'Shoes', 50, 2]])]);
    $run = syncNow($fixture);

    expect($run->status)->toBe(SyncStatus::Success)
        ->and($run->counts['unchanged'])->toBe(1)
        ->and($fixture->table->refresh()->header_row)->toBe(4)
        ->and(StructureAlert::query()->where('type', AlertType::HeaderMoved)->exists())->toBeTrue();
});

it('ignores reordered columns', function () {
    $fixture = keyFixture([[1001, 'Shoes', 50, 2]]);
    syncNow($fixture);

    $fixture->sheet([['amount', 'price', 'order_id', 'product_name'], [2, 50, 1001, 'Shoes']]);

    expect(syncNow($fixture)->counts['unchanged'])->toBe(1);
});

it('marks the table broken when a mapped column is deleted, leaving data untouched', function () {
    $fixture = keyFixture([[1001, 'Shoes', 50, 2]]);
    syncNow($fixture);

    $fixture->sheet([['order_id', 'product_name', 'amount'], [1001, 'Shoes', 2]]);
    $run = syncNow($fixture);

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($fixture->table->refresh()->status)->toBe(SourceTableStatus::Broken)
        ->and(StructureAlert::query()->where('type', AlertType::ColumnMissing)->exists())->toBeTrue()
        ->and(DB::table('sales')->value('product_price'))->toBe('50.000000');
});

it('alerts about a new sheet column without changing anything, then fills it once mapped (requirement #6)', function () {
    $fixture = keyFixture([[1001, 'Shoes', 50, 2], [1002, 'Bag', 35, 1]]);
    syncNow($fixture);

    $fixture->sheet([['order_id', 'product_name', 'region', 'price', 'amount'], [1001, 'Shoes', 'North', 50, 2], [1002, 'Bag', 'South', 35, 1]]);
    $run = syncNow($fixture);

    expect($run->counts)->toMatchArray(['unchanged' => 2, 'modified' => 0])
        ->and(StructureAlert::query()->where('type', AlertType::ColumnAdded)->value('message'))->toContain('region')
        ->and(DB::getSchemaBuilder()->hasColumn('sales', 'region'))->toBeFalse();

    $previous = $fixture->table->trackedColumns()->pluck('id')->all();
    $fixture->mapping->columns()->create(['source_column_id' => $fixture->column('region')->id, 'target_column' => 'region']);
    app(SchemaManager::class)->apply($fixture->mapping->refresh());
    app(Backfiller::class)->afterConfigChange($fixture->table->refresh(), $previous);

    expect(DB::table('sales')->orderBy('order_id')->pluck('region')->all())->toBe(['North', 'South'])
        ->and(PendingChange::query()->where('type', ChangeType::Modified)->count())->toBe(0)
        ->and(syncNow($fixture)->counts['unchanged'])->toBe(2);
});

it('backfills a new column in content mode by matching the old fingerprint', function () {
    $fixture = SyncFixture::make(
        [['name', 'city', 'phone'], ['Xhoi', 'Tirana', '0692'], ['Ana', 'Durres', '0681']],
        ['name' => ColumnType::String, 'city' => ColumnType::String, 'phone' => ColumnType::String],
        ['name' => 'name', 'city' => 'city'],
        target: 'people',
    );
    syncNow($fixture);

    $previous = $fixture->table->trackedColumns()->pluck('id')->all();
    $fixture->mapping->columns()->create(['source_column_id' => $fixture->column('phone')->id, 'target_column' => 'phone_number']);
    app(SchemaManager::class)->apply($fixture->mapping->refresh());
    app(Backfiller::class)->afterConfigChange($fixture->table->refresh(), $previous);

    expect(DB::table('people')->orderBy('name')->pluck('phone_number', 'name')->all())->toBe(['Ana' => '0681', 'Xhoi' => '0692'])
        ->and(syncNow($fixture)->counts['unchanged'])->toBe(2);
});

it('flags duplicate keys and skips rows with formula errors', function () {
    $fixture = keyFixture([[1001, 'Shoes', 50, 2], [1002, 'Bag', '#DIV/0!', 1], [1003, 'Belt', 20, 1], [1003, 'Hat', 25, 1]]);

    $run = syncNow($fixture);

    expect(DB::table('sales')->pluck('order_id')->all())->toBe([1001])
        ->and(collect($run->errors)->pluck('message')->implode(' '))
        ->toContain('#DIV/0!')
        ->toContain('duplicate key 1003');
});

it('skips the table while IMPORTRANGE is still loading', function () {
    $fixture = keyFixture([[1001, 'Shoes', 50, 2]]);
    syncNow($fixture);

    $fixture->sheet(orders([[1001, 'Loading...', 50, 2]]));
    $run = syncNow($fixture);

    expect(PendingChange::query()->where('type', ChangeType::Removed)->count())->toBe(0)
        ->and(collect($run->errors)->pluck('message')->implode(' '))->toContain('Loading');
});

it('writes one sheet row to two tables linked by _record_id', function () {
    $fixture = SyncFixture::make(
        [['first_name', 'last_name', 'city', 'phone'], ['Xhoi', 'Sadaj', 'Tirana', '0692']],
        ['first_name' => ColumnType::String, 'last_name' => ColumnType::String, 'city' => ColumnType::String, 'phone' => ColumnType::String],
        ['first_name' => 'first_name', 'last_name' => 'last_name'],
        target: 'people_names',
    );

    $contacts = Mapping::query()->create([
        'source_table_id' => $fixture->table->id,
        'managed_table_id' => ManagedTable::query()->create(['name' => 'people_contacts'])->id,
    ]);
    $contacts->columns()->create(['source_column_id' => $fixture->column('city')->id, 'target_column' => 'city']);
    $contacts->columns()->create(['source_column_id' => $fixture->column('phone')->id, 'target_column' => 'phone']);
    app(SchemaManager::class)->apply($contacts);
    app(Backfiller::class)->afterConfigChange($fixture->table->refresh(), []);

    syncNow($fixture);

    $joined = DB::table('people_names')->join('people_contacts', 'people_names._record_id', '=', 'people_contacts._record_id')
        ->first(['first_name', 'city', 'phone']);

    expect((array) $joined)->toBe(['first_name' => 'Xhoi', 'city' => 'Tirana', 'phone' => '0692']);
});

function systemIdFixture(): SyncFixture
{
    $fixture = SyncFixture::make(
        [['name', 'city', 'amount'], ['Xhoi', 'Tirana', 20], ['Ana', 'Durres', 35], ['Ben', 'Vlore', 15], ['Mira', 'Shkoder', 50]],
        ['name' => ColumnType::String, 'city' => ColumnType::String, 'amount' => ColumnType::Integer],
        ['name' => 'name', 'city' => 'city', 'amount' => 'amount'],
        target: 'people',
        mode: IdentityMode::SystemId,
    );
    syncNow($fixture);

    return $fixture;
}

it('keeps system IDs on our side and pairs a row edited in place, without writing to the sheet', function () {
    $fixture = systemIdFixture();
    $anaId = DB::table('people')->where('name', 'Ana')->value('_record_id');

    expect(DB::table('people')->count())->toBe(4);

    $fixture->sheet([['name', 'city', 'amount'], ['Xhoi', 'Tirana', 20], ['Ana', 'Durres', 40], ['Ben', 'Vlore', 15], ['Mira', 'Shkoder', 50]]);
    $run = syncNow($fixture);
    $change = PendingChange::query()->pending()->sole();

    expect($run->counts)->toMatchArray(['modified' => 1, 'new' => 0, 'removed' => 0, 'auto_applied' => 0])
        ->and($change->source_record_id)->toBe($anaId)
        ->and($change->matched_by)->toBe('position')
        ->and(DB::table('people')->where('name', 'Ana')->value('amount'))->toBe(35);

    app(ChangeApplier::class)->approve($change, User::factory()->create());

    expect(DB::table('people')->where('_record_id', $anaId)->value('amount'))->toBe(40)
        ->and(DB::table('people')->count())->toBe(4)
        ->and($fixture->gateway->readTabs('sheet-1', ['Sales'])['Sales']->formatted[0])->toBe(['name', 'city', 'amount']);
});

it('still pairs an edited row when a row above it is deleted', function () {
    $fixture = systemIdFixture();

    $fixture->sheet([['name', 'city', 'amount'], ['Xhoi', 'Tirana', 20], ['Ben', 'Vlore', 15], ['Mira', 'Shkoder', 99]]);
    $run = syncNow($fixture);

    expect($run->counts)->toMatchArray(['modified' => 1, 'removed' => 1, 'new' => 0])
        ->and(PendingChange::query()->where('type', 'modified')->sole()->source_record_id)
        ->toBe(DB::table('people')->where('name', 'Mira')->value('_record_id'));
});

it('does not pair a row that was edited and moved', function () {
    $fixture = systemIdFixture();

    $fixture->sheet([['name', 'city', 'amount'], ['Ana', 'Durres', 40], ['Ben', 'Vlore', 15], ['Mira', 'Shkoder', 50], ['Xhoi', 'Tirana', 20]]);
    $run = syncNow($fixture);

    expect($run->counts)->toMatchArray(['modified' => 0, 'removed' => 1, 'new' => 1]);
});

it('treats a rejected position pairing as removed plus new on the next sync', function () {
    $fixture = systemIdFixture();
    $fixture->sheet([['name', 'city', 'amount'], ['Xhoi', 'Tirana', 20], ['Lea', 'Fier', 70], ['Ben', 'Vlore', 15], ['Mira', 'Shkoder', 50]]);
    syncNow($fixture);

    app(ChangeApplier::class)->reject(PendingChange::query()->pending()->sole(), User::factory()->create());
    $run = syncNow($fixture);

    expect($run->counts)->toMatchArray(['modified' => 0, 'removed' => 1, 'new' => 1, 'auto_applied' => 1])
        ->and(DB::table('people')->where('name', 'Lea')->exists())->toBeTrue()
        ->and(PendingChange::query()->pending()->sole()->type->value)->toBe('removed');
});

it('fails one file cleanly without touching data when it becomes inaccessible', function () {
    $fixture = keyFixture([[1001, 'Shoes', 50, 2]]);
    syncNow($fixture);

    $fixture->gateway->failWith('sheet-1', new GoogleAccessException('The sheet is not public.', 'not_public'));
    $run = syncNow($fixture);

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and(StructureAlert::query()->where('type', AlertType::FileNotAccessible)->exists())->toBeTrue()
        ->and(DB::table('sales')->count())->toBe(1);
});
