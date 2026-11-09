<?php

namespace App\Filament\Resources\StructureAlerts\Pages;

use App\Enums\AlertStatus;
use App\Filament\Resources\StructureAlerts\StructureAlertResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListStructureAlerts extends ListRecords
{
    protected static string $resource = StructureAlertResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'open' => Tab::make('Needs attention')
                ->badge(fn () => StructureAlertResource::getEloquentQuery()->where('status', AlertStatus::Open)->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', AlertStatus::Open)),
            'handled' => Tab::make('Handled')->modifyQueryUsing(fn (Builder $query) => $query->where('status', '!=', AlertStatus::Open)),
            'all' => Tab::make('All'),
        ];
    }
}
