<?php

namespace App\Sync\Data;

use App\Google\A1;

/**
 * A table proposed by auto-detection at setup (0-based positions).
 */
final readonly class DetectedTable
{
    /**
     * @param  list<string>  $headers  displayed header texts, left to right
     * @param  list<list<string>>  $sampleRows
     */
    public function __construct(
        public int $headerRow,
        public int $startColumn,
        public int $endColumn,
        public array $headers,
        public int $dataRowCount,
        public array $sampleRows,
    ) {}

    public function range(): string
    {
        return A1::cell($this->headerRow, $this->startColumn).':'.A1::column($this->endColumn);
    }
}
