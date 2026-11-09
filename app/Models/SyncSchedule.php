<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Syncs one spreadsheet (or all of them) at the given times on the given weekdays, in a timezone.
 *
 * @property list<string> $times "HH:MM", local to the timezone
 * @property list<int> $days ISO weekdays: 1 = Monday … 7 = Sunday
 */
class SyncSchedule extends Model
{
    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'times' => 'array',
            'days' => 'array',
            'is_active' => 'boolean',
            'last_triggered_at' => 'datetime',
        ];
    }

    public function spreadsheet(): BelongsTo
    {
        return $this->belongsTo(Spreadsheet::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The files this schedule syncs: its spreadsheet, or every active one.
     *
     * @return Collection<int, Spreadsheet>
     */
    public function spreadsheets(): Collection
    {
        return $this->spreadsheet_id === null
            ? Spreadsheet::query()->where('is_active', true)->get()
            : Spreadsheet::query()->whereKey($this->spreadsheet_id)->where('is_active', true)->get();
    }

    /**
     * Whether one of the times falls in the given minute (on a chosen weekday) and hasn't fired yet.
     */
    public function isDue(CarbonImmutable $now): bool
    {
        $local = $now->setTimezone($this->timezone)->startOfMinute();

        if (! in_array($local->isoWeekday(), array_map('intval', $this->days ?? []), true)
            || ! in_array($local->format('H:i'), $this->normalizedTimes(), true)) {
            return false;
        }

        return $this->last_triggered_at === null || $this->last_triggered_at->lt($local->utc());
    }

    /**
     * The next moment this schedule fires, in its own timezone.
     */
    public function nextRunAt(CarbonImmutable $now): ?CarbonImmutable
    {
        $local = $now->setTimezone($this->timezone);
        $times = $this->normalizedTimes();
        sort($times);

        for ($day = 0; $day <= 7; $day++) {
            $date = $local->addDays($day);

            if (! in_array($date->isoWeekday(), array_map('intval', $this->days ?? []), true)) {
                continue;
            }

            foreach ($times as $time) {
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $candidate = $date->setTime($hour, $minute);

                if ($candidate->gt($local)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    public function daysLabel(): string
    {
        $days = array_map('intval', $this->days ?? []);
        sort($days);

        return match (true) {
            count($days) === 7 => 'Every day',
            $days === [1, 2, 3, 4, 5] => 'Weekdays',
            $days === [6, 7] => 'Weekends',
            default => implode(', ', array_map(fn (int $d) => self::DAYS[$d], $days)),
        };
    }

    /**
     * @return list<string>
     */
    public function normalizedTimes(): array
    {
        return array_values(array_unique(array_map(fn ($t) => substr((string) $t, 0, 5), $this->times ?? [])));
    }
}
