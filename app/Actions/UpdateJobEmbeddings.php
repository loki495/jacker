<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EmbeddingKind;
use App\Models\ContentEmbedding;
use App\Models\Opportunity;
use App\Services\TextEmbeddings;
use App\Support\EmbeddingSettings;
use Illuminate\Support\Facades\DB;

final readonly class UpdateJobEmbeddings
{
    public const array JOB_FIELDS = ['company', 'title', 'benefits', 'hours', 'location', 'remote_restrictions', 'responsibilities', 'requirements', 'employment_type', 'work_arrangement'];

    public function __construct(private TextEmbeddings $embeddings) {}

    public static function source(Opportunity $opportunity, EmbeddingKind $kind): string
    {
        if ($kind === EmbeddingKind::Letter) {
            return trim((string) $opportunity->coverLetter?->body);
        }
        $parts = [];
        foreach (self::JOB_FIELDS as $field) {
            $value = $opportunity->getRawOriginal($field);
            if (is_scalar($value) && trim((string) $value) !== '') {
                $parts[] = $field.': '.mb_substr(trim((string) $value), 0, 10000);
            }
        }

        return implode("\n", $parts);
    }

    public function handle(Opportunity $opportunity): void
    {
        if (! EmbeddingSettings::enabled()) {
            return;
        }
        foreach (EmbeddingKind::cases() as $kind) {
            $fresh = Opportunity::query()->find($opportunity->id);
            if ($fresh === null) {
                return;
            }
            $source = self::source($fresh, $kind);
            $hash = hash('sha256', $source);
            $existing = ContentEmbedding::query()->where('opportunity_id', $fresh->id)->where('kind', $kind->value)->first();
            if ($source === '') {
                $existing?->delete();

                continue;
            }
            $provider = (string) config('embeddings.provider');
            $model = (string) config('embeddings.model');
            $dimensions = (int) config('embeddings.dimensions');
            if ($existing && $existing->source_hash === $hash && $existing->provider === $provider && $existing->model === $model && $existing->dimensions === $dimensions && TextEmbeddings::valid($existing->vector, $dimensions)) {
                continue;
            }
            $vector = $this->embeddings->generate($source);
            DB::transaction(function () use ($fresh, $kind, $hash, $provider, $model, $dimensions, $vector): void {
                $current = Opportunity::query()->lockForUpdate()->find($fresh->id);
                if ($current === null || ! EmbeddingSettings::enabled() || hash('sha256', self::source($current, $kind)) !== $hash) {
                    return;
                }
                ContentEmbedding::query()->updateOrCreate(['opportunity_id' => $current->id, 'kind' => $kind->value], [
                    'source_hash' => $hash, 'provider' => $provider, 'model' => $model, 'dimensions' => $dimensions, 'vector' => $vector,
                ]);
            });
        }
    }
}
