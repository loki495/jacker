<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Opportunity;
use App\Models\Resume;

final class SaveResume
{
    public const int MAX = 20_000;

    /** Creates or replaces the job's one edited resume (Markdown); a blank body deletes it. */
    public function handle(Opportunity $opportunity, string $body): ?Resume
    {
        $body = trim($body);
        if ($body === '') {
            Resume::query()->where('opportunity_id', $opportunity->id)->delete();

            return null;
        }

        return Resume::query()->updateOrCreate(['opportunity_id' => $opportunity->id], ['body' => mb_substr($body, 0, self::MAX)]);
    }
}
