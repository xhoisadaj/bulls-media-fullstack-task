<?php

namespace App\Filament\Resources\SourceTables\Pages;

use App\Filament\Resources\SourceTables\SourceTableResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSourceTables extends ListRecords
{
    protected static string $resource = SourceTableResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a sheet table')];
    }
}
