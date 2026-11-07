<?php

namespace App\Sync;

use App\Enums\EndRule;
use App\Sync\Data\LocatedTable;
use App\Sync\Data\TableSpec;

/**
 * Finds a table inside a tab by its header names. Cell positions are only hints:
 * if the headers moved (rows inserted above, columns inserted to the left) we search for them.
 */
class TableLocator
{
    /**
     * @param  list<list<mixed>>  $grid  displayed values, 0-indexed from A1
     */
    public function locate(array $grid, TableSpec $spec): LocatedTable
    {
        $known = array_values(array_unique(array_filter($spec->headerNames)));

        if ($known === []) {
            return LocatedTable::notFound();
        }

        $candidates = [];

        foreach ($grid as $rowIndex => $row) {
            foreach ($this->segments($grid, $rowIndex) as [$start, $end]) {
                $matches = 0;

                for ($c = $start; $c <= $end; $c++) {
                    if (in_array(HeaderName::normalize($row[$c] ?? ''), $known, true)) {
                        $matches++;
                    }
                }

                // A block counts as this table when it holds at least half of the known headers.
                if ($matches > 0 && $matches * 2 >= count($known)) {
                    $candidates[] = [
                        'row' => $rowIndex,
                        'start' => $start,
                        'end' => $end,
                        'matches' => $matches,
                        'distance' => abs($rowIndex - $spec->headerRowHint) + abs($start - $spec->startColumnHint),
                    ];
                }
            }
        }

        if ($candidates === []) {
            return LocatedTable::notFound();
        }

        usort($candidates, fn (array $a, array $b) => [$b['matches'], $a['distance']] <=> [$a['matches'], $b['distance']]);
        $best = $candidates[0];

        if (isset($candidates[1])
            && $candidates[1]['matches'] === $best['matches']
            && $candidates[1]['distance'] === $best['distance']) {
            return LocatedTable::notFound(ambiguous: true);
        }

        $headers = [];

        for ($c = $best['start']; $c <= $best['end']; $c++) {
            $name = HeaderName::normalize($grid[$best['row']][$c] ?? '');

            if ($name !== '') {
                $headers[$name][] = $c;
            }
        }

        $dataRows = $this->dataRows($grid, $best['row'], $best['start'], $best['end'], $spec, array_keys($headers));

        $unnamed = [];

        for ($c = $best['start']; $c <= $best['end']; $c++) {
            if (HeaderName::normalize($grid[$best['row']][$c] ?? '') === '' && $this->columnHasData($grid, $c, $dataRows)) {
                $unnamed[] = $c;
            }
        }

        return new LocatedTable(
            found: true,
            headerRow: $best['row'],
            startColumn: $best['start'],
            endColumn: $best['end'],
            headers: $headers,
            dataRows: $dataRows,
            moved: $best['row'] !== $spec->headerRowHint || $best['start'] !== $spec->startColumnHint,
            unnamedColumnsWithData: $unnamed,
        );
    }

    /**
     * Splits a row into blocks of header cells. A block ends at an empty header cell whose
     * column is also empty below (a real gap between side-by-side tables). An empty header
     * with data under it stays inside the block, so it can be reported.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function segments(array $grid, int $rowIndex): array
    {
        $row = $grid[$rowIndex] ?? [];
        $segments = [];
        $start = null;
        $lastFilled = null;
        $width = count($row);
        $lastRow = count($grid) - 1;
        $below = $rowIndex < $lastRow ? range($rowIndex + 1, min($rowIndex + 50, $lastRow)) : [];

        for ($c = 0; $c < $width; $c++) {
            $filled = HeaderName::normalize($row[$c] ?? '') !== '';

            if ($filled) {
                $start ??= $c;
                $lastFilled = $c;

                continue;
            }

            if ($start !== null && ! $this->columnHasData($grid, $c, $below)) {
                $segments[] = [$start, $lastFilled];
                $start = null;
            }
        }

        if ($start !== null) {
            $segments[] = [$start, $lastFilled];
        }

        return $segments;
    }

    /**
     * @param  list<string>  $ownHeaders
     * @return list<int>
     */
    private function dataRows(array $grid, int $headerRow, int $start, int $end, TableSpec $spec, array $ownHeaders): array
    {
        $rows = [];
        $last = count($grid) - 1;

        if ($spec->endRule === EndRule::FixedRow && $spec->endRow !== null) {
            $last = min($last, $spec->endRow);
        }

        $stopHeaders = array_filter([...$spec->otherTableHeaders, $ownHeaders]);

        for ($r = $headerRow + 1; $r <= $last; $r++) {
            $cells = [];

            for ($c = $start; $c <= $end; $c++) {
                $cells[] = HeaderName::normalize($grid[$r][$c] ?? '');
            }

            $empty = array_filter($cells, fn (string $v) => $v !== '') === [];

            if ($empty) {
                if ($spec->endRule === EndRule::FirstEmptyRow) {
                    break;
                }

                continue;
            }

            if ($spec->endRule === EndRule::BeforeNextHeader && $this->looksLikeHeaderOf($grid[$r] ?? [], $stopHeaders)) {
                break;
            }

            $rows[] = $r;
        }

        return $rows;
    }

    /**
     * @param  list<mixed>  $row
     * @param  list<list<string>>  $headerSets
     */
    private function looksLikeHeaderOf(array $row, array $headerSets): bool
    {
        $values = array_values(array_filter(array_map(HeaderName::normalize(...), $row)));

        foreach ($headerSets as $headers) {
            $hits = count(array_intersect($values, $headers));

            if ($headers !== [] && $hits * 2 >= count($headers) && $hits >= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $rows
     */
    private function columnHasData(array $grid, int $column, array $rows): bool
    {
        foreach ($rows as $r) {
            if (HeaderName::normalize($grid[$r][$column] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }
}
