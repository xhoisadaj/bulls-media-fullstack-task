<?php

namespace App\Sync;

use App\Enums\IdentityMode;
use App\Google\Data\TabValues;
use App\Models\SourceColumn;
use App\Models\SourceTable;
use App\Sync\Data\ColumnSpec;
use App\Sync\Data\SheetRow;
use App\Sync\Data\TableRead;
use App\Sync\Data\TableSpec;

/**
 * Reads one source table out of a tab: locate → check columns → normalize → fingerprint.
 */
class SheetReader
{
    public function __construct(
        private readonly TableLocator $locator,
        private readonly StructureInspector $inspector,
        private readonly ValueNormalizer $normalizer,
        private readonly Fingerprinter $fingerprinter,
    ) {}

    /**
     * @param  list<list<string>>  $otherTableHeaders  normalized headers of other tables in the same tab
     * @param  list<int>|null  $trackedIds  override the tracked set (used by backfill to fingerprint with an old set)
     */
    public function read(SourceTable $table, TabValues $values, array $otherTableHeaders = [], ?array $trackedIds = null): TableRead
    {
        $located = $this->locator->locate($values->formatted, new TableSpec(
            headerNames: array_map(HeaderName::normalize(...), $table->header_names ?? []),
            headerRowHint: $table->header_row - 1,
            startColumnHint: $table->start_column - 1,
            endRule: $table->end_rule,
            endRow: $table->end_row !== null ? $table->end_row - 1 : null,
            otherTableHeaders: $otherTableHeaders,
        ));

        if (! $located->found) {
            return new TableRead($located);
        }

        $columns = $table->columns()->get();
        $trackedIds ??= $table->trackedColumns()->pluck('id')->all();
        $keyIds = $this->keyIds($table, $columns->all());
        $mappedIds = $table->mappings()->where('is_active', true)->with('columns')->get()
            ->flatMap(fn ($m) => $m->columns->pluck('source_column_id'))->unique()->all();

        $specs = $columns->map(fn (SourceColumn $c) => new ColumnSpec(
            id: $c->id,
            header: $c->header,
            type: $c->type,
            tracked: in_array($c->id, $trackedIds, true),
            isKey: in_array($c->id, $keyIds, true),
            mapped: in_array($c->id, $mappedIds, true),
        ))->all();

        $displayed = [];

        for ($c = $located->startColumn; $c <= $located->endColumn; $c++) {
            $text = trim($values->formattedAt($located->headerRow, $c));

            if ($text !== '') {
                $displayed[HeaderName::normalize($text)] = $text;
            }
        }

        $report = $this->inspector->inspect($located, $specs, $displayed);

        $addedSamples = [];

        foreach ($report->addedHeaders as $header) {
            $column = $located->columnOf(HeaderName::normalize($header));

            if ($column !== null) {
                foreach (array_slice($located->dataRows, 0, 20) as $r) {
                    $addedSamples[$header]['raw'][] = $values->rawAt($r, $column);
                    $addedSamples[$header]['formatted'][] = $values->formattedAt($r, $column);
                }
            }
        }

        if (! $report->canSync()) {
            return new TableRead($located, $report, addedColumnSamples: $addedSamples, displayedHeaders: $displayed);
        }

        $rows = [];
        $problems = [];

        foreach ($located->dataRows as $r) {
            $rowValues = [];
            $rowOk = true;

            foreach ($specs as $spec) {
                $column = $located->columnOf($spec->normalizedHeader());

                if ($column === null) {
                    $rowValues[$spec->id] = null;

                    continue;
                }

                $normalized = $this->normalizer->normalize(
                    $spec->type,
                    $values->rawAt($r, $column),
                    $values->formattedAt($r, $column),
                    $values->formulaAt($r, $column),
                );

                if ($normalized->temporary && ($spec->tracked || $spec->isKey)) {
                    return new TableRead($located, $report, temporary: true, displayedHeaders: $displayed);
                }

                if (! $normalized->ok) {
                    if ($spec->tracked || $spec->isKey) {
                        $problems[] = ['row' => $r + 1, 'column' => $spec->header, 'error' => (string) $normalized->error];
                        $rowOk = false;
                    }

                    $rowValues[$spec->id] = null;

                    continue;
                }

                $rowValues[$spec->id] = $normalized->value;
            }

            if (! $rowOk) {
                continue;
            }

            $identity = $table->identity_mode === IdentityMode::Key ? $this->fingerprinter->identity($rowValues, $keyIds) : null;

            $rows[] = new SheetRow($r + 1, $rowValues, $this->fingerprinter->fingerprint($rowValues, $trackedIds), $identity);
        }

        return new TableRead($located, $report, $rows, $problems, addedColumnSamples: $addedSamples, displayedHeaders: $displayed);
    }

    /**
     * @param  list<SourceColumn>  $columns
     * @return list<int>
     */
    public function keyIds(SourceTable $table, array $columns): array
    {
        return $table->identity_mode === IdentityMode::Key ? array_map('intval', $table->key_column_ids ?? []) : [];
    }
}
