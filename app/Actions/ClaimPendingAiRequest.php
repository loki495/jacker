<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AiDriver;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use Illuminate\Support\Facades\DB;

final class ClaimPendingAiRequest
{
    public function handle(string $kind, ?int $id = null, AiDriver $driver = AiDriver::Bridge): ?AiRequest
    {
        return DB::transaction(function () use ($kind, $id, $driver): ?AiRequest {
            $request = AiRequest::query()->with(['linkCheck', 'opportunity'])
                ->where('kind', $kind)->where('driver', $driver->value)->where('status', LinkCheckStatus::Queued)
                ->when($id !== null, fn ($query) => $query->whereKey($id))
                ->when($kind === AiRequest::KIND_EXTRACT, fn ($query) => $query->whereDoesntHave('linkCheck.aiRequests', fn ($renders) => $renders->where('kind', AiRequest::KIND_RENDER)->whereIn('status', [LinkCheckStatus::Queued, LinkCheckStatus::Running])))
                ->orderBy('id')->lockForUpdate()->first();
            if (! $request instanceof AiRequest) {
                return null;
            }
            $claimed = AiRequest::query()->whereKey($request->id)->where('status', LinkCheckStatus::Queued)
                ->update(['status' => LinkCheckStatus::Running->value, 'started_at' => now()]);

            return $claimed === 1 ? $request->refresh() : null;
        });
    }
}
