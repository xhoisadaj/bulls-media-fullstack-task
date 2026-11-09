<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Our copy of one sheet row, with a permanent ID. `data` holds the normalized value of
 * every source column, keyed by source column ID.
 *
 * @property array<string, mixed> $data
 */
class SourceRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'keep_on_removal' => 'boolean',
            'removed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'sheet_row' => 'integer',
        ];
    }

    public function sourceTable(): BelongsTo
    {
        return $this->belongsTo(SourceTable::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /**
     * The row's ID as it appears in the database tables: R-101, R-102, …
     */
    public function code(): string
    {
        return self::codeFor($this->id);
    }

    public static function codeFor(int $id): string
    {
        return 'R-'.($id + 100);
    }

    /**
     * The row IDs (R-101, …) of the current rows of the given sheet tables.
     *
     * @param  list<int>  $sourceTableIds
     * @return list<string>
     */
    public static function codesFor(array $sourceTableIds): array
    {
        return static::query()->whereIn('source_table_id', $sourceTableIds)->whereNull('removed_at')
            ->pluck('id')->map(fn (int $id) => self::codeFor($id))->all();
    }

    public static function idFromCode(string $code): ?int
    {
        return preg_match('/^R-(\d+)$/', $code, $m) ? (int) $m[1] - 100 : null;
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }
}
