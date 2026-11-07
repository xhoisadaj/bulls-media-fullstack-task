<?php

namespace App\Sync;

use App\Enums\ChangeStatus;
use App\Enums\IdentityMode;
use App\Google\Exceptions\GoogleAccessException;
use App\Models\SourceRecord;
use App\Models\SourceTable;
use Illuminate\Support\Collection;

/**
 * Brings existing data up to date after a configuration change (new or remapped column,
 * key or identity change) without creating change entries: the admin asked for it.
 */
class Backfiller
{
    public function __construct(
        private readonly TabFetcher $fetcher,
        private readonly SheetReader $reader,
        private readonly Fingerprinter $fingerprinter,
        private readonly TargetWriter $writer,
    ) {}

    /**
     * @param  list<int>  $previousTrackedIds  tracked columns before the change
     * @return list<string> messages for the admin
     */
    public function afterConfigChange(SourceTable $table, array $previousTrackedIds, bool $refreshFromSheet = true): array
    {
        $messages = [];
        $superseded = $table->pendingChanges()->pending()->update(['status' => ChangeStatus::Superseded]);

        if ($superseded > 0) {
            $messages[] = $superseded.' pending change(s) were superseded; the next sync recalculates them with the new configuration.';
        }

        $currentTracked = $table->trackedColumns()->pluck('id')->all();
        $added = array_values(array_diff($currentTracked, $previousTrackedIds));

        if ($refreshFromSheet && $added !== [] && $table->records()->exists()) {
            $messages = [...$messages, ...$this->refreshFromSheet($table, $previousTrackedIds, $currentTracked)];
        }

        $this->rehash($table);
        $written = $this->writer->projectAll($table);

        if ($written > 0) {
            $messages[] = 'Wrote '.$written.' row(s) to the target table(s).';
        }

        return $messages;
    }

    /**
     * Recalculates fingerprints and identities from stored data with the current configuration.
     */
    public function rehash(SourceTable $table): void
    {
        $tracked = $table->trackedColumns()->pluck('id')->all();
        $version = $this->fingerprinter->hashVersion($tracked);
        $keyIds = $this->reader->keyIds($table, $table->columns()->get()->all());

        $table->records()->chunkById(500, function (Collection $records) use ($tracked, $version, $keyIds, $table) {
            foreach ($records as $record) {
                $record->fingerprint = $this->fingerprinter->fingerprint($record->data, $tracked);
                $record->hash_version = $version;

                if ($table->identity_mode !== IdentityMode::Content) {
                    $record->identity_value = $this->fingerprinter->identity($record->data, $keyIds) ?? $record->identity_value;
                } else {
                    $record->identity_value = null;
                }

                if ($record->isDirty()) {
                    $record->save();
                }
            }
        });

        $table->forceFill(['hash_version' => $version])->save();
    }

    /**
     * Fills values of newly tracked columns from the live sheet. Rows are matched with the
     * old configuration: by key, or by the old fingerprint in content mode. Rows edited since
     * the last sync don't match and are left to the normal sync.
     *
     * @param  list<int>  $previousTracked
     * @param  list<int>  $currentTracked
     * @return list<string>
     */
    private function refreshFromSheet(SourceTable $table, array $previousTracked, array $currentTracked): array
    {
        try {
            $values = $this->fetcher->fetch($table->tab, $this->fetcher->needsFormulas($table));
        } catch (GoogleAccessException $e) {
            return ['Could not read the sheet to fill the new column(s): '.$e->getMessage().' Values will be filled on the next sync.'];
        }

        $read = $this->reader->read($table, $values, trackedIds: $previousTracked);

        if (! $read->canSync()) {
            return ['Could not read the table to fill the new column(s): '.($read->report?->blockingReason() ?? 'table not found').'.'];
        }

        $records = $table->records()->get();
        $byIdentity = $records->whereNotNull('identity_value')->keyBy('identity_value');
        $byFingerprint = $records->groupBy('fingerprint')->map(fn (Collection $group) => $group->values()->all())->all();
        $refreshIds = array_diff(array_keys($read->rows[0]->values ?? []), $previousTracked);
        $filled = 0;

        foreach ($read->rows as $row) {
            /** @var SourceRecord|null $record */
            $record = $table->identity_mode === IdentityMode::Content
                ? (empty($byFingerprint[$row->fingerprint]) ? null : array_shift($byFingerprint[$row->fingerprint]))
                : ($row->identity !== null ? $byIdentity->get($row->identity) : null);

            if ($record === null) {
                continue;
            }

            $data = $record->data;

            foreach ($refreshIds as $columnId) {
                $data[(string) $columnId] = $row->values[$columnId] ?? null;
            }

            $record->update(['data' => $data]);
            $filled++;
        }

        $skipped = count($read->rows) - $filled;

        return array_values(array_filter([
            'Filled the new column(s) for '.$filled.' existing row(s).',
            $skipped > 0 ? $skipped.' row(s) changed since the last sync and will be handled by the next sync.' : null,
        ]));
    }
}
