<?php

namespace App\Filament\Resources\ServiceZones\Pages;

use App\Filament\Resources\ServiceZones\ServiceZoneResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditServiceZone extends EditRecord
{
    protected static string $resource = ServiceZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
