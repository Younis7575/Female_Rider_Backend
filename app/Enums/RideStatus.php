<?php

namespace App\Enums;

enum RideStatus: string
{
    case Searching = 'searching';
    case OffersAvailable = 'offers_available';
    case Accepted = 'accepted';
    case CancelledByCustomer = 'cancelled_by_customer';
    case Expired = 'expired';
    case NoDriverFound = 'no_driver_found';

    public function isOpen(): bool
    {
        return in_array($this, [self::Searching, self::OffersAvailable], true);
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }
}
