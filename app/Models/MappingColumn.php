<?php

namespace App\Models;

use App\Enums\ColumnType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which source column goes to which target column (`price` → `product_price`).
 */
class MappingColumn extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['target_type' => ColumnType::class];
    }

    /**
     * The database column type: the one chosen in the mapping, else the sheet column's type.
     */
    public function targetType(): ColumnType
    {
        return $this->target_type ?? $this->sourceColumn->type;
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(Mapping::class);
    }

    public function sourceColumn(): BelongsTo
    {
        return $this->belongsTo(SourceColumn::class);
    }
}
