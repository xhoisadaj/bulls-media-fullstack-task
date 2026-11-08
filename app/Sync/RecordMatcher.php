<?php

namespace App\Sync;

use App\Enums\IdentityMode;
use App\Sync\Data\MatchResult;
use App\Sync\Data\SheetRow;
use App\Sync\Data\StoredRecord;

/**
 * Decides which sheet row is which stored record, using facts only:
 * - content mode: the fingerprint (identical rows matched in sheet order),
 * - system ID mode: the fingerprint, then position pairing of what is left over,
 * - key mode: the key value.
 */
class RecordMatcher
{
    public const BY_POSITION = 'position';

    /**
     * @param  list<SheetRow>  $rows
     * @param  list<StoredRecord>  $records
     */
    public function match(array $rows, array $records, IdentityMode $mode): MatchResult
    {
        return match ($mode) {
            IdentityMode::Key => $this->byKey($rows, $records),
            IdentityMode::Content => $this->byContent($rows, $records),
            IdentityMode::SystemId => $this->pairByPosition($this->byContent($rows, $records), $records),
        };
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

        // Identical rows are handed out in sheet order, so duplicates keep their own IDs.
        usort($records, fn (StoredRecord $a, StoredRecord $b) => [$a->sheetRow ?? PHP_INT_MAX, $a->id] <=> [$b->sheetRow ?? PHP_INT_MAX, $b->id]);
        usort($rows, fn (SheetRow $a, SheetRow $b) => $a->sheetRow <=> $b->sheetRow);

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

        sort($result->removed);

        return $result;
    }

    /**
     * System ID mode: a record that disappeared and a row that appeared are the same row when
     * they sit in the same place, between the same unchanged neighbours (rows above and below).
     * Inserting or deleting other rows doesn't break this; sorting does, and then nothing is paired.
     * When a gap holds a different number of disappeared and appeared rows, nothing is paired either.
     *
     * @param  list<StoredRecord>  $records
     */
    private function pairByPosition(MatchResult $result, array $records): MatchResult
    {
        if ($result->removed === [] || $result->new === []) {
            return $result;
        }

        $byId = [];

        foreach ($records as $record) {
            $byId[$record->id] = $record;
        }

        // Anchors: unchanged rows, in their old order (by stored row) and their new order (by sheet row).
        $oldOrder = [];
        $newOrder = [];

        foreach ($result->unchanged as $pair) {
            if ($byId[$pair['record']]->sheetRow !== null) {
                $oldOrder[$pair['record']] = $byId[$pair['record']]->sheetRow;
            }

            $newOrder[$pair['record']] = $pair['row']->sheetRow;
        }

        $gone = [];

        foreach ($result->removed as $id) {
            $row = $byId[$id]->sheetRow;

            if ($row !== null) {
                $gone[$this->gap($oldOrder, $row)][] = $id;
            }
        }

        $appeared = [];

        foreach ($result->new as $index => $row) {
            $appeared[$this->gap($newOrder, $row->sheetRow)][] = $index;
        }

        $pairedRecords = [];
        $pairedRows = [];

        foreach ($gone as $gap => $ids) {
            $indexes = $appeared[$gap] ?? [];

            if (count($indexes) !== count($ids)) {
                continue;
            }

            usort($ids, fn (int $a, int $b) => $byId[$a]->sheetRow <=> $byId[$b]->sheetRow);
            usort($indexes, fn (int $a, int $b) => $result->new[$a]->sheetRow <=> $result->new[$b]->sheetRow);

            foreach ($ids as $i => $id) {
                $result->modified[] = ['record' => $id, 'row' => $result->new[$indexes[$i]], 'by' => self::BY_POSITION];
                $pairedRecords[$id] = true;
                $pairedRows[$indexes[$i]] = true;
            }
        }

        $result->removed = array_values(array_filter($result->removed, fn (int $id) => ! isset($pairedRecords[$id])));
        $result->new = array_values(array_filter($result->new, fn (int $index) => ! isset($pairedRows[$index]), ARRAY_FILTER_USE_KEY));

        return $result;
    }

    /**
     * The unchanged neighbours around a row position, as "above|below" record IDs.
     *
     * @param  array<int, int>  $anchors  record ID => row position
     */
    private function gap(array $anchors, int $row): string
    {
        $above = null;
        $below = null;

        foreach ($anchors as $id => $position) {
            if ($position < $row && ($above === null || $position > $anchors[$above])) {
                $above = $id;
            }

            if ($position > $row && ($below === null || $position < $anchors[$below])) {
                $below = $id;
            }
        }

        return ($above ?? 'top').'|'.($below ?? 'bottom');
    }

    /**
     * @param  list<SheetRow>  $rows
     * @param  list<StoredRecord>  $records
     */
    private function byKey(array $rows, array $records): MatchResult
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
                $result->flagged[] = ['row' => $row, 'reason' => 'empty key'];

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
