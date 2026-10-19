<?php

declare(strict_types=1);

namespace App\Enums;

enum LinkResult: string
{
    case Ok = 'ok';
    case Dead = 'dead';
    case Blocked = 'blocked';
    case Error = 'error';

    public function label(): string
    {
        return __('jobs.LinkResult.'.$this->value);
    }
}
