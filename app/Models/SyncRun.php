<?php

namespace App\Models;

use App\Enums\CredentialType;
use App\Enums\SyncStatus;
use App\Enums\TriggerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, int>|null $counts
 * @property list<array<string, mixed>>|null $errors
 */
class SyncRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trigger' => TriggerType::class,
            'credential_used' => CredentialType::class,
            'status' => SyncStatus::class,
            'counts' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function spreadsheet(): BelongsTo
    {
        return $this->belongsTo(Spreadsheet::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(PendingChange::class);
    }
}
