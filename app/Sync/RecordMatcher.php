<?php

namespace App\Sync;

use App\Enums\IdentityMode;
use App\Sync\Data\MatchResult;
use App\Sync\Data\SheetRow;
use App\Sync\Data\StoredRecord;

/**
 * Decides which sheet row is which stored record, using facts only:
 * - content mode: the fingerprint (identical rows matched by count),
 * - key / system ID mode: the key value.
 * A row's position in the sheet is never used for identity.
 */
class RecordMatcher
{
    /**
     * @param  list<SheetRow>  $rows
     * @param  list<StoredRecord>  $records
     */
    public function match(array $rows, array $records, IdentityMode $mode): MatchResult
    {
        return $mode === IdentityMode::Content
            ? $this->byContent($rows, $records)
            : $this->byKey($rows, $records, $mode);
    }

    /**
     * @param  list<SheetRow>  $rows
     * @param  list<StoredRecord>  $records
     */
    private function byContent(array $rows, array $records): MatchResult
    {
        $result = new MatchResult;
        $active = [];
        $removed = [];

        foreach ($records as $record) {
            if ($record->removed) {
                $removed[$record->fingerprint][] = $record->id;
            } else {
                $active[$record->fingerprint][] = $record->id;
            }
        }

        foreach ($rows as $row) {
            if (! empty($active[$row->fingerprint])) {
                $result->unchanged[] = ['record' => array_shift($active[$row->fingerprint]), 'row' => $row];
            } elseif (! empty($removed[$row->fingerprint])) {
                $result->restored[] = ['record' => array_shift($removed[$row->fingerprint]), 'row' => $row];
            } else {
                $result->new[] = $row;
            }
        }

        foreach ($active as $ids) {
            array_push($result->removed, ...$ids);
        }

        return $result;
    }

    /**
     * @param  list<SheetRow>  $rows
     * @param  list<StoredRecord>  $records
     */
    private function byKey(array $rows, array $records, IdentityMode $mode): MatchResult
    {
        $result = new MatchResult;
        $counts = [];

        foreach ($rows as $row) {
            if ($row->identity !== null) {
                $counts[$row->identity] = ($counts[$row->identity] ?? 0) + 1;
            }
        }

        $byIdentity = [];

        foreach ($records as $record) {
            if ($record->identity === null) {
                continue;
            }

            // Prefer the active record when an identity exists twice in storage.
            if (! isset($byIdentity[$record->identity]) || $byIdentity[$record->identity]->removed) {
                $byIdentity[$record->identity] = $record;
            }
        }

        $seen = [];

        foreach ($rows as $row) {
            if ($row->identity === null) {
                if ($mode === IdentityMode::SystemId) {
                    $result->new[] = $row;
                } else {
                    $result->flagged[] = ['row' => $row, 'reason' => 'empty key'];
                }

                continue;
            }

            if ($counts[$row->identity] > 1) {
                $result->flagged[] = ['row' => $row, 'reason' => 'duplicate key '.$row->identity];
                $seen[$row->identity] = true;

                continue;
            }

            $seen[$row->identity] = true;
            $record = $byIdentity[$row->identity] ?? null;

            if ($record === null) {
                $result->new[] = $row;
            } elseif ($record->removed) {
                $result->restored[] = ['record' => $record->id, 'row' => $row];
            } elseif ($record->fingerprint === $row->fingerprint) {
                $result->unchanged[] = ['record' => $record->id, 'row' => $row];
            } else {
                $result->modified[] = ['record' => $record->id, 'row' => $row];
            }
        }

        // Records whose key is gone from the sheet. Duplicated keys are uncertain, so never removed.
        foreach ($records as $record) {
            if (! $record->removed && ($record->identity === null || ! isset($seen[$record->identity]))) {
                if ($record->identity !== null && ($byIdentity[$record->identity] ?? null)?->id !== $record->id) {
                    continue;
                }

                $result->removed[] = $record->id;
            }
        }

        return $result;
    }
}
