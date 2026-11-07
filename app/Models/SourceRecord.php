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

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }
}
