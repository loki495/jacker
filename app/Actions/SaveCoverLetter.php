<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CoverLetter;
use App\Models\Opportunity;

final class SaveCoverLetter
{
    public const int MAX = 20_000;

    /** Creates or replaces the job's one letter; a blank body deletes it. Suggestions are left alone. */
    public function handle(Opportunity $opportunity, string $body): ?CoverLetter
    {
        $body = trim($body);
        if ($body === '') {
            CoverLetter::query()->where('opportunity_id', $opportunity->id)->delete();

            return null;
        }

        return CoverLetter::query()->updateOrCreate(['opportunity_id' => $opportunity->id], ['body' => mb_substr($body, 0, self::MAX)]);
    }
}
