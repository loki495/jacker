<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\LinkCheck;

final readonly class RequestAiExtraction
{
    public function __construct(private RequestPageRender $render) {}

    /** Queues one extraction for a link check, preceded by a browser render of its link when it has one, so the model reads the rendered text; null when there is neither saved text nor a renderable link, or an extraction is already pending. */
    public function handle(LinkCheck $check): ?AiRequest
    {
        $pending = AiRequest::query()->where('link_check_id', $check->id)->where('kind', AiRequest::KIND_EXTRACT)->whereIn('status', [LinkCheckStatus::Queued, LinkCheckStatus::Running])->exists();
        if ($pending) {
            return null;
        }
        $render = $this->render->handle($check);
        if (! $render instanceof AiRequest && blank($check->page_text)) {
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
