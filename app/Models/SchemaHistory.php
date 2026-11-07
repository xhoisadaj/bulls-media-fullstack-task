<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every CREATE / ALTER the sync ran. Replaces migration files for runtime tables.
 */
class SchemaHistory extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function managedTable(): BelongsTo
    {
        return $this->belongsTo(ManagedTable::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
