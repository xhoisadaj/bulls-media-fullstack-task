<?php

namespace App\Policies;

use App\Models\ManagedTable;
use App\Models\User;
use App\Support\Access;

/**
 * Database tables: everyone sees the ones their sheet tables save into; only admins change their structure.
 */
class ManagedTablePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ManagedTable $record): bool
    {
        return $user->isAdmin() || Access::whereManagedTable($record->newQuery())->whereKey($record->id)->exists();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ManagedTable $record): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ManagedTable $record): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
