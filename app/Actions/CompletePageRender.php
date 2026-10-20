<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;

final class CompletePageRender
{
    public function fail(AiRequest $request, string $message): void
    {
        $request->update(['status' => LinkCheckStatus::Failed, 'message' => $message, 'finished_at' => now()]);
    }

    /** Replaces the check's page text with the rendered text; the AI pass then reads that. */
    public function handle(AiRequest $request, string $text): void
    {
        $text = mb_substr(trim((string) preg_replace(["/[ \t\xC2\xA0]+/", "/ *\n\s*/"], [' ', "\n"], $text)), 0, (int) config('linkcheck.max_text_chars'));
        if ($text === '') {
            $this->fail($request, __('jobs.render_empty'));

            return;
        }
        $request->linkCheck->update(['page_text' => $text]);
        $request->update(['status' => LinkCheckStatus::Done, 'message' => __('jobs.render_done', ['chars' => mb_strlen($text)]), 'finished_at' => now()]);
    }
}
