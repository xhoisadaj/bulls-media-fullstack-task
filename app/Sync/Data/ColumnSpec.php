<?php

namespace App\Sync\Data;

use App\Enums\ColumnType;
use App\Sync\HeaderName;

/**
 * A configured source column, as the pure services need it.
 */
final readonly class ColumnSpec
{
    public function __construct(
        public int $id,
        public string $header,
        public ColumnType $type,
        public bool $tracked = false,
        public bool $isKey = false,
        public bool $mapped = false,
    ) {}

    public function normalizedHeader(): string
    {
        return HeaderName::normalize($this->header);
    }

    /**
     * Columns whose absence stops the sync for the table.
     */
    public function isRequired(): bool
    {
        return $this->mapped || $this->isKey;
    }
}
