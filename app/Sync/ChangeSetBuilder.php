<?php

namespace App\Sync;

use App\Enums\AlertType;
use App\Enums\ApplyMode;
use App\Enums\ChangeStatus;
use App\Enums\ChangeType;
use App\Models\PendingChange;
use App\Models\SourceRecord;
use App\Models\SourceTable;
use App\Models\StructureAlert;
use App\Models\SyncRun;
use App\Sync\Data\ChangeSetOutcome;
use App\Sync\Data\MatchResult;
use App\Sync\Data\SheetRow;
use Illuminate\Support\Collection;

/**
 * Turns match facts into decisions: mass-removal guard, rejection memory, superseding
 * outdated pending changes, and review vs auto. RecordMatcher answers "what's different?",
 * this answers "what do we do about it?".
 */
class ChangeSetBuilder
{
    public function __construct(private readonly ChangeApplier $applier) {}

    public function build(SourceTable $table, MatchResult $match, SyncRun $run, bool $allowMassRemoval = false): ChangeSetOutcome
    {
        $outcome = new ChangeSetOutcome;
        $records = $table->records()->get()->keyBy('id');
        $trackedIds = $table->trackedColumns()->pluck('id')->all();
        $this->unpairRejected($table, $match);

        $removable = collect($match->removed)->reject(fn (int $id) => $records[$id]?->keep_on_removal)->values();
        $activeCount = $records->whereNull('removed_at')->count();

        if (! $allowMassRemoval
            && $removable->count() >= config('sync.mass_removal_min_rows')
            && $removable->count() * 2 > $activeCount) {
            $outcome->blocked = true;
            $outcome->blockedReason = $removable->count().' of '.$activeCount.' rows would be removed. The sheet looks cleared or the wrong table was found. Nothing was changed; use "Sync anyway" if this is intended.';

            StructureAlert::raise(AlertType::MassRemoval, $table->label().': '.$outcome->blockedReason, $table, details: ['removed' => $removable->count(), 'active' => $activeCount]);

            return $outcome;
        }

        StructureAlert::resolve(AlertType::MassRemoval, $table);

        $this->refreshUnchanged($match, $records, $trackedIds);
        $outcome->counts['unchanged'] = count($match->unchanged);

        $desired = [];

        foreach ($match->new as $row) {
            $desired[] = $this->describe(ChangeType::New, null, $row, $trackedIds);
        }

        foreach ($match->modified as $pair) {
            $desired[] = $this->describe(ChangeType::Modified, $records[$pair['record']], $pair['row'], $trackedIds, $pair['by'] ?? null);
        }

        foreach ($match->restored as $pair) {
            $desired[] = $this->describe(ChangeType::Restored, $records[$pair['record']], $pair['row'], $trackedIds);
        }

        foreach ($removable as $recordId) {
            $desired[] = $this->describe(ChangeType::Removed, $records[$recordId], null, $trackedIds);
        }

        $desired = array_values(array_filter($desired, function (array $change) use ($table, $outcome) {
            if ($this->wasRejected($table, $change)) {
                $outcome->counts['ignored_rejected']++;

                return false;
            }

            return true;
        }));

        foreach ($desired as $change) {
            $outcome->counts[$change['type']->value]++;
        }

        $live = $this->reconcile($table, $run, $desired, $outcome);

        if ($table->apply_mode === ApplyMode::Auto) {
            foreach ($live as $change) {
                // A position pairing is a strong hint, not proof: it always waits for a human.
                if ($change->type->canAutoApply() && $change->matched_by === null && $this->applier->approve($change, null)) {
                    $outcome->counts['auto_applied']++;
                }
            }
        }

        return $outcome;
    }

    /**
     * A position pairing the admin rejected ("not the same row") falls back to removed + new,
     * so the row isn't stuck between the two.
     */
    private function unpairRejected(SourceTable $table, MatchResult $match): void
    {
        $match->modified = array_values(array_filter($match->modified, function (array $pair) use ($table, $match): bool {
            if (($pair['by'] ?? null) === null) {
                return true;
            }

            $rejected = PendingChange::query()
                ->where('source_table_id', $table->id)
                ->where('status', ChangeStatus::Rejected)
                ->where('type', ChangeType::Modified)
                ->where('source_record_id', $pair['record'])
                ->where('fingerprint', $pair['row']->fingerprint)
                ->exists();

            if ($rejected) {
                $match->removed[] = $pair['record'];
                $match->new[] = $pair['row'];
            }

            return ! $rejected;
        }));
    }

    /**
     * Updates row numbers, "last seen" and untracked values of rows that didn't change.
     *
     * @param  Collection<int, SourceRecord>  $records
     * @param  list<int>  $trackedIds
     */
    private function refreshUnchanged(MatchResult $match, Collection $records, array $trackedIds): void
    {
        $seenIds = [];

        foreach ($match->unchanged as $pair) {
            $record = $records[$pair['record']];
            $row = $pair['row'];
            $seenIds[] = $record->id;

            $data = $record->data;

            foreach ($row->values as $columnId => $value) {
                if (! in_array($columnId, $trackedIds, true)) {
                    $data[(string) $columnId] = $value;
                }
            }

            $record->sheet_row = $row->sheetRow;
            $record->data = $data;
            $record->keep_on_removal = false;

            if ($record->isDirty()) {
                $record->save();
            }
        }

        foreach (array_chunk($seenIds, 1000) as $chunk) {
            SourceRecord::query()->whereIn('id', $chunk)->update(['last_seen_at' => now()]);
        }
    }

    /**
     * @param  list<int>  $trackedIds
     * @return array{type: ChangeType, record: ?SourceRecord, old: ?array, new: ?array, changed: list<string>, fingerprint: ?string, identity: ?string, sheet_row: ?int, matched_by: ?string}
     */
    private function describe(ChangeType $type, ?SourceRecord $record, ?SheetRow $row, array $trackedIds, ?string $matchedBy = null): array
    {
        $new = null;

        if ($row !== null) {
            $new = [];

            foreach ($row->values as $columnId => $value) {
                $new[(string) $columnId] = $value;
            }
        }

        $old = $record?->data;
        $changed = [];

        if ($old !== null && $new !== null) {
            foreach ($trackedIds as $id) {
                if (($old[(string) $id] ?? null) !== ($new[(string) $id] ?? null)) {
                    $changed[] = (string) $id;
                }
            }
        }

        return [
            'type' => $type,
            'record' => $record,
            'old' => $old,
            'new' => $new,
            'changed' => $changed,
            'fingerprint' => $row?->fingerprint,
            'identity' => $row?->identity ?? $record?->identity_value,
            'sheet_row' => $row?->sheetRow ?? $record?->sheet_row,
            'matched_by' => $matchedBy,
        ];
    }

    private function wasRejected(SourceTable $table, array $change): bool
    {
        if ($change['type'] === ChangeType::Removed) {
            return (bool) $change['record']?->keep_on_removal;
        }

        return PendingChange::query()
            ->where('source_table_id', $table->id)
            ->where('status', ChangeStatus::Rejected)
            ->where('type', $change['type'])
            ->where('fingerprint', $change['fingerprint'])
            ->when($change['record'], fn ($q, $record) => $q->where('source_record_id', $record->id))
            ->when(! $change['record'], fn ($q) => $q->where('identity_value', $change['identity']))
            ->exists();
    }

    /**
     * Keeps identical pending changes, supersedes ones that no longer apply, creates the rest.
     *
     * @param  list<array<string, mixed>>  $desired
     * @return list<PendingChange> the pending changes that are live after this sync
     */
    private function reconcile(SourceTable $table, SyncRun $run, array $desired, ChangeSetOutcome $outcome): array
    {
        $existing = $table->pendingChanges()->pending()->get()->groupBy(fn (PendingChange $c) => $this->signature(
            $c->type, $c->source_record_id, $c->identity_value, $c->fingerprint,
        ));

        $live = [];

        foreach ($desired as $change) {
            $signature = $this->signature($change['type'], $change['record']?->id, $change['identity'], $change['fingerprint']);

            if (isset($existing[$signature]) && $existing[$signature]->isNotEmpty()) {
                $kept = $existing[$signature]->shift();
                $kept->update(['sheet_row' => $change['sheet_row']]);
                $live[] = $kept;

                continue;
            }

            $live[] = PendingChange::query()->create([
                'sync_run_id' => $run->id,
                'source_table_id' => $table->id,
                'source_record_id' => $change['record']?->id,
                'type' => $change['type'],
                'old' => $change['old'],
                'new' => $change['new'],
                'changed_columns' => $change['changed'],
                'fingerprint' => $change['fingerprint'],
                'identity_value' => $change['identity'],
                'sheet_row' => $change['sheet_row'],
                'matched_by' => $change['matched_by'],
                'status' => ChangeStatus::Pending,
            ]);
            $outcome->counts['pending_created']++;
        }

        foreach ($existing->flatten() as $stale) {
            $stale->update(['status' => ChangeStatus::Superseded]);
            $outcome->counts['superseded']++;
        }

        return $live;
    }

    private function signature(ChangeType $type, ?int $recordId, ?string $identity, ?string $fingerprint): string
    {
        return match ($type) {
            ChangeType::New => 'new|'.($identity ?? '').'|'.$fingerprint,
            ChangeType::Removed => 'removed|'.$recordId,
            default => $type->value.'|'.$recordId.'|'.$fingerprint,
        };
    }
}
