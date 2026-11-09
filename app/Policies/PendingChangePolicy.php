<?php

namespace App\Policies;

use App\Models\PendingChange;
use App\Models\User;
use App\Support\Access;

/**
 * Changes of the user's files: everyone sees them, editors and admins decide.
 */
class PendingChangePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PendingChange $record): bool
    {
        return Access::canAccessSpreadsheet($record->sourceTable?->tab?->spreadsheet_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PendingChange $record): bool
    {
        return $user->canEdit() && Access::canAccessSpreadsheet($record->sourceTable?->tab?->spreadsheet_id);
    }

    public function delete(User $user, PendingChange $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
