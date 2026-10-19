<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LinkCheckStatus;
use App\Jobs\ProcessLinkCheck;
use App\Models\LinkCheck;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class QueueLinkChecks
{
    private const array PENDING = [LinkCheckStatus::Queued, LinkCheckStatus::Running];

    /** Checks every active, unclosed job that has an application link. Returns how many were queued. */
    public function forActiveJobs(): int
    {
        return $this->forOpportunities(Opportunity::query()
            ->whereNull('archived_at')
            ->where('status', '!=', 'closed')
            ->whereNotNull('application_url')
            ->orderBy('id')
            ->get());
    }

    /** @param Collection<int, Opportunity> $opportunities */
    public function forOpportunities(Collection $opportunities): int
    {
        $queued = 0;
        foreach ($opportunities as $opportunity) {
            if ($opportunity->application_url === null || $this->pending(LinkCheck::query()->where('opportunity_id', $opportunity->id))) {
                continue;
            }
            $this->enqueue(LinkCheck::KIND_CHECK, $opportunity->application_url, $opportunity->id, $queued++);
        }

        return $queued;
    }

    /**
     * @param  list<string>  $urls
     * @return array{queued: int, rejected: list<string>}
     */
    public function forUrls(array $urls): array
    {
        $queued = 0;
        $rejected = [];
        foreach (array_unique(array_filter(array_map(trim(...), $urls))) as $url) {
            if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) || $this->pending(LinkCheck::query()->where('url', $url)->where('kind', LinkCheck::KIND_IMPORT))) {
                $rejected[] = $url;

                continue;
            }
            $this->enqueue(LinkCheck::KIND_IMPORT, $url, null, $queued++);
        }

        return ['queued' => $queued, 'rejected' => $rejected];
    }

    /** @param Builder<LinkCheck> $query */
    private function pending($query): bool
    {
        return $query->whereIn('status', array_map(fn (LinkCheckStatus $status): string => $status->value, self::PENDING))->exists();
    }

    private function enqueue(string $kind, string $url, ?int $opportunityId, int $position): void
    {
        $check = LinkCheck::query()->create(['kind' => $kind, 'url' => $url, 'opportunity_id' => $opportunityId, 'status' => LinkCheckStatus::Queued]);
        ProcessLinkCheck::dispatch($check->id)->delay(now()->addSeconds($position * (int) config('linkcheck.spacing')));
    }
}
