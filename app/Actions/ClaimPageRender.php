<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Services\UrlGuard;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ClaimPageRender
{
    public function __construct(private UrlGuard $guard, private CompletePageRender $complete) {}

    /** @return array{id: int, url: string}|null */
    public function handle(): ?array
    {
        $request = DB::transaction(function (): ?AiRequest {
            $request = AiRequest::query()->with('linkCheck')->where('kind', AiRequest::KIND_RENDER)->where('status', LinkCheckStatus::Queued)->orderBy('id')->first();
            $request?->update(['status' => LinkCheckStatus::Running, 'started_at' => now()]);

            return $request;
        });
        if (! $request instanceof AiRequest) {
            return null;
        }
        try {
            $this->guard->assertPublic($request->linkCheck->url);
        } catch (InvalidArgumentException $exception) {
            $this->complete->fail($request, $exception->getMessage());

            return $this->handle();
        }

        return ['id' => $request->id, 'url' => $request->linkCheck->url];
    }
}
