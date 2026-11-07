<?php

namespace App\Filament\Resources\ManagedTables\Pages;

use App\Filament\Resources\ManagedTables\ManagedTableResource;
use Filament\Resources\Pages\ListRecords;

class ListManagedTables extends ListRecords
{
    protected static string $resource = ManagedTableResource::class;
}
