<?php

namespace App\Sync;

use App\Enums\AlertType;
use App\Enums\CredentialType;
use App\Enums\SyncStatus;
use App\Enums\TriggerType;
use App\Google\A1;
use App\Google\Contracts\SheetsGateway;
use App\Google\Data\TabValues;
use App\Google\Exceptions\GoogleAccessException;
use App\Models\GoogleSetting;
use App\Models\SourceColumn;
use App\Models\SourceRecord;
use App\Models\SourceTable;
use App\Models\Spreadsheet;
use App\Models\StructureAlert;
use App\Models\SyncRun;
use App\Sync\Data\StoredRecord;
use App\Sync\Data\TableRead;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the whole sync for one spreadsheet. Each source table is processed on its own:
 * a broken table never stops the others.
 */
class SyncRunner
{
    public function __construct(
        private readonly SheetsGateway $gateway,
        private readonly SheetReader $reader,
        private readonly RecordMatcher $matcher,
        private readonly ChangeSetBuilder $builder,
        private readonly Backfiller $backfiller,
        private readonly TypeGuesser $typeGuesser,
        private readonly Fingerprinter $fingerprinter,
        private readonly SyncNotifier $notifier,
    ) {}

    public function run(Spreadsheet $spreadsheet, TriggerType $trigger = TriggerType::Manual, bool $allowMassRemoval = false): SyncRun
    {
        $lock = Cache::lock('sync-spreadsheet-'.$spreadsheet->id, 900);

        if (! $lock->get()) {
            return SyncRun::query()->create([
                'spreadsheet_id' => $spreadsheet->id,
                'trigger' => $trigger,
                'status' => SyncStatus::Skipped,
                'errors' => [['message' => 'Already syncing; this run was skipped.']],
                'started_at' => now(),
                'finished_at' => now(),
            ]);
        }

        $run = SyncRun::query()->create([
            'spreadsheet_id' => $spreadsheet->id,
            'trigger' => $trigger,
            'status' => SyncStatus::Running,
            'started_at' => now(),
        ]);

        $errors = [];
        $counts = [];

        try {
            $this->sync($spreadsheet, $run, $allowMassRemoval, $errors, $counts);
        } catch (GoogleAccessException $e) {
            $errors[] = ['message' => $e->getMessage()];
            $run->status = SyncStatus::Failed;
            StructureAlert::raise(AlertType::FileNotAccessible, '"'.$spreadsheet->name.'": '.$e->getMessage(), spreadsheet: $spreadsheet);
        } catch (Throwable $e) {
            report($e);
            $errors[] = ['message' => 'Unexpected error: '.$e->getMessage()];
            $run->status = SyncStatus::Failed;
        } finally {
            $lock->release();
        }

        $run->forceFill([
            'status' => $run->status === SyncStatus::Running ? SyncStatus::Success : $run->status,
            'counts' => $counts,
            'errors' => array_slice($errors, 0, (int) config('sync.max_logged_problems')),
            'credential_used' => $run->credential_used ?? $this->gateway->lastCredential(),
            'finished_at' => now(),
        ])->save();

        $spreadsheet->forceFill(['last_synced_at' => now(), 'last_sync_status' => $run->status])->save();
        $this->notifier->afterRun($run);

        return $run;
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @param  array<string, int>  $counts
     */
    private function sync(Spreadsheet $spreadsheet, SyncRun $run, bool $allowMassRemoval, array &$errors, array &$counts): void
    {
        $meta = $this->gateway->getSpreadsheet($spreadsheet->google_id);
        StructureAlert::resolve(AlertType::FileNotAccessible, spreadsheet: $spreadsheet);
        $this->noteCredential($run);

        $titles = [];

        foreach ($spreadsheet->tabs as $tab) {
            $tabMeta = $meta->tabById($tab->google_tab_id);
            $tab->update(['name' => $tabMeta?->title ?? $tab->name, 'is_present' => $tabMeta !== null]);

            if ($tabMeta === null && $tab->is_active) {
                StructureAlert::raise(AlertType::TabMissing, 'Tab "'.$tab->name.'" no longer exists in "'.$spreadsheet->name.'".', spreadsheet: $spreadsheet);
            } elseif ($tabMeta !== null) {
                $titles[$tab->id] = $tabMeta->title;
            }
        }

        $tables = SourceTable::query()
            ->active()
            ->whereHas('tab', fn ($q) => $q->where('spreadsheet_id', $spreadsheet->id)->where('is_active', true))
            ->with('tab')
            ->get()
            ->filter(fn (SourceTable $t) => isset($titles[$t->tab_id]));

        if ($tables->isEmpty()) {
            $errors[] = ['message' => 'No active tables to sync. Enable a tab and set up a table first.'];

            return;
        }

        $values = $this->gateway->readTabs($spreadsheet->google_id, array_values(array_unique(array_intersect_key($titles, $tables->pluck('tab_id', 'tab_id')->all()))));

        $failedTables = 0;

        foreach ($tables as $table) {
            $tabTitle = $titles[$table->tab_id];

            try {
                $tableValues = $values[$tabTitle] ?? new TabValues([], []);
                $result = $this->syncTable($table, $tableValues, $tabTitle, $run, $allowMassRemoval, $errors, $tables);

                foreach ($result as $key => $value) {
                    $counts[$key] = ($counts[$key] ?? 0) + $value;
                }

                if ($result === []) {
                    $failedTables++;
                }
            } catch (Throwable $e) {
                report($e);
                $failedTables++;
                $errors[] = ['table' => $table->name, 'message' => $e->getMessage()];
            }
        }

        if ($failedTables > 0) {
            $run->status = $failedTables === $tables->count() ? SyncStatus::Failed : SyncStatus::Partial;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @return array<string, int> counts, or [] when the table could not be synced
     */
    private function syncTable(SourceTable $table, TabValues $values, string $tabTitle, SyncRun $run, bool $allowMassRemoval, array &$errors, $allTables): array
    {
        if (! $table->mappings()->where('is_active', true)->exists()) {
            $errors[] = ['table' => $table->name, 'message' => 'No mapping yet; table skipped.'];

            return [];
        }

        if ($table->columns()->where('type', 'image_url')->exists() && $values->formulas === null) {
            $values = $values->withFormulas($this->gateway->readFormulas($table->tab->spreadsheet->google_id, $tabTitle));
        }

        $currentVersion = $this->fingerprinter->hashVersion($table->trackedColumns()->pluck('id')->all());

        if ($table->hash_version !== $currentVersion) {
            $this->backfiller->rehash($table);
            $table->refresh();
        }

        $otherHeaders = $allTables
            ->filter(fn (SourceTable $other) => $other->tab_id === $table->tab_id && $other->id !== $table->id)
            ->map(fn (SourceTable $other) => array_map(HeaderName::normalize(...), $other->header_names ?? []))
            ->values()
            ->all();

        $read = $this->reader->read($table, $values, $otherHeaders);

        if (! $read->located->found) {
            $type = $read->located->ambiguous ? AlertType::TableAmbiguous : AlertType::TableNotFound;
            $message = $read->located->ambiguous
                ? 'Two tables with the same headers are equally close to the saved position; we won\'t guess which one is meant.'
                : 'The table\'s headers were not found in the tab.';
            $table->markBroken($message);
            StructureAlert::raise($type, $table->label().': '.$message, $table);
            $errors[] = ['table' => $table->name, 'message' => $message];

            return [];
        }

        StructureAlert::resolve(AlertType::TableNotFound, $table);
        StructureAlert::resolve(AlertType::TableAmbiguous, $table);
        $this->updatePosition($table, $read);

        if ($read->report->addedHeaders !== []) {
            $this->addDiscoveredColumns($table, $read);
            $read = $this->reader->read($table, $values, $otherHeaders);
        }

        $this->updateColumnPresence($table, $read);

        if (! $read->report->canSync()) {
            $reason = (string) $read->report->blockingReason();
            $table->markBroken($reason);
            $type = $read->report->duplicateHeaders !== [] ? AlertType::DuplicateHeader : AlertType::ColumnMissing;
            StructureAlert::raise($type, $table->label().': '.$reason, $table);
            $errors[] = ['table' => $table->name, 'message' => $reason];

            return [];
        }

        StructureAlert::resolve(AlertType::ColumnMissing, $table);
        StructureAlert::resolve(AlertType::DuplicateHeader, $table);

        if ($read->temporary) {
            $errors[] = ['table' => $table->name, 'message' => 'Cells still show "Loading..."; table skipped this run, nothing changed.'];

            return ['skipped_tables' => 1];
        }

        $table->markOk();

        foreach ($read->report->unnamedColumnsWithData as $column) {
            $errors[] = ['table' => $table->name, 'message' => 'Column '.A1::column($column).' has data but no header; it is ignored.'];
        }

        foreach ($read->problems as $problem) {
            $errors[] = ['table' => $table->name, 'row' => $problem['row'], 'column' => $problem['column'], 'message' => 'Row '.$problem['row'].', '.$problem['column'].': '.$problem['error'].' (row skipped)'];
        }

        $rows = $read->rows;

        $stored = $table->records()
            ->get(['id', 'fingerprint', 'identity_value', 'removed_at', 'sheet_row'])
            ->map(fn (SourceRecord $r) => new StoredRecord($r->id, $r->fingerprint, $r->identity_value, $r->removed_at !== null, $r->sheet_row))
            ->all();

        $match = $this->matcher->match($rows, $stored, $table->identity_mode);

        foreach ($match->flagged as $flag) {
            $errors[] = ['table' => $table->name, 'row' => $flag['row']->sheetRow, 'message' => 'Row '.$flag['row']->sheetRow.': '.$flag['reason'].' (not applied)'];
        }

        $outcome = $this->builder->build($table, $match, $run, $allowMassRemoval);

        if ($outcome->blocked) {
            $errors[] = ['table' => $table->name, 'message' => (string) $outcome->blockedReason];

            return [];
        }

        return $outcome->counts + ['problems' => count($read->problems), 'flagged' => count($match->flagged)];
    }

    private function updatePosition(SourceTable $table, TableRead $read): void
    {
        $row = $read->located->headerRow + 1;
        $column = $read->located->startColumn + 1;

        if ($read->located->moved) {
            StructureAlert::raise(
                AlertType::HeaderMoved,
                $table->label().': header moved from '.A1::cell($table->header_row - 1, $table->start_column - 1).' to '.A1::cell($row - 1, $column - 1).'.',
                $table,
            );
        }

        $table->forceFill([
            'header_row' => $row,
            'start_column' => $column,
            'end_column' => $read->located->endColumn + 1,
        ])->save();
    }

    /**
     * New headers become (unmapped) source columns, so their values are kept in the
     * records' data and ready if someone maps them later. They never change fingerprints.
     */
    private function addDiscoveredColumns(SourceTable $table, TableRead $read): void
    {
        foreach ($read->report->addedHeaders as $header) {
            $samples = $read->addedColumnSamples[$header] ?? ['raw' => [], 'formatted' => []];

            SourceColumn::query()->firstOrCreate(
                ['source_table_id' => $table->id, 'header' => $header],
                [
                    'type' => $this->typeGuesser->guess($samples['raw'] ?? [], $samples['formatted'] ?? []),
                    'compare' => true,
                    'position' => ($read->located->columnOf(HeaderName::normalize($header)) ?? 0) + 1,
                ],
            );

            StructureAlert::raise(
                AlertType::ColumnAdded,
                'New column "'.$header.'" found in '.$table->label().'. Map it to save its data.',
                $table,
                details: ['header' => $header],
            );
        }

        $table->update(['header_names' => array_values(array_unique([...$table->header_names, ...$read->report->addedHeaders]))]);
    }

    private function updateColumnPresence(SourceTable $table, TableRead $read): void
    {
        foreach ($table->columns()->get() as $column) {
            $position = $read->located->columnOf(HeaderName::normalize($column->header));
            $column->fill(['is_present' => $position !== null, 'position' => $position !== null ? $position + 1 : $column->position]);

            if ($column->isDirty()) {
                $column->save();
            }
        }
    }

    private function noteCredential(SyncRun $run): void
    {
        $used = $this->gateway->lastCredential();
        $run->credential_used = $used;

        $settings = GoogleSetting::current();
        $oauthConfigured = $settings->secret('refresh_token') !== null || $settings->hasOAuthClient();

        if ($used === CredentialType::ApiKey && $oauthConfigured) {
            StructureAlert::raise(AlertType::FallbackInUse, 'Syncing with the API key fallback: only public sheets can be read. Reconnect your Google account in Settings → Google connection.');
            Log::warning('Google sync is running on the API key fallback.');
        } elseif ($used === CredentialType::OAuth) {
            StructureAlert::resolve(AlertType::FallbackInUse);
        }
    }
}
