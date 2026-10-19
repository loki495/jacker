<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OpportunityStatus;
use App\Models\Opportunity;

final class ChangeOpportunityStatus
{
    public function handle(Opportunity $opportunity, OpportunityStatus $status): Opportunity
    {
        return app(SaveOpportunity::class)->handle(['status' => $status->value], $opportunity);
    }
}
