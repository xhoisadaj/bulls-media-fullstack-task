<?php

namespace App\Sync;

/**
 * Hashes a row's tracked values. Same values give the same fingerprint, whatever row the
 * data is on. Values are JSON-encoded (not joined with a separator), so a value containing
 * the separator can't make two different rows look the same.
 */
class Fingerprinter
{
    /**
     * @param  array<int|string, mixed>  $values  normalized values keyed by source column ID
     * @param  list<int>  $trackedColumnIds
     */
    public function fingerprint(array $values, array $trackedColumnIds): string
    {
        $ids = $trackedColumnIds;
        sort($ids);

        $pairs = array_map(fn (int $id) => [$id, $values[$id] ?? $values[(string) $id] ?? null], $ids);

        return hash('sha256', json_encode($pairs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Identifies the set of tracked columns a fingerprint was built from.
     *
     * @param  list<int>  $trackedColumnIds
     */
    public function hashVersion(array $trackedColumnIds): string
    {
        $ids = $trackedColumnIds;
        sort($ids);

        return sha1('v1:'.implode(',', $ids));
    }

    /**
     * The key of a row in key mode, or null when any key column is empty.
     *
     * @param  array<int|string, mixed>  $values
     * @param  list<int>  $keyColumnIds
     */
    public function identity(array $values, array $keyColumnIds): ?string
    {
        if ($keyColumnIds === []) {
            return null;
        }

        $ids = $keyColumnIds;
        sort($ids);
        $parts = [];

        foreach ($ids as $id) {
            $value = $values[$id] ?? $values[(string) $id] ?? null;

            if ($value === null || $value === '') {
                return null;
            }

            $parts[] = (string) $value;
        }

        return count($parts) === 1 ? $parts[0] : json_encode($parts, JSON_UNESCAPED_UNICODE);
    }
}
