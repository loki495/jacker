<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\UpdateJobEmbeddings;
use App\Jobs\ProcessJobEmbeddings;
use App\Models\Opportunity;
use App\Support\EmbeddingSettings;

final class OpportunityEmbeddingObserver
{
    public function created(Opportunity $opportunity): void
    {
        if (EmbeddingSettings::enabled()) {
            ProcessJobEmbeddings::dispatch($opportunity->id)->afterCommit();
        }
    }

    public function updated(Opportunity $opportunity): void
    {
        if (EmbeddingSettings::enabled() && $opportunity->wasChanged(UpdateJobEmbeddings::JOB_FIELDS)) {
            ProcessJobEmbeddings::dispatch($opportunity->id)->afterCommit();
        }
    }
}
