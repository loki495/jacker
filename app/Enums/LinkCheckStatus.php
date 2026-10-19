<?php

declare(strict_types=1);

namespace App\Enums;

enum LinkCheckStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return __('jobs.LinkCheckStatus.'.$this->value);
    }
}
