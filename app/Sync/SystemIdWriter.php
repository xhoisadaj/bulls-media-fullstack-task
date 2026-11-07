<?php

namespace App\Sync;

use App\Enums\AlertType;
use App\Enums\ColumnType;
use App\Google\A1;
use App\Google\Contracts\SheetsGateway;
use App\Google\Exceptions\GoogleAccessException;
use App\Models\SourceColumn;
use App\Models\SourceTable;
use App\Models\StructureAlert;
use App\Sync\Data\SheetRow;
use App\Sync\Data\TableRead;
use Illuminate\Support\Str;

/**
 * System ID mode: makes sure every row in the sheet carries a _sync_id. The only code that
 * changes the user's sheet. IDs are written right after the read, while row positions are fresh.
 */
class SystemIdWriter
{
    public function __construct(
        private readonly SheetsGateway $gateway,
        private readonly Fingerprinter $fingerprinter,
    ) {}

    /**
     * Adds the _sync_id header when missing. Returns true when the header was created
     * (the caller should read the tab again).
     */
    public function ensureHeader(SourceTable $table, TableRead $read, string $tabTitle): bool
    {
        if ($read->located->columnOf(SheetReader::SYSTEM_ID_HEADER) !== null) {
            $this->ensureColumn($table, $read->located->columnOf(SheetReader::SYSTEM_ID_HEADER));

            return false;
        }

        if (! $this->gateway->canWrite()) {
            StructureAlert::raise(AlertType::SystemIdProblem, $table->label().': the _sync_id column can\'t be added without OAuth write access. Reconnect Google with write access.', $table);

            return false;
        }

        $column = $read->located->endColumn + 1;
        $this->gateway->writeCells($table->tab->spreadsheet->google_id, $tabTitle, [
            A1::cell($read->located->headerRow, $column) => SheetReader::SYSTEM_ID_HEADER,
        ]);

        $this->ensureColumn($table, $column);
        $table->update(['header_names' => array_values(array_unique([...$table->header_names, SheetReader::SYSTEM_ID_HEADER]))]);

        return true;
    }

    /**
     * Gives every row without an ID a new one. Rows that can't get an ID (no write access)
     * are held back and not synced, so we never store rows we can't track later.
     *
     * @return list<SheetRow>
     */
    public function assign(SourceTable $table, TableRead $read, string $tabTitle): array
    {
        $idColumn = $read->located->columnOf(SheetReader::SYSTEM_ID_HEADER);
        $sourceColumn = $table->columns()->where('header', SheetReader::SYSTEM_ID_HEADER)->first();
        $missing = array_filter($read->rows, fn (SheetRow $row) => $row->identity === null);

        if ($missing === [] || $idColumn === null || $sourceColumn === null) {
            return $idColumn === null ? [] : $read->rows;
        }

        if (! $this->gateway->canWrite()) {
            StructureAlert::raise(
                AlertType::SystemIdProblem,
                $table->label().': '.count($missing).' new row(s) are waiting for write access to get an ID. They are held until OAuth with write access is back.',
                $table,
            );

            return array_values(array_filter($read->rows, fn (SheetRow $row) => $row->identity !== null));
        }

        // Records stored before system ID mode was switched on get the new IDs, matched by content.
        $unlabelled = $table->records()->whereNull('identity_value')->get()->groupBy('fingerprint');
        $cells = [];
        $rows = [];

        foreach ($read->rows as $row) {
            if ($row->identity !== null) {
                $rows[] = $row;

                continue;
            }

            $id = Str::lower(Str::random(10));
            $cells[A1::cell($row->sheetRow - 1, $idColumn)] = $id;
            $values = $row->values;
            $values[$sourceColumn->id] = $id;

            $previous = $unlabelled->get($row->fingerprint)?->shift();
            $previous?->update(['identity_value' => $id, 'data' => array_replace($previous->data, [(string) $sourceColumn->id => $id])]);

            $rows[] = new SheetRow($row->sheetRow, $values, $row->fingerprint, $id);
        }

        try {
            $this->gateway->writeCells($table->tab->spreadsheet->google_id, $tabTitle, $cells);
        } catch (GoogleAccessException $e) {
            StructureAlert::raise(AlertType::SystemIdProblem, $table->label().': writing IDs failed: '.$e->getMessage(), $table);

            return array_values(array_filter($read->rows, fn (SheetRow $row) => $row->identity !== null));
        }

        StructureAlert::resolve(AlertType::SystemIdProblem, $table);

        return $rows;
    }

    private function ensureColumn(SourceTable $table, int $position): void
    {
        $column = SourceColumn::query()->firstOrCreate(
            ['source_table_id' => $table->id, 'header' => SheetReader::SYSTEM_ID_HEADER],
            ['type' => ColumnType::String, 'compare' => false, 'position' => $position + 1],
        );

        if (($table->key_column_ids ?? []) !== [$column->id]) {
            $table->update(['key_column_ids' => [$column->id]]);
        }
    }
}
