<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\ProcessJobEmbeddings;
use App\Models\CoverLetter;
use App\Support\EmbeddingSettings;

final class CoverLetterEmbeddingObserver
{
    public function created(CoverLetter $letter): void
    {
        if (EmbeddingSettings::enabled()) {
            ProcessJobEmbeddings::dispatch($letter->opportunity_id)->afterCommit();
        }
    }

    public function updated(CoverLetter $letter): void
    {
        if (EmbeddingSettings::enabled() && $letter->wasChanged('body')) {
            ProcessJobEmbeddings::dispatch($letter->opportunity_id)->afterCommit();
        }
    }

    public function deleted(CoverLetter $letter): void
    {
        if (EmbeddingSettings::enabled()) {
            ProcessJobEmbeddings::dispatch($letter->opportunity_id)->afterCommit();
        }
    }
}
