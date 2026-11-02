<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Opportunity;

/** Per-job resume and cover letter PDFs the agent worker leaves in storage/app/agent/apply/{opportunity id}/. They carry personal contact details, so they are served only through the app, never from public/. */
final class AgentFiles
{
    public const array NAMES = ['resume' => 'resume.pdf', 'letter' => 'letter.pdf'];

    /** Markdown twins of the PDFs, preloaded into the editors by PreloadApplicationMarkdown. */
    public const array MARKDOWN = ['resume' => 'resume.md', 'letter' => 'letter.md'];

    public static function markdownPath(Opportunity $opportunity, string $file): string
    {
        return storage_path('app/agent/apply/'.$opportunity->id.'/'.(self::MARKDOWN[$file] ?? abort(404)));
    }

    public static function path(Opportunity $opportunity, string $file): string
    {
        return storage_path('app/agent/apply/'.$opportunity->id.'/'.(self::NAMES[$file] ?? abort(404)));
    }

    /** @return list<string> keys (resume, letter) whose PDF exists */
    public static function available(Opportunity $opportunity): array
    {
        return array_values(array_filter(array_keys(self::NAMES), fn (string $file): bool => is_file(self::path($opportunity, $file))));
    }

    /** The general (not job-specific) resume PDF the host worker copies in; the version picker offers it as "general" when this is not null. */
    public static function generalResume(): ?string
    {
        $path = storage_path('app/agent/general/resume.pdf');

        return is_file($path) ? $path : null;
    }
}
