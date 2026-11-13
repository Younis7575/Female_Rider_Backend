<?php

namespace App\Filament\Resources\Rides\Pages;

use App\Filament\Resources\Rides\RideResource;
use Filament\Resources\Pages\ListRecords;

class ListRides extends ListRecords
{
    protected static string $resource = RideResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
