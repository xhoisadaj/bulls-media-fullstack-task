<?php

namespace App\Filament\Resources\Mappings\Pages;

use App\Filament\Resources\Mappings\MappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMappings extends ListRecords
{
    protected static string $resource = MappingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
