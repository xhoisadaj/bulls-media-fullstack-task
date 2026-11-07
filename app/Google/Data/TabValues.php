<?php

namespace App\Google\Data;

/**
 * One tab's cells, starting at A1. `raw` holds unformatted values (numbers, booleans,
 * date serial numbers); `formatted` holds the text people see. Both are 0-indexed grids.
 */
final readonly class TabValues
{
    /**
     * @param  list<list<mixed>>  $raw
     * @param  list<list<mixed>>  $formatted
     * @param  list<list<mixed>>|null  $formulas
     */
    public function __construct(
        public array $raw,
        public array $formatted,
        public ?array $formulas = null,
    ) {}

    public function rawAt(int $row, int $column): mixed
    {
        return $this->raw[$row][$column] ?? null;
    }

    public function formattedAt(int $row, int $column): string
    {
        $value = $this->formatted[$row][$column] ?? '';

        return is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : (string) $value;
    }

    public function formulaAt(int $row, int $column): ?string
    {
        $value = $this->formulas[$row][$column] ?? null;

        return is_string($value) ? $value : null;
    }

    public function rowCount(): int
    {
        return max(count($this->raw), count($this->formatted));
    }

    public function withFormulas(array $formulas): self
    {
        return new self($this->raw, $this->formatted, $formulas);
    }
}
