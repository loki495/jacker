<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\CoverLetter;
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
        AiRequest::query()->create(['opportunity_id' => $letter->opportunity_id, 'kind' => AiRequest::KIND_LETTER, 'agent' => $ai['agent'], 'model' => $ai['model']]);

        return true;
    }

    public function isPending(CoverLetter $letter): bool
    {
        return AiRequest::query()->where('kind', AiRequest::KIND_LETTER)->where('opportunity_id', $letter->opportunity_id)->whereIn('status', [LinkCheckStatus::Queued, LinkCheckStatus::Running])->exists();
    }
}
