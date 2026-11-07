<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A database table created and owned by the sync. Only these can be altered.
 */
class ManagedTable extends Model
{
    protected $guarded = ['id'];

    public function mappings(): HasMany
    {
        return $this->hasMany(Mapping::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(SchemaHistory::class);
    }
}
