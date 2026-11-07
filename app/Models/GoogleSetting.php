<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;

/**
 * The single row holding the Google credentials. Secrets are encrypted with APP_KEY
 * and hidden from serialization; they are never shown back in the UI.
 *
 * @property string|null $oauth_client_id
 * @property ConnectionStatus $oauth_status
 * @property ConnectionStatus $api_key_status
 */
class GoogleSetting extends Model
{
    /** @var list<string> */
    public const SECRET_FIELDS = ['oauth_client_secret', 'api_key', 'refresh_token', 'access_token'];

    protected $guarded = ['id'];

    protected $hidden = self::SECRET_FIELDS;

    protected function casts(): array
    {
        return [
            'oauth_client_secret' => 'encrypted',
            'api_key' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token' => 'encrypted:array',
            'access_token_expires_at' => 'datetime',
            'scopes' => 'array',
            'oauth_status' => ConnectionStatus::class,
            'api_key_status' => ConnectionStatus::class,
            'use_demo_data' => 'boolean',
            'connected_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'oauth_status' => ConnectionStatus::NotSet,
            'api_key_status' => ConnectionStatus::NotSet,
        ]);
    }

    /**
     * Reads a secret without throwing when APP_KEY changed; returns null when unreadable.
     */
    public function secret(string $field): ?string
    {
        try {
            $value = $this->getAttribute($field);
        } catch (DecryptException) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * True when a secret is stored but can no longer be decrypted (APP_KEY lost or changed).
     */
    public function secretIsUnreadable(string $field): bool
    {
        $raw = $this->getRawOriginal($field);

        if ($raw === null || $raw === '') {
            return false;
        }

        try {
            $this->getAttribute($field);

            return false;
        } catch (DecryptException) {
            return true;
        }
    }

    public function hasOAuthClient(): bool
    {
        return filled($this->oauth_client_id) && $this->secret('oauth_client_secret') !== null;
    }

    /**
     * Last four characters of a secret, for the masked hint in the UI.
     */
    public function maskedHint(string $field): ?string
    {
        $value = $this->secret($field);

        return $value === null ? null : str_repeat('•', 12).substr($value, -4);
    }
}
