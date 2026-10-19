<?php

declare(strict_types=1);

namespace App\Enums;

enum PayPeriod: string
{
    case Unspecified = 'unspecified';
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public function label(): string
    {
        return __('jobs.PayPeriod.'.$this->value);
    }
}
