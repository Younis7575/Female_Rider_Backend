<?php

namespace App\Enums;

enum EligibilityStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case MoreInfoRequired = 'more_info_required';
    case Suspended = 'suspended';
    case Appealed = 'appealed';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    /** States from which the user may (re)submit evidence. */
    public function canSubmit(): bool
    {
        return in_array($this, [self::Draft, self::MoreInfoRequired], true);
    }

    public function canAppeal(): bool
    {
        return in_array($this, [self::Rejected, self::Suspended], true);
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }
}
