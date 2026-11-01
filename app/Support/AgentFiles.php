<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Opportunity;

/** Per-job CV and cover letter PDFs the agent worker leaves in storage/app/agent/apply/{opportunity id}/. They carry personal contact details, so they are served only through the app, never from public/. */
final class AgentFiles
{
    public const array NAMES = ['cv' => 'cv.pdf', 'letter' => 'letter.pdf'];

    public static function path(Opportunity $opportunity, string $file): string
    {
        return storage_path('app/agent/apply/'.$opportunity->id.'/'.(self::NAMES[$file] ?? abort(404)));
    }

    /** @return list<string> keys (cv, letter) whose PDF exists */
    public static function available(Opportunity $opportunity): array
    {
        return array_values(array_filter(array_keys(self::NAMES), fn (string $file): bool => is_file(self::path($opportunity, $file))));
    }
}
