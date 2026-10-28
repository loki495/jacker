<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AiDriver;
use App\Enums\LinkCheckStatus;
use App\Jobs\ProcessAiRequest;
use App\Models\AiRequest;
use App\Models\CoverLetter;
use App\Support\AiSettings;
use App\Support\CoverLetterSettings;

final class RequestLetterSuggestions
{
    /** Queues a suggestions run for the job's letter with the saved agent and model; false when a run is already pending. */
    public function handle(CoverLetter $letter): bool
    {
        if ($this->isPending($letter)) {
            return false;
        }
        $ai = CoverLetterSettings::ai();
        $request = AiRequest::query()->create(['opportunity_id' => $letter->opportunity_id, 'kind' => AiRequest::KIND_LETTER, ...AiSettings::requestAttributes('letter', $ai['agent'], $ai['model'])]);
        if ($request->driver === AiDriver::Sdk) {
            ProcessAiRequest::dispatch($request->id)->afterCommit();
        }

        return true;
    }

    public function isPending(CoverLetter $letter): bool
    {
        return AiRequest::query()->where('kind', AiRequest::KIND_LETTER)->where('opportunity_id', $letter->opportunity_id)->whereIn('status', [LinkCheckStatus::Queued, LinkCheckStatus::Running])->exists();
    }
}
