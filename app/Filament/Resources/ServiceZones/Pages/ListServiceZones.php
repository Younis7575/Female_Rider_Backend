<?php

namespace App\Filament\Resources\ServiceZones\Pages;

use App\Filament\Resources\ServiceZones\ServiceZoneResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceZones extends ListRecords
{
    protected static string $resource = ServiceZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
