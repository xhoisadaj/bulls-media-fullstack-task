<?php

namespace App\Sync\Data;

/**
 * The result of reading one source table from a tab.
 */
final readonly class TableRead
{
    /**
     * @param  list<SheetRow>  $rows
     * @param  list<array{row: int, column: string, error: string}>  $problems
     * @param  array<string, array{raw: list<mixed>, formatted: list<string>}>  $addedColumnSamples  displayed header => samples
     */
    public function __construct(
        public LocatedTable $located,
        public ?StructureReport $report = null,
        public array $rows = [],
        public array $problems = [],
        public bool $temporary = false,
        public array $addedColumnSamples = [],
        public array $displayedHeaders = [],
    ) {}

    public function canSync(): bool
    {
        return $this->located->found && $this->report?->canSync() === true && ! $this->temporary;
    }
}
