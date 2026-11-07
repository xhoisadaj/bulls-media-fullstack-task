<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tab inside a spreadsheet, tracked by Google's tab ID so renames don't break anything.
 */
class Tab extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'google_tab_id' => 'integer',
            'is_active' => 'boolean',
            'is_present' => 'boolean',
        ];
    }

    public function spreadsheet(): BelongsTo
    {
        return $this->belongsTo(Spreadsheet::class);
    }

    public function sourceTables(): HasMany
    {
        return $this->hasMany(SourceTable::class);
    }
}
