<?php

namespace App\Filament\Resources\FareRules\Pages;

use App\Filament\Resources\FareRules\FareRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFareRules extends ListRecords
{
    protected static string $resource = FareRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
