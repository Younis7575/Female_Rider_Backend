<?php

namespace App\Filament\Resources\EligibilityReviews\Pages;

use App\Filament\Resources\EligibilityReviews\EligibilityReviewResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEligibilityReview extends ViewRecord
{
    protected static string $resource = EligibilityReviewResource::class;

    protected function getHeaderActions(): array
    {
        return EligibilityReviewResource::viewHeaderActions();
    }
}
