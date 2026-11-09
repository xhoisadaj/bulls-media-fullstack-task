<?php

namespace App\Policies;

use App\Models\Mapping;
use App\Models\User;
use App\Support\Access;

/**
 * Column mappings of the user's sheet tables; editors and admins change them.
 */
class MappingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Mapping $record): bool
    {
        return Access::canAccessSpreadsheet($record->sourceTable?->tab?->spreadsheet_id);
    }

    public function create(User $user): bool
    {
        return $user->canEdit();
    }

    public function update(User $user, Mapping $record): bool
    {
        return $user->canEdit() && Access::canAccessSpreadsheet($record->sourceTable?->tab?->spreadsheet_id);
    }

    public function delete(User $user, Mapping $record): bool
    {
        return $user->canEdit() && Access::canAccessSpreadsheet($record->sourceTable?->tab?->spreadsheet_id);
    }

    public function deleteAny(User $user): bool
    {
        return $user->canEdit();
    }
}
