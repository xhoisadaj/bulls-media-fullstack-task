<?php

namespace App\Google\Data;

final readonly class TabMeta
{
    public function __construct(
        public int $id,
        public string $title,
        public int $rowCount = 0,
        public int $columnCount = 0,
    ) {}
}
