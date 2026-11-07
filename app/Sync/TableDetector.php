<?php

namespace App\Sync;

use App\Enums\EndRule;
use App\Sync\Data\DetectedTable;
use App\Sync\Data\TableSpec;

/**
 * Proposes tables in a tab: blocks whose first row looks like a header (all text,
 * no duplicates, at least two cells) with at least one data row under it.
 */
class TableDetector
{
    public function __construct(private readonly TableLocator $locator) {}

    /**
     * @param  list<list<mixed>>  $grid  displayed values
     * @return list<DetectedTable>
     */
    public function detect(array $grid, int $sampleSize = 5): array
    {
        $tables = [];
        $claimed = [];

        foreach ($grid as $rowIndex => $row) {
            foreach ($this->locator->segments($grid, $rowIndex) as [$start, $end]) {
                if ($this->isClaimed($claimed, $rowIndex, $start, $end)) {
                    continue;
                }

                $headers = [];

                for ($c = $start; $c <= $end; $c++) {
                    $headers[] = trim((string) ($row[$c] ?? ''));
                }

                $named = array_values(array_filter($headers, fn (string $h) => $h !== ''));

                if (count($named) < 2 || ! $this->looksLikeHeader($named)) {
                    continue;
                }

                $located = $this->locator->locate($grid, new TableSpec(
                    headerNames: array_map(HeaderName::normalize(...), $named),
                    headerRowHint: $rowIndex,
                    startColumnHint: $start,
                    endRule: EndRule::FirstEmptyRow,
                ));

                if (! $located->found || $located->headerRow !== $rowIndex || $located->dataRows === []) {
                    continue;
                }

                $samples = [];

                foreach (array_slice($located->dataRows, 0, $sampleSize) as $r) {
                    $sample = [];

                    for ($c = $start; $c <= $end; $c++) {
                        $sample[] = trim((string) ($grid[$r][$c] ?? ''));
                    }

                    $samples[] = $sample;
                }

                $tables[] = new DetectedTable($rowIndex, $start, $end, $headers, count($located->dataRows), $samples);
                $claimed[] = [$rowIndex, max($located->dataRows), $start, $end];
            }
        }

        return $tables;
    }

    /**
     * @param  list<string>  $headers
     */
    private function looksLikeHeader(array $headers): bool
    {
        $normalized = array_map(HeaderName::normalize(...), $headers);

        if (count(array_unique($normalized)) !== count($normalized)) {
            return false;
        }

        $numeric = array_filter($headers, fn (string $h) => is_numeric(str_replace([',', ' '], '', $h)));

        return count($numeric) * 2 < count($headers);
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int, 3: int}>  $claimed
     */
    private function isClaimed(array $claimed, int $row, int $start, int $end): bool
    {
        foreach ($claimed as [$top, $bottom, $left, $right]) {
            if ($row >= $top && $row <= $bottom && $start <= $right && $end >= $left) {
                return true;
            }
        }

        return false;
    }
}
