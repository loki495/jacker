<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AiDriver;
use App\Enums\LinkCheckStatus;
use App\Jobs\ProcessAiRequest;
use App\Models\AiRequest;
use App\Models\LinkCheck;
use App\Support\AiSettings;

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
        $renderPending = $render instanceof AiRequest || $check->aiRequests()->where('kind', AiRequest::KIND_RENDER)->whereIn('status', [LinkCheckStatus::Queued, LinkCheckStatus::Running])->exists();
        if (! $renderPending && blank($check->page_text)) {
            return null;
        }

        $request = AiRequest::query()->create([
            'link_check_id' => $check->id,
            'kind' => AiRequest::KIND_EXTRACT,
            ...AiSettings::requestAttributes('extract', (string) config('ai.agent'), (string) config('ai.model')),
        ]);
        if ($request->driver === AiDriver::Sdk) {
            ProcessAiRequest::dispatch($request->id)->afterCommit();
        }

        return $request;
    }
}
