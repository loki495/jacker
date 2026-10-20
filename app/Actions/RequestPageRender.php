<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\LinkCheck;
use App\Services\UrlGuard;
use InvalidArgumentException;

final readonly class RequestPageRender
{
    public function __construct(private UrlGuard $guard) {}

    /** Queues one browser render for a fetched link's page; null for saved text, a non-public address, or when one is already pending. */
    public function handle(LinkCheck $check): ?AiRequest
    {
        if ($check->kind === LinkCheck::KIND_SAVED) {
            return null;
        }
        try {
            $this->guard->assertPublic($check->url);
        } catch (InvalidArgumentException) {
            return null;
        }
        $pending = AiRequest::query()->where('link_check_id', $check->id)->where('kind', AiRequest::KIND_RENDER)->whereIn('status', [LinkCheckStatus::Queued, LinkCheckStatus::Running])->exists();
        if ($pending) {
            return null;
        }

        return AiRequest::query()->create(['link_check_id' => $check->id, 'kind' => AiRequest::KIND_RENDER]);
    }
}
