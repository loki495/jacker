<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Opportunity;

final class ArchiveOpportunity
{
    public function handle(Opportunity $opportunity, bool $archive = true): Opportunity
    {
        $opportunity->forceFill(['archived_at' => $archive ? now() : null])->save();

        return $opportunity->refresh();
    }
}
