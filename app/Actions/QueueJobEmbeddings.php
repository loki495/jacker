<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\ProcessJobEmbeddings;
use App\Models\Opportunity;
use App\Support\EmbeddingSettings;

final class QueueJobEmbeddings
{
    public function handle(): int
    {
        if (! EmbeddingSettings::enabled()) {
            return 0;
        }
        $count = 0;
        Opportunity::query()->whereNull('archived_at')->eachById(function (Opportunity $opportunity) use (&$count): void {
            ProcessJobEmbeddings::dispatch($opportunity->id)->afterCommit();
            $count++;
        });

        return $count;
    }
}
