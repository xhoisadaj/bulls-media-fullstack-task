<?php

namespace App\Policies;

use App\Models\SyncSchedule;
use App\Models\User;

/**
 * Sync schedules are set by admins.
 */
class SyncSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, SyncSchedule $record): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, SyncSchedule $record): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, SyncSchedule $record): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
