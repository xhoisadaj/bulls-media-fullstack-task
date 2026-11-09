<?php

namespace App\Filament\Resources\PendingChanges\Pages;

use App\Enums\ChangeStatus;
use App\Filament\Resources\PendingChanges\PendingChangeResource;
use App\Models\PendingChange;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * Pending first; the decided ones stay one click away.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Waiting for review')
                ->badge(fn () => PendingChange::query()->pending()->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ChangeStatus::Pending)),
            'approved' => Tab::make('Approved')->modifyQueryUsing(fn (Builder $query) => $query->where('status', ChangeStatus::Approved)),
            'rejected' => Tab::make('Rejected')->modifyQueryUsing(fn (Builder $query) => $query->where('status', ChangeStatus::Rejected)),
            'all' => Tab::make('All'),
        ];
    }
}
