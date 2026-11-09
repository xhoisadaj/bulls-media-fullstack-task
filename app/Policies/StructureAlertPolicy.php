<?php

namespace App\Policies;

use App\Models\StructureAlert;
use App\Models\User;
use App\Support\Access;

/**
 * Alerts of the user's files (alerts about the Google connection itself are for admins).
 */
class StructureAlertPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StructureAlert $record): bool
    {
        return Access::canAccessSpreadsheet($record->spreadsheet_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StructureAlert $record): bool
    {
        return $user->canEdit() && Access::canAccessSpreadsheet($record->spreadsheet_id);
    }

    public function delete(User $user, StructureAlert $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
