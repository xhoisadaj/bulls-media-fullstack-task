<?php

namespace App\Sync;

use App\Enums\ColumnType;
use App\Enums\EndRule;
use App\Google\A1;
use App\Google\Data\TabValues;
use App\Google\Exceptions\GoogleAccessException;
use App\Models\Tab;
use App\Sync\Data\DetectedTable;
use App\Sync\Data\TableSpec;
use Illuminate\Support\Facades\Cache;

/**
 * Helpers for the "Set up a table" wizard: detect tables, suggest column types,
 * warn about volatile formulas and errors, and check that a key is unique.
 */
class TableSetup
{
    public function __construct(
        private readonly TabFetcher $fetcher,
        private readonly TableDetector $detector,
        private readonly TableLocator $locator,
        private readonly TypeGuesser $guesser,
        private readonly ValueNormalizer $normalizer,
    ) {}

    /**
     * @throws GoogleAccessException
     */
    public function values(Tab $tab, bool $fresh = false): TabValues
    {
        $key = 'table-setup-tab-'.$tab->id;

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 300, fn () => $this->fetcher->fetch($tab, withFormulas: true));
    }

    /**
     * @return list<DetectedTable>
     *
     * @throws GoogleAccessException
     */
    public function detect(Tab $tab): array
    {
        return $this->detector->detect($this->values($tab)->formatted);
    }

    /**
     * Columns of the table whose header row starts at the given 1-based position.
     *
     * @return list<array{header: string, type: string, compare: bool, warning: ?string, sample: string}>
     *
     * @throws GoogleAccessException
     */
    public function columns(Tab $tab, int $headerRow, int $startColumn): array
    {
        $values = $this->values($tab);
        $row = $values->formatted[$headerRow - 1] ?? [];
        $headers = $this->headersFrom($values, $headerRow, $startColumn);
        $located = $this->locator->locate($values->formatted, new TableSpec(
            array_map(HeaderName::normalize(...), array_keys($headers)), $headerRow - 1, $startColumn - 1, EndRule::EndOfSheet,
        ));

        $columns = [];

        foreach ($headers as $header => $column) {
            $raw = [];
            $formatted = [];
            $formulas = [];

            foreach (array_slice($located->dataRows, 0, 50) as $r) {
                $raw[] = $values->rawAt($r, $column);
                $formatted[] = $values->formattedAt($r, $column);
                $formulas[] = $values->formulaAt($r, $column);
            }

            $columns[] = [
                'header' => $header,
                'type' => $this->guesser->guess($raw, $formatted, $formulas)->value,
                'compare' => ! $this->hasVolatileFormula($formulas),
                'warning' => $this->warning($formatted, $formulas),
                'sample' => implode(', ', array_slice(array_filter($formatted, fn ($v) => $v !== ''), 0, 3)),
            ];
        }

        unset($row);

        return $columns;
    }

    /**
     * Rows that break the uniqueness of the chosen key, as messages. Empty when the key is unique.
     *
     * @param  list<string>  $keyHeaders
     * @param  array<string, string>  $types  header => column type value
     * @return list<string>
     *
     * @throws GoogleAccessException
     */
    public function keyProblems(Tab $tab, int $headerRow, int $startColumn, array $keyHeaders, array $types): array
    {
        $values = $this->values($tab, fresh: true);
        $headers = $this->headersFrom($values, $headerRow, $startColumn);
        $located = $this->locator->locate($values->formatted, new TableSpec(
            array_map(HeaderName::normalize(...), array_keys($headers)), $headerRow - 1, $startColumn - 1, EndRule::FirstEmptyRow,
        ));

        $seen = [];
        $problems = [];

        foreach ($located->dataRows as $r) {
            $parts = [];

            foreach ($keyHeaders as $header) {
                $column = $headers[$header] ?? null;
                $type = ColumnType::tryFrom($types[$header] ?? 'string') ?? ColumnType::String;
                $value = $column === null ? null : $this->normalizer->normalize($type, $values->rawAt($r, $column), $values->formattedAt($r, $column))->value;
                $parts[] = $value;
            }

            if (in_array(null, $parts, true) || in_array('', $parts, true)) {
                $problems[] = 'Row '.($r + 1).' has an empty key.';

                continue;
            }

            $key = implode(' + ', $parts);

            if (isset($seen[$key])) {
                $problems[] = 'Key "'.$key.'" appears in rows '.$seen[$key].' and '.($r + 1).'.';
            }

            $seen[$key] ??= $r + 1;
        }

        return array_slice($problems, 0, 10);
    }

    /**
     * @return array<string, int> displayed header => 0-based column, left to right, stopping at the block's end
     */
    private function headersFrom(TabValues $values, int $headerRow, int $startColumn): array
    {
        $segments = $this->locator->segments($values->formatted, $headerRow - 1);
        $end = $startColumn - 1;

        foreach ($segments as [$start, $segmentEnd]) {
            if ($start <= $startColumn - 1 && $segmentEnd >= $startColumn - 1) {
                $end = $segmentEnd;
            }
        }

        $headers = [];

        for ($c = $startColumn - 1; $c <= $end; $c++) {
            $text = trim($values->formattedAt($headerRow - 1, $c));

            if ($text !== '' && ! isset($headers[$text])) {
                $headers[$text] = $c;
            }
        }

        return $headers;
    }

    /**
     * @param  list<string|null>  $formulas
     */
    private function hasVolatileFormula(array $formulas): bool
    {
        foreach ($formulas as $formula) {
            if (is_string($formula) && preg_match('/\b(NOW|TODAY|RAND|RANDBETWEEN|RANDARRAY)\s*\(/i', $formula)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $formatted
     * @param  list<string|null>  $formulas
     */
    private function warning(array $formatted, array $formulas): ?string
    {
        if ($this->hasVolatileFormula($formulas)) {
            return 'Uses NOW()/TODAY()/RAND(): the value changes on every recalculation, so "compare" is off.';
        }

        foreach ($formatted as $i => $value) {
            if (in_array(strtoupper(trim($value)), ValueNormalizer::ERROR_VALUES, true)) {
                return 'Formula error '.$value.' in the sample rows; those rows will be skipped and reported.';
            }
        }

        if (collect($formulas)->contains(fn ($f) => is_string($f) && preg_match('/^=\s*IMAGE\s*\(/i', $f))) {
            return 'Contains =IMAGE() formulas: the image URL is stored.';
        }

        return null;
    }

    public static function position(int $headerRow, int $startColumn): string
    {
        return A1::cell($headerRow - 1, $startColumn - 1);
    }
}
