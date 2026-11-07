<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who changed which credential and when. Never stores the value.
 */
class CredentialAudit extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public static function record(string $credential, string $action): void
    {
        static::query()->create([
            'user_id' => auth()->id(),
            'credential' => $credential,
            'action' => $action,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
