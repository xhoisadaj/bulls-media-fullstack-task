<?php

namespace App\Sync\Data;

/**
 * Where a source table was found in a tab. Rows and columns are 0-based grid indexes.
 */
final readonly class LocatedTable
{
    /**
     * @param  array<string, list<int>>  $headers  normalized header => column indexes (more than one = duplicate)
     * @param  list<int>  $dataRows  grid row indexes holding data
     * @param  list<int>  $unnamedColumnsWithData  column indexes with data but an empty header cell
     */
    public function __construct(
        public bool $found,
        public bool $ambiguous = false,
        public ?int $headerRow = null,
        public ?int $startColumn = null,
        public ?int $endColumn = null,
        public array $headers = [],
        public array $dataRows = [],
        public bool $moved = false,
        public array $unnamedColumnsWithData = [],
    ) {}

    public static function notFound(bool $ambiguous = false): self
    {
        return new self(found: false, ambiguous: $ambiguous);
    }

    public function columnOf(string $normalizedHeader): ?int
    {
        $columns = $this->headers[$normalizedHeader] ?? [];

        return count($columns) === 1 ? $columns[0] : null;
    }
}
