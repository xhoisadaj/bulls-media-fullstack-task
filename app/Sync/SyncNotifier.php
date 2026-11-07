<?php

namespace App\Sync;

use App\Enums\SyncStatus;
use App\Models\StructureAlert;
use App\Models\SyncRun;
use App\Models\User;
use App\Notifications\SyncNeedsAttention;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the admin when a sync needs attention: pending changes, new alerts, failures.
 */
class SyncNotifier
{
    public function afterRun(SyncRun $run): void
    {
        $pending = (int) ($run->counts['pending_created'] ?? 0) - (int) ($run->counts['auto_applied'] ?? 0);
        $alerts = StructureAlert::query()->open()->where('created_at', '>=', $run->started_at)->count();
        $failed = in_array($run->status, [SyncStatus::Failed, SyncStatus::Partial], true);

        if ($pending <= 0 && $alerts === 0 && ! $failed) {
            return;
        }

        $name = $run->spreadsheet->name;
        $parts = array_filter([
            $pending > 0 ? $pending.' change(s) waiting for review' : null,
            $alerts > 0 ? $alerts.' new alert(s)' : null,
            $failed ? 'sync '.$run->status->value : null,
        ]);
        $body = '"'.$name.'": '.implode(', ', $parts).'.';

        $users = User::all();

        FilamentNotification::make()
            ->title($failed ? 'Sync needs attention' : 'Sync finished')
            ->body($body)
            ->status($failed ? 'danger' : 'warning')
            ->actions([
                Action::make('review')->label('Open review inbox')->url('/admin/pending-changes'),
            ])
            ->sendToDatabase($users);

        if ($email = config('sync.notify_email')) {
            Notification::route('mail', $email)->notify(new SyncNeedsAttention($body));
        }
    }
}
