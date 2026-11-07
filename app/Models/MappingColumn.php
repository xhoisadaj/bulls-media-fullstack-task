<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which source column goes to which target column (`price` → `product_price`).
 */
class MappingColumn extends Model
{
    protected $guarded = ['id'];

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(Mapping::class);
    }

    public function sourceColumn(): BelongsTo
    {
        return $this->belongsTo(SourceColumn::class);
    }
}
