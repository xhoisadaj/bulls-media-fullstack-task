<?php

namespace App\Sync;

use App\Sync\Data\ColumnSpec;
use App\Sync\Data\LocatedTable;
use App\Sync\Data\StructureReport;

/**
 * Compares the headers found in the sheet with the configured source columns.
 */
class StructureInspector
{
    /**
     * @param  list<ColumnSpec>  $columns
     * @param  array<string, string>  $displayedHeaders  normalized => displayed text, for messages
     */
    public function inspect(LocatedTable $located, array $columns, array $displayedHeaders = []): StructureReport
    {
        $configured = [];

        foreach ($columns as $column) {
            $configured[$column->normalizedHeader()] = $column;
        }

        $added = [];

        foreach (array_keys($located->headers) as $header) {
            if (! isset($configured[$header])) {
                $added[] = $displayedHeaders[$header] ?? $header;
            }
        }

        $missingRequired = [];
        $missingOptional = [];
        $duplicates = [];

        foreach ($configured as $header => $column) {
            $positions = $located->headers[$header] ?? [];

            if ($positions === []) {
                if ($column->isRequired()) {
                    $missingRequired[] = $column;
                } else {
                    $missingOptional[] = $column;
                }
            } elseif (count($positions) > 1) {
                $duplicates[] = $column->header;
            }
        }

        return new StructureReport($added, $missingRequired, $missingOptional, $duplicates, $located->unnamedColumnsWithData);
    }
}
