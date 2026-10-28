<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EmbeddingKind;
use App\Models\ContentEmbedding;
use App\Models\Opportunity;
use App\Services\TextEmbeddings;
use App\Support\EmbeddingSettings;

final class FindRelatedContent
{
    /** @return list<array{opportunity: Opportunity, similarity: float}> */
    public function handle(Opportunity $opportunity, EmbeddingKind $kind, int $limit = 3): array
    {
        if (! EmbeddingSettings::enabled() || $limit < 1) {
            return [];
        }
        $query = ContentEmbedding::query()->where('opportunity_id', $opportunity->id)->where('kind', EmbeddingKind::Job->value)->first();
        $fresh = Opportunity::query()->find($opportunity->id);
        if (! $query || ! $fresh || ! $this->usable($query, $fresh)) {
            return [];
        }
        $results = [];
        $candidates = ContentEmbedding::query()->with(['opportunity', 'opportunity.coverLetter'])->where('kind', $kind->value)->where('opportunity_id', '!=', $opportunity->id)->whereHas('opportunity', fn ($q) => $q->whereNull('archived_at'))->get();
        foreach ($candidates as $candidate) {
            if (! $this->usable($candidate, $candidate->opportunity)) {
                continue;
            }
            $queryScale = max(array_map(abs(...), $query->vector));
            $candidateScale = max(array_map(abs(...), $candidate->vector));
            $dot = 0.0;
            $a = 0.0;
            $b = 0.0;
            foreach ($query->vector as $i => $value) {
                $value /= $queryScale;
                $other = $candidate->vector[$i] / $candidateScale;
                $dot += $value * $other;
                $a += $value * $value;
                $b += $other * $other;
            }
            $similarity = $dot / sqrt($a) / sqrt($b);
            if (is_finite($similarity) && $similarity > 0) {
                $results[] = ['opportunity' => $candidate->opportunity, 'similarity' => $similarity];
            }
        }
        usort($results, fn (array $a, array $b): int => ($b['similarity'] <=> $a['similarity']) ?: ($a['opportunity']->id <=> $b['opportunity']->id));

        return array_slice($results, 0, $limit);
    }

    private function usable(ContentEmbedding $embedding, Opportunity $opportunity): bool
    {
        return $embedding->provider === config('embeddings.provider') && $embedding->model === config('embeddings.model')
            && $embedding->dimensions === (int) config('embeddings.dimensions')
            && TextEmbeddings::valid($embedding->vector, $embedding->dimensions)
            && $embedding->source_hash === hash('sha256', UpdateJobEmbeddings::source($opportunity, $embedding->kind));
    }
}
