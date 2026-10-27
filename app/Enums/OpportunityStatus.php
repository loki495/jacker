<?php

declare(strict_types=1);

namespace App\Enums;

enum OpportunityStatus: string
{
    case Saved = 'saved';
    case Preparing = 'preparing';
    case Contacted = 'contacted';
    case Applied = 'applied';
    case Screening = 'screening';
    case Interviewing = 'interviewing';
    case Offer = 'offer';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Closed = 'closed';

    public function label(): string
    {
        return __('jobs.OpportunityStatus.'.$this->value);
    }

    public function impliesApplied(): bool
    {
        return in_array($this, [self::Applied, self::Screening, self::Interviewing, self::Offer], true);
    }

    public function isActive(): bool
    {
        return ! in_array($this, [self::Rejected, self::Withdrawn, self::Closed], true);
    }
}
