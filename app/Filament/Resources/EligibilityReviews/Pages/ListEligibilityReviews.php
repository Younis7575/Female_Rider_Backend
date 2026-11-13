<?php

namespace App\Filament\Resources\EligibilityReviews\Pages;

use App\Filament\Resources\EligibilityReviews\EligibilityReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListEligibilityReviews extends ListRecords
{
    protected static string $resource = EligibilityReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
