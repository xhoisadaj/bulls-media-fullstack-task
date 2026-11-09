<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Cache;

/**
 * A tracked Google spreadsheet ("file" in the plan).
 */
class Spreadsheet extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_sync_status' => SyncStatus::class,
        ];
    }

    public function tabs(): HasMany
    {
        return $this->hasMany(Tab::class);
    }

    public function sourceTables(): HasManyThrough
    {
        return $this->hasManyThrough(SourceTable::class, Tab::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    public function url(): string
    {
        return 'https://docs.google.com/spreadsheets/d/'.$this->google_id.'/edit';
    }

    public function syncingCacheKey(): string
    {
        return 'spreadsheet-syncing-'.$this->id;
    }

    /**
     * True while a queued sync of this spreadsheet hasn't finished yet.
     */
    public function isSyncing(): bool
    {
        return Cache::has($this->syncingCacheKey());
    }

    /**
     * Non-admin users this spreadsheet is shared with.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
