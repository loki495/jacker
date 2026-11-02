<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Models\Resume;
use App\Support\AgentFiles;

final class PreloadApplicationMarkdown
{
    public const int MAX = 20_000;

    /**
     * Fills the job's letter and resume editors from the Markdown the agent worker stored, only where nothing exists yet.
     * Unreadable, symlinked, oversized, non-UTF-8 or blank files are ignored.
     *
     * @return list<string> which of resume and letter were filled
     */
    public function handle(Opportunity $opportunity): array
    {
        $filled = [];
        $letter = $this->read($opportunity, 'letter');
        if ($letter !== null && ! CoverLetter::query()->where('opportunity_id', $opportunity->id)->exists()) {
            app(SaveCoverLetter::class)->handle($opportunity, $letter);
            $filled[] = 'letter';
        }
        $resume = $this->read($opportunity, 'resume');
        if ($resume !== null && ! Resume::query()->where('opportunity_id', $opportunity->id)->exists()) {
            Resume::query()->create(['opportunity_id' => $opportunity->id, 'body' => $resume]);
            $filled[] = 'resume';
        }

        return $filled;
    }

    private function read(Opportunity $opportunity, string $file): ?string
    {
        $path = AgentFiles::markdownPath($opportunity, $file);
        if (is_link($path) || ! is_file($path) || filesize($path) > self::MAX * 4) {
            return null;
        }
        $text = trim((string) file_get_contents($path));
        if ($text === '' || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > self::MAX) {
            return null;
        }

        return $text;
    }
}
