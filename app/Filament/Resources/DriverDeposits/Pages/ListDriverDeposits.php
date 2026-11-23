<?php

namespace App\Filament\Resources\DriverDeposits\Pages;

use App\Filament\Resources\DriverDeposits\DriverDepositResource;
use Filament\Resources\Pages\ListRecords;

class ListDriverDeposits extends ListRecords
{
    protected static string $resource = DriverDepositResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
