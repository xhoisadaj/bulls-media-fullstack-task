<?php

namespace App\Models;

use App\Enums\ChangeStatus;
use App\Enums\ChangeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A detected change. Also the full changes log: auto-applied changes are stored as approved.
 *
 * @property array<string, mixed>|null $old
 * @property array<string, mixed>|null $new
 * @property list<string>|null $changed_columns
 */
class PendingChange extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ChangeType::class,
            'status' => ChangeStatus::class,
            'old' => 'array',
            'new' => 'array',
            'changed_columns' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }

    public function sourceTable(): BelongsTo
    {
        return $this->belongsTo(SourceTable::class);
    }

    public function sourceRecord(): BelongsTo
    {
        return $this->belongsTo(SourceRecord::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', ChangeStatus::Pending);
    }
}
