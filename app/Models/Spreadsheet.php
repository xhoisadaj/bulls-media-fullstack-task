<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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
}
