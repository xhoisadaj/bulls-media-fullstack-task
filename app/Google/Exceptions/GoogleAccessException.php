<?php

namespace App\Google\Exceptions;

use RuntimeException;

/**
 * A Google call failed. `reason` says why in a way the UI can explain.
 */
class GoogleAccessException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const AUTH_FAILED = 'auth_failed';

    public const NOT_ACCESSIBLE = 'not_accessible';

    public const NOT_PUBLIC = 'not_public';

    public const INVALID_KEY = 'invalid_key';

    public const RATE_LIMITED = 'rate_limited';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  array<string, string>  $attempts  credential => reason message, when a fallback was tried
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $attempts = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Errors where trying the next credential makes sense (auth or access), as opposed to rate limits or outages.
     */
    public function allowsFallback(): bool
    {
        return in_array($this->reason, [
            self::NOT_CONFIGURED, self::AUTH_FAILED, self::NOT_ACCESSIBLE, self::NOT_PUBLIC, self::INVALID_KEY,
        ], true);
    }
}
