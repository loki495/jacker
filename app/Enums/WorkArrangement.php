<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkArrangement: string
{
    case Unspecified = 'unspecified';
    case Remote = 'remote';
    case OnSite = 'on_site';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return __('jobs.WorkArrangement.'.$this->value);
    }
}
