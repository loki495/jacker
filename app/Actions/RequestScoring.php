<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LinkCheckStatus;
use App\Enums\OpportunityStatus;
use App\Models\AiRequest;
use App\Models\Opportunity;

final class RequestScoring
{
    public const string ALL = 'all';

    public const string UNSCORED = 'unscored';

    /** Queues one scoring request per open, unapplied listing that has none pending; UNSCORED only takes listings missing either score. Returns how many were queued. */
    public function handle(string $mode): int
    {
        $first = config('scoring.chain.0');
        $pending = AiRequest::query()->where('kind', AiRequest::KIND_SCORE)->whereIn('status', [LinkCheckStatus::Queued, LinkCheckStatus::Running])->pluck('opportunity_id');
        $jobs = Opportunity::query()->whereNull('archived_at')->where('has_applied', false)->where('status', '!=', OpportunityStatus::Closed->value)
            ->whereNotIn('id', $pending)
            ->when($mode === self::UNSCORED, fn ($query) => $query->where(fn ($query) => $query->whereNull('skill_match')->orWhereNull('desirability')))
            ->orderBy('id')->get(['id']);
        foreach ($jobs as $job) {
            AiRequest::query()->create(['opportunity_id' => $job->id, 'kind' => AiRequest::KIND_SCORE, 'mode' => $mode, 'agent' => $first['agent'], 'model' => $first['model']]);
        }

        return $jobs->count();
    }
}
