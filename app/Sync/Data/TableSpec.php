<?php

namespace App\Sync\Data;

use App\Enums\EndRule;

/**
 * What we know about a source table, used to find it again in a tab (all 0-based).
 */
final readonly class TableSpec
{
    /**
     * @param  list<string>  $headerNames  normalized header names
     * @param  list<list<string>>  $otherTableHeaders  normalized headers of other tables in the same tab
     */
    public function __construct(
        public array $headerNames,
        public int $headerRowHint,
        public int $startColumnHint,
        public EndRule $endRule = EndRule::FirstEmptyRow,
        public ?int $endRow = null,
        public array $otherTableHeaders = [],
    ) {}
}
