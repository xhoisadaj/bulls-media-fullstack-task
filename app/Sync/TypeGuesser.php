<?php

namespace App\Sync;

use App\Enums\ColumnType;

/**
 * Suggests a column type from sample cells. Only a suggestion: the admin confirms it.
 */
class TypeGuesser
{
    /**
     * @param  list<mixed>  $raw
     * @param  list<string>  $formatted
     * @param  list<string|null>  $formulas
     */
    public function guess(array $raw, array $formatted, array $formulas = []): ColumnType
    {
        $samples = [];

        foreach ($raw as $i => $value) {
            $text = trim((string) ($formatted[$i] ?? ''));

            if ($text !== '' || ($formulas[$i] ?? null)) {
                $samples[] = [$value, $text, $formulas[$i] ?? null];
            }
        }

        if ($samples === []) {
            return ColumnType::String;
        }

        $all = fn (callable $test) => collect($samples)->every(fn (array $s) => $test(...$s));

        return match (true) {
            $all(fn ($v, $t, $f) => is_string($f) && preg_match('/^=\s*IMAGE\s*\(/i', $f)) => ColumnType::ImageUrl,
            $all(fn ($v) => is_bool($v)) => ColumnType::Boolean,
            $all(fn ($v, $t) => is_numeric($v) && $this->looksLikeDateTime($t)) => ColumnType::DateTime,
            $all(fn ($v, $t) => is_numeric($v) && $this->looksLikeDate($t)) => ColumnType::Date,
            $all(fn ($v, $t) => is_numeric($v) && ! $this->hasLeadingZero($t) && floor((float) $v) == $v && ! str_contains($t, '.') && ! preg_match('/[€$£%]/u', $t)) => ColumnType::Integer,
            $all(fn ($v, $t) => is_numeric($v) && ! $this->hasLeadingZero($t)) => ColumnType::Decimal,
            $all(fn ($v, $t) => mb_strlen($t) <= 255) => ColumnType::String,
            default => ColumnType::Text,
        };
    }

    private function looksLikeDate(string $text): bool
    {
        return (bool) preg_match('~^\d{1,4}[./-]\d{1,2}[./-]\d{1,4}$|^[A-Za-z]{3,9}\.? \d{1,2},? \d{4}$|^\d{1,2} [A-Za-z]{3,9}\.? \d{4}$~', $text);
    }

    private function looksLikeDateTime(string $text): bool
    {
        return (bool) preg_match('~^\d{1,4}[./-]\d{1,2}[./-]\d{1,4}[ T]\d{1,2}:\d{2}~', $text);
    }

    private function hasLeadingZero(string $text): bool
    {
        return (bool) preg_match('/^0\d/', $text);
    }
}
