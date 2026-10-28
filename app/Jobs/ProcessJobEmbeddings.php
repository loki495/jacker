<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\UpdateJobEmbeddings;
use App\Models\Opportunity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class ProcessJobEmbeddings implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $opportunityId) {}

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [new WithoutOverlapping((string) $this->opportunityId)->expireAfter(300)];
    }

    public function handle(UpdateJobEmbeddings $action): void
    {
        $opportunity = Opportunity::query()->find($this->opportunityId);
        if ($opportunity !== null) {
            $action->handle($opportunity);
        }
    }
}
