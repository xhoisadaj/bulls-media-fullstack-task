<?php

namespace App\Sync;

use App\Enums\ChangeStatus;
use App\Enums\ChangeType;
use App\Models\PendingChange;
use App\Models\SourceRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Applies one approved change to the source record and every target table, in one
 * transaction. Used by the sync (auto mode) and by the Approve button.
 */
class ChangeApplier
{
    public function __construct(private readonly TargetWriter $writer) {}

    /**
     * @return bool false when the change is no longer pending (already decided or superseded)
     */
    public function approve(PendingChange $change, ?User $user): bool
    {
        return DB::transaction(function () use ($change, $user) {
            $fresh = PendingChange::query()->lockForUpdate()->find($change->id);

            if ($fresh === null || $fresh->status !== ChangeStatus::Pending) {
                return false;
            }

            $table = $fresh->sourceTable;

            match ($fresh->type) {
                ChangeType::New => $this->insert($fresh, $table->hash_version),
                ChangeType::Modified, ChangeType::Restored => $this->update($fresh, $table->hash_version),
                ChangeType::Removed => $this->remove($fresh),
            };

            $fresh->update([
                'status' => ChangeStatus::Approved,
                'decided_by' => $user?->id,
                'decided_at' => now(),
            ]);

            $change->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });
    }

    public function reject(PendingChange $change, User $user): bool
    {
        return DB::transaction(function () use ($change, $user) {
            $fresh = PendingChange::query()->lockForUpdate()->find($change->id);

            if ($fresh === null || $fresh->status !== ChangeStatus::Pending) {
                return false;
            }

            if ($fresh->type === ChangeType::Removed && $fresh->sourceRecord) {
                $fresh->sourceRecord->update(['keep_on_removal' => true]);
            }

            $fresh->update([
                'status' => ChangeStatus::Rejected,
                'decided_by' => $user->id,
                'decided_at' => now(),
            ]);

            return true;
        });
    }

    private function insert(PendingChange $change, ?string $hashVersion): void
    {
        $record = SourceRecord::query()->create([
            'source_table_id' => $change->source_table_id,
            'data' => $change->new,
            'fingerprint' => $change->fingerprint,
            'hash_version' => $hashVersion,
            'identity_value' => $change->identity_value,
            'sheet_row' => $change->sheet_row,
            'last_seen_at' => now(),
        ]);

        $change->source_record_id = $record->id;
        $change->save();

        $this->writer->upsertRecord($record);
    }

    private function update(PendingChange $change, ?string $hashVersion): void
    {
        $record = $change->sourceRecord;

        if ($record === null) {
            $this->insert($change, $hashVersion);

            return;
        }

        $record->update([
            'data' => $change->new,
            'fingerprint' => $change->fingerprint,
            'hash_version' => $hashVersion,
            'identity_value' => $change->identity_value ?? $record->identity_value,
            'sheet_row' => $change->sheet_row,
            'removed_at' => null,
            'keep_on_removal' => false,
            'last_seen_at' => now(),
        ]);

        $this->writer->upsertRecord($record);
    }

    private function remove(PendingChange $change): void
    {
        $record = $change->sourceRecord;

        if ($record === null) {
            return;
        }

        $record->update(['removed_at' => now()]);
        $this->writer->markRemoved($record);
    }
}
