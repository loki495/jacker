<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\CoverLetter;

final class CompleteLetterRequest
{
    public function fail(AiRequest $request, string $message): void
    {
        $request->update(['status' => LinkCheckStatus::Failed, 'message' => $message, 'finished_at' => now()]);
    }

    /** Stores the reply as the letter's suggestions, replacing the earlier ones; the letter text is never touched. */
    public function handle(AiRequest $request, string $output): void
    {
        $output = trim(mb_substr($output, 0, (int) config('ai.max_output_chars')));
        $letter = CoverLetter::query()->where('opportunity_id', $request->opportunity_id)->first();
        if ($output === '' || ! $letter instanceof CoverLetter) {
            $this->fail($request, __('letter.bad_output'));

            return;
        }
        $letter->update(['suggestions' => $output, 'suggested_at' => now()]);
        $request->update(['output' => $output, 'status' => LinkCheckStatus::Done, 'message' => __('letter.suggested'), 'finished_at' => now()]);
    }
}
