<?php

namespace App\Enums;

enum DriverStatus: string
{
    case NotSubmitted = 'not_submitted';
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case MoreInfoRequired = 'more_info_required';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Expired = 'expired';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    public function canEditApplication(): bool
    {
        return in_array($this, [self::NotSubmitted, self::MoreInfoRequired, self::Rejected, self::Expired], true);
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }
}
