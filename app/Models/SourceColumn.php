<?php

namespace App\Models;

use App\Enums\ColumnType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A column of a source table, as it is in the sheet. The type lives here so every
 * mapping reads the column the same way.
 */
class SourceColumn extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ColumnType::class,
            'compare' => 'boolean',
            'is_present' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function sourceTable(): BelongsTo
    {
        return $this->belongsTo(SourceTable::class);
    }

    public function mappingColumns(): HasMany
    {
        return $this->hasMany(MappingColumn::class);
    }

    public function isMapped(): bool
    {
        return $this->mappingColumns()->whereHas('mapping', fn ($q) => $q->where('is_active', true))->exists();
    }
}
