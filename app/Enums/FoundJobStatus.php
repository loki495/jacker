<?php

declare(strict_types=1);

namespace App\Enums;

enum FoundJobStatus: string
{
    case New = 'new';
    case Accepted = 'accepted';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return __('scrapers.FoundJobStatus.'.$this->value);
    }
}
