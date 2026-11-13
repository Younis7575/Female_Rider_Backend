<?php

namespace App\Enums;

enum OfferStatus: string
{
    case Pending = 'pending';
    case Countered = 'countered';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
    case Closed = 'closed';

    public function label(): string
    {
        return ucwords($this->value);
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }
}
