<?php

namespace App\Policies;

use App\Models\SourceTable;
use App\Models\User;
use App\Support\Access;

/**
 * Sheet tables of the user's spreadsheets; editors and admins change them.
 */
class SourceTablePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SourceTable $record): bool
    {
        return Access::canAccessSpreadsheet($record->tab?->spreadsheet_id);
    }

    public function create(User $user): bool
    {
        return $user->canEdit();
    }

    public function update(User $user, SourceTable $record): bool
    {
        return $user->canEdit() && Access::canAccessSpreadsheet($record->tab?->spreadsheet_id);
    }

    public function delete(User $user, SourceTable $record): bool
    {
        return $user->canEdit() && Access::canAccessSpreadsheet($record->tab?->spreadsheet_id);
    }

    public function deleteAny(User $user): bool
    {
        return $user->canEdit();
    }
}
