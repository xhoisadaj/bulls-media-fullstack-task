<?php

namespace App\Google;

/**
 * Helpers for A1 notation and tab names in ranges.
 */
final class A1
{
    /**
     * 0-based column index → letters (0 → A, 25 → Z, 26 → AA).
     */
    public static function column(int $index): string
    {
        $letters = '';
        $index++;

        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod).$letters;
            $index = intdiv($index - $mod, 26);
        }

        return $letters;
    }

    /**
     * 0-based row and column → "H99".
     */
    public static function cell(int $row, int $column): string
    {
        return self::column($column).($row + 1);
    }

    /**
     * Quotes a tab title for use in a range: Sales 2026 → 'Sales 2026'.
     */
    public static function tab(string $title): string
    {
        return "'".str_replace("'", "''", $title)."'";
    }

    /**
     * Extracts the spreadsheet ID from a Google Sheets URL, or returns the input when it already is an ID.
     */
    public static function spreadsheetIdFromUrl(string $input): ?string
    {
        $input = trim($input);

        if (preg_match('~/spreadsheets/d/([a-zA-Z0-9_-]{10,})~', $input, $match)) {
            return $match[1];
        }

        return preg_match('~^[a-zA-Z0-9_-]{10,}$~', $input) ? $input : null;
    }
}
