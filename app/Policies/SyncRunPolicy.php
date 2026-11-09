<?php

namespace App\Policies;

use App\Models\SyncRun;
use App\Models\User;
use App\Support\Access;

/**
 * Sync history of the user's files, read-only.
 */
class SyncRunPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SyncRun $record): bool
    {
        return Access::canAccessSpreadsheet($record->spreadsheet_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SyncRun $record): bool
    {
        return false;
    }

    public function delete(User $user, SyncRun $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
