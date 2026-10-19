<?php

declare(strict_types=1);

namespace App\Enums;

enum EmploymentType: string
{
    case Unspecified = 'unspecified';
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contract = 'contract';
    case Freelance = 'freelance';
    case Temporary = 'temporary';
    case Internship = 'internship';

    public function label(): string
    {
        return __('jobs.EmploymentType.'.$this->value);
    }
}
