<?php

namespace App\Filament\Resources\PendingChanges\Pages;

use App\Filament\Resources\PendingChanges\PendingChangeResource;
use App\Models\PendingChange;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPendingChanges extends ListRecords
{
    protected static string $resource = PendingChangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approveAll')
                ->label('Approve all pending')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(fn () => PendingChange::query()->pending()->count().' pending change(s) will be written to the database, including removals.')
                ->visible(fn () => PendingChange::query()->pending()->exists())
                ->action(fn () => PendingChangeResource::decide(PendingChange::query()->pending()->orderBy('id')->get(), approve: true)),
        ];
    }
}
