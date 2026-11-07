<?php

namespace App\Sync\Data;

final readonly class StructureReport
{
    /**
     * @param  list<string>  $addedHeaders  headers in the sheet that are not configured (displayed form)
     * @param  list<ColumnSpec>  $missingRequired  mapped or key columns no longer in the sheet
     * @param  list<ColumnSpec>  $missingOptional  unmapped configured columns no longer in the sheet
     * @param  list<string>  $duplicateHeaders  configured headers that appear more than once
     * @param  list<int>  $unnamedColumnsWithData
     */
    public function __construct(
        public array $addedHeaders = [],
        public array $missingRequired = [],
        public array $missingOptional = [],
        public array $duplicateHeaders = [],
        public array $unnamedColumnsWithData = [],
    ) {}

    public function canSync(): bool
    {
        return $this->missingRequired === [] && $this->duplicateHeaders === [];
    }

    public function blockingReason(): ?string
    {
        if ($this->duplicateHeaders !== []) {
            return 'Header '.implode(', ', array_map(fn ($h) => '"'.$h.'"', $this->duplicateHeaders)).' appears more than once. Rename one in the sheet.';
        }

        if ($this->missingRequired !== []) {
            return 'Mapped column '.implode(', ', array_map(fn (ColumnSpec $c) => '"'.$c->header.'"', $this->missingRequired)).' is missing from the sheet.';
        }

        return null;
    }
}
