<?php

namespace App\Policies;

use App\Models\Spreadsheet;
use App\Models\User;
use App\Support\Access;

/**
 * Everyone sees the spreadsheets shared with them; admins add and delete spreadsheets, editors work on theirs.
 */
class SpreadsheetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Spreadsheet $record): bool
    {
        return Access::canAccessSpreadsheet($record->id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Spreadsheet $record): bool
    {
        return $user->canEdit() && Access::canAccessSpreadsheet($record->id);
    }

    public function delete(User $user, Spreadsheet $record): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
