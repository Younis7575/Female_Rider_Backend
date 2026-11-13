<?php

namespace App\Filament\Resources\Rides\Pages;

use App\Filament\Resources\Rides\RideResource;
use Filament\Resources\Pages\ViewRecord;

class ViewRide extends ViewRecord
{
    protected static string $resource = RideResource::class;

    protected function getHeaderActions(): array
    {
        return RideResource::viewHeaderActions();
    }
}
