<?php

use App\Filament\Resources\SyncRuns\Pages\ListSyncRuns;
use App\Filament\Resources\SyncSchedules\Pages\ManageSyncSchedules;
use App\Jobs\SyncSpreadsheetJob;
use App\Models\Spreadsheet;
use App\Models\SyncSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['timezone' => 'Europe/Tirane']));
    Filament::setCurrentPanel('admin');
    SyncSchedule::query()->delete();
});

it('is due at its local times on its weekdays, once per slot', function () {
    $schedule = SyncSchedule::query()->create(['times' => ['08:00', '17:30'], 'days' => [1, 2, 3, 4, 5], 'timezone' => 'Europe/Tirane']);

    // Monday 2026-11-09 08:00 in Tirane = 07:00 UTC.
    $monday8 = CarbonImmutable::parse('2026-11-09 07:00:20', 'UTC');

    expect($schedule->isDue($monday8))->toBeTrue()
        ->and($schedule->isDue(CarbonImmutable::parse('2026-11-09 08:00:00', 'UTC')))->toBeFalse()
        ->and($schedule->isDue(CarbonImmutable::parse('2026-11-14 07:00:00', 'UTC')))->toBeFalse()
        ->and($schedule->nextRunAt($monday8)->format('D H:i'))->toBe('Mon 17:30')
        ->and($schedule->nextRunAt(CarbonImmutable::parse('2026-11-13 18:00:00', 'UTC'))->format('D H:i'))->toBe('Mon 08:00');

    $schedule->update(['last_triggered_at' => $monday8]);

    expect($schedule->refresh()->isDue($monday8->addSeconds(30)))->toBeFalse();
});

it('starts the due schedules from the scheduler command, one job per file', function () {
    Queue::fake();
    $a = Spreadsheet::query()->create(['google_id' => 'g-a', 'name' => 'A']);
    $b = Spreadsheet::query()->create(['google_id' => 'g-b', 'name' => 'B']);
    $now = CarbonImmutable::now('UTC');

    SyncSchedule::query()->create(['times' => [$now->format('H:i')], 'days' => [1, 2, 3, 4, 5, 6, 7], 'timezone' => 'UTC']);
    SyncSchedule::query()->create(['spreadsheet_id' => $a->id, 'times' => [$now->format('H:i')], 'days' => [1, 2, 3, 4, 5, 6, 7], 'timezone' => 'UTC']);
    SyncSchedule::query()->create(['times' => [$now->addHours(2)->format('H:i')], 'days' => [1, 2, 3, 4, 5, 6, 7], 'timezone' => 'UTC']);

    $this->artisan('sheets:run-schedules')->assertSuccessful();
    $this->artisan('sheets:run-schedules')->assertSuccessful();

    Queue::assertPushed(SyncSpreadsheetJob::class, 2);
    Queue::assertPushed(SyncSpreadsheetJob::class, fn ($job) => $job->spreadsheet->is($b));
});

it('adds a schedule and syncs all files from the sync history page', function () {
    Queue::fake();
    Spreadsheet::query()->create(['google_id' => 'g-a', 'name' => 'A']);

    Livewire::test(ListSyncRuns::class)
        ->callAction('addSchedule', ['times' => [['time' => '09:15'], ['time' => '21:00']], 'days' => [1, 3, 5], 'timezone' => 'Europe/Tirane', 'is_active' => true])
        ->assertHasNoActionErrors()
        ->callAction('syncAllFiles');

    $schedule = SyncSchedule::query()->sole();

    expect($schedule->normalizedTimes())->toBe(['09:15', '21:00'])
        ->and($schedule->daysLabel())->toBe('Mon, Wed, Fri')
        ->and($schedule->spreadsheet_id)->toBeNull();

    Queue::assertPushed(SyncSpreadsheetJob::class, 1);
});

it('saves the user timezone and shows dates in it', function () {
    Livewire::test(ManageSyncSchedules::class)
        ->callAction('timezone', ['timezone' => 'America/New_York'])
        ->assertHasNoActionErrors();

    expect(auth()->user()->refresh()->timezone)->toBe('America/New_York');

    $this->get(ManageSyncSchedules::getUrl())->assertOk()->assertSee('Your timezone: America/New_York');
});
