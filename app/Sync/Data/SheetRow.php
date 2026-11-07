<?php

namespace App\Sync\Data;

/**
 * One data row read from the sheet, after normalization.
 */
final readonly class SheetRow
{
    /**
     * @param  array<int, string|int|null>  $values  normalized values of every source column, keyed by column ID
     */
    public function __construct(
        public int $sheetRow,
        public array $values,
        public string $fingerprint,
        public ?string $identity = null,
    ) {}
}
