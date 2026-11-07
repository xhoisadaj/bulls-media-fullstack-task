<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "This source table goes to that database table."
 */
class Mapping extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function sourceTable(): BelongsTo
    {
        return $this->belongsTo(SourceTable::class);
    }

    public function managedTable(): BelongsTo
    {
        return $this->belongsTo(ManagedTable::class);
    }

    public function columns(): HasMany
    {
        return $this->hasMany(MappingColumn::class);
    }
}
