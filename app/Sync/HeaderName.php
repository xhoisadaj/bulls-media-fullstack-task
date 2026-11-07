<?php

namespace App\Sync;

/**
 * Header comparison ignores case and extra spaces: " Price " equals "price".
 */
final class HeaderName
{
    public static function normalize(mixed $header): string
    {
        $text = is_scalar($header) ? (string) $header : '';

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
