<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OpportunityStatus;
use Illuminate\Database\Eloquent\Model;

class OpportunityEvent extends Model
{
    #[\Override]
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['from_status' => OpportunityStatus::class, 'to_status' => OpportunityStatus::class];
    }
}
