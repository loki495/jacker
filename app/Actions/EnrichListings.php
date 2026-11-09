<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LinkCheckStatus;
use App\Models\Opportunity;
use App\Support\ListingGaps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class EnrichListings
{
    public function __construct(private QueueLinkChecks $queue) {}

    /**
     * Queues enrich checks for up to $limit listings that have an application link and gaps. LinkedIn links are never fetched.
     *
     * @return array{candidates: list<Opportunity>, queued: int, skipped_linkedin: int, gone: Collection<int, Opportunity>}
     */
    public function handle(int $limit, bool $dryRun): array
    {
        $candidates = [];
        $skipped = 0;
        foreach ($this->eligible()->cursor() as $opportunity) {
            if (count($candidates) >= $limit) {
                break;
            }
            if ($this->isLinkedIn((string) $opportunity->application_url)) {
                $skipped++;

                continue;
            }
            $candidates[] = $opportunity;
        }
        $queued = $dryRun ? 0 : $this->queue->forOpportunities(collect($candidates), true);

        return [
            'candidates' => $candidates,
            'queued' => $queued,
            'skipped_linkedin' => $skipped,
            'gone' => Opportunity::query()->whereNull('archived_at')->where('status', '!=', 'closed')->where('link_status', 'dead')->orderBy('id')->get(),
        ];
    }

    /** @return Builder<Opportunity> */
    private function eligible(): Builder
    {
        $since = now()->subDays((int) config('linkcheck.enrich_cooldown_days'));

        return Opportunity::query()
            ->whereNull('archived_at')
            ->where('status', '!=', 'closed')
            ->whereNotNull('application_url')
            ->where(fn (Builder $q): Builder => ListingGaps::apply($q))
            ->whereDoesntHave('linkChecks', fn (Builder $q): Builder => $q->whereIn('status', [LinkCheckStatus::Queued->value, LinkCheckStatus::Running->value]))
            ->whereDoesntHave('linkChecks', fn (Builder $q): Builder => $q->where('enrich', true)->where('created_at', '>=', $since))
            ->orderBy('id');
    }

    private function isLinkedIn(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'linkedin.com' || str_ends_with($host, '.linkedin.com');
    }
}
