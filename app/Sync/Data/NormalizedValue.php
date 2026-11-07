<?php

namespace App\Sync\Data;

final readonly class NormalizedValue
{
    private function __construct(
        public bool $ok,
        public string|int|null $value,
        public ?string $error = null,
        public bool $temporary = false,
    ) {}

    public static function of(string|int|null $value): self
    {
        return new self(true, $value);
    }

    public static function invalid(string $error): self
    {
        return new self(false, null, $error);
    }

    public static function loading(): self
    {
        return new self(false, null, 'still loading', true);
    }
}
