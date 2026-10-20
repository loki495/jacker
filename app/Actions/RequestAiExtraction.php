<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\LinkCheck;

final class RequestAiExtraction
{
    /** Queues one extraction for a link check that kept page text, whether or not structured data was found; null when there is no text or one is already pending. */
    public function handle(LinkCheck $check): ?AiRequest
    {
        if (blank($check->page_text)) {
            return null;
        }
        $pending = AiRequest::query()->where('link_check_id', $check->id)->whereIn('status', [LinkCheckStatus::Queued, LinkCheckStatus::Running])->exists();
        if ($pending) {
            return null;
        }

        return AiRequest::query()->create([
            'link_check_id' => $check->id,
            'kind' => AiRequest::KIND_EXTRACT,
            'agent' => config('ai.agent'),
            'model' => config('ai.model'),
        ]);
    }
}
