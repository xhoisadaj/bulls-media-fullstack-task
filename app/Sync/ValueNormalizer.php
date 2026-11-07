<?php

namespace App\Sync;

use App\Enums\ColumnType;
use App\Sync\Data\NormalizedValue;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns sheet cells into clean, comparable values, so formatting never counts as a change.
 * Numbers and dates are read from the raw value; text columns from what people see.
 */
class ValueNormalizer
{
    /** Formula errors Google Sheets can show in a cell. */
    public const ERROR_VALUES = ['#REF!', '#N/A', '#DIV/0!', '#VALUE!', '#NAME?', '#NUM!', '#NULL!', '#ERROR!', '#SPILL!', '#CALC!'];

    /** Text values that mean a formula (e.g. IMPORTRANGE) is still loading. */
    public const LOADING_VALUES = ['loading...', 'loading…'];

    public const DECIMAL_SCALE = 6;

    public function normalize(ColumnType $type, mixed $raw, string $formatted, ?string $formula = null): NormalizedValue
    {
        $display = trim($formatted);

        if (in_array(strtoupper($display), self::ERROR_VALUES, true)) {
            return NormalizedValue::invalid('formula error '.$display);
        }

        if (in_array(mb_strtolower($display), self::LOADING_VALUES, true)) {
            return NormalizedValue::loading();
        }

        return match ($type) {
            ColumnType::String => $this->string($display, 255),
            ColumnType::Text => $this->string($display, null),
            ColumnType::Integer => $this->integer($raw, $display),
            ColumnType::Decimal => $this->decimal($raw, $display),
            ColumnType::Date => $this->date($raw, $display, false),
            ColumnType::DateTime => $this->date($raw, $display, true),
            ColumnType::Boolean => $this->boolean($raw, $display),
            ColumnType::ImageUrl => $this->imageUrl($formula, $display),
        };
    }

    private function string(string $value, ?int $max): NormalizedValue
    {
        if ($value === '') {
            return NormalizedValue::of(null);
        }

        if ($max !== null && mb_strlen($value) > $max) {
            return NormalizedValue::invalid('longer than '.$max.' characters (use the Text type)');
        }

        return NormalizedValue::of($value);
    }

    private function integer(mixed $raw, string $display): NormalizedValue
    {
        $decimal = $this->decimal($raw, $display);

        if (! $decimal->ok || $decimal->value === null) {
            return $decimal->ok ? $decimal : NormalizedValue::invalid('"'.$display.'" is not a whole number');
        }

        if (str_contains((string) $decimal->value, '.')) {
            return NormalizedValue::invalid('"'.$display.'" is not a whole number');
        }

        return NormalizedValue::of((int) $decimal->value);
    }

    private function decimal(mixed $raw, string $display): NormalizedValue
    {
        if (is_int($raw) || is_float($raw)) {
            return NormalizedValue::of($this->canonicalNumber((float) $raw));
        }

        $text = is_string($raw) && trim($raw) !== '' ? trim($raw) : $display;

        if ($text === '') {
            return NormalizedValue::of(null);
        }

        $number = $this->parseNumber($text);

        return $number === null
            ? NormalizedValue::invalid('"'.$text.'" is not a number')
            : NormalizedValue::of($this->canonicalNumber($number));
    }

    /**
     * Parses "€1,250.50", "1.250,50", "1 250", "-3.5" or "(42)" into a float.
     */
    public function parseNumber(string $text): ?float
    {
        $negative = str_starts_with($text, '(') && str_ends_with($text, ')');
        $clean = (string) preg_replace('/[^\d.,\-]/u', '', $text);

        if ($clean === '' || ! preg_match('/\d/', $clean)) {
            return null;
        }

        if (preg_match('/[^\d.,\-\s€$£¥%()+]|[a-z]/iu', preg_replace('/\s+/u', '', $text))) {
            return null;
        }

        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $clean = $lastComma > $lastDot
                ? str_replace(['.', ','], ['', '.'], $clean)
                : str_replace(',', '', $clean);
        } elseif ($lastComma !== false) {
            $clean = preg_match('/^-?\d{1,3}(,\d{3})+$/', $clean) ? str_replace(',', '', $clean) : str_replace(',', '.', $clean);
        }

        if (! is_numeric($clean)) {
            return null;
        }

        $value = (float) $clean;

        if (str_ends_with(trim($text), '%')) {
            $value /= 100;
        }

        return $negative ? -abs($value) : $value;
    }

    /**
     * Canonical decimal text: fixed scale, trailing zeros removed ("10.00" → "10", "10.50" → "10.5").
     */
    public function canonicalNumber(float $value): string
    {
        $text = number_format($value, self::DECIMAL_SCALE, '.', '');

        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '-0' ? '0' : $text;
    }

    private function date(mixed $raw, string $display, bool $withTime): NormalizedValue
    {
        $format = $withTime ? 'Y-m-d H:i:s' : 'Y-m-d';

        if (is_int($raw) || is_float($raw)) {
            // Google serial number: days since 1899-12-30, fraction = time of day.
            $seconds = (int) round(((float) $raw - 25569) * 86400);

            return NormalizedValue::of(CarbonImmutable::createFromTimestampUTC($seconds)->format($format));
        }

        $text = trim(is_string($raw) && $raw !== '' ? $raw : $display);

        if ($text === '') {
            return NormalizedValue::of(null);
        }

        $formats = $withTime
            ? ['Y-m-d H:i:s', 'Y-m-d H:i', 'd.m.Y H:i', 'd/m/Y H:i', 'Y-m-d\TH:i:s', 'd.m.Y H:i:s', 'd/m/Y H:i:s']
            : ['Y-m-d', 'd.m.Y', 'j.n.Y', 'd/m/Y', 'j/n/Y', 'j M Y', 'M j, Y', 'F j, Y', 'j F Y'];

        foreach ($formats as $candidate) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$candidate, $text, 'UTC');

                if ($date !== null && $date->format($candidate) === $text) {
                    return NormalizedValue::of($date->format($format));
                }
            } catch (Throwable) {
                continue;
            }
        }

        return NormalizedValue::invalid('"'.$text.'" is not a recognised '.($withTime ? 'date and time' : 'date'));
    }

    private function boolean(mixed $raw, string $display): NormalizedValue
    {
        if (is_bool($raw)) {
            return NormalizedValue::of($raw ? 1 : 0);
        }

        $text = mb_strtolower(trim(is_scalar($raw) && (string) $raw !== '' ? (string) $raw : $display));

        return match (true) {
            in_array($text, ['true', 'yes', 'y', '1', 'x', '✓', '✔'], true) => NormalizedValue::of(1),
            in_array($text, ['false', 'no', 'n', '0', ''], true) => NormalizedValue::of(0),
            default => NormalizedValue::invalid('"'.$text.'" is not yes/no'),
        };
    }

    private function imageUrl(?string $formula, string $display): NormalizedValue
    {
        if ($formula !== null && preg_match('/^=\s*IMAGE\s*\(\s*"([^"]+)"/i', $formula, $match)) {
            return NormalizedValue::of($match[1]);
        }

        if (filter_var($display, FILTER_VALIDATE_URL)) {
            return NormalizedValue::of($display);
        }

        return NormalizedValue::of(null);
    }
}
