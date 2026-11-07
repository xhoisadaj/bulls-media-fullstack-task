<?php

namespace App\Google\Data;

final readonly class DriveFile
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $modifiedTime = null,
    ) {}
}
