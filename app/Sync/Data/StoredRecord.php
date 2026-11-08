<?php

namespace App\Sync\Data;

/**
 * A source record as the matcher sees it.
 */
final readonly class StoredRecord
{
    public function __construct(
        public int $id,
        public string $fingerprint,
        public ?string $identity = null,
        public bool $removed = false,
        public ?int $sheetRow = null,
    ) {}
}
