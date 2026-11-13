<?php

namespace App\Filament\Resources\FareRules\Pages;

use App\Filament\Resources\FareRules\FareRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFareRule extends EditRecord
{
    protected static string $resource = FareRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
