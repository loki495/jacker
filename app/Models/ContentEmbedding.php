<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmbeddingKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $opportunity_id
 * @property EmbeddingKind $kind
 * @property string $source_hash
 * @property string $provider
 * @property string $model
 * @property int $dimensions
 * @property array<mixed> $vector
 * @property Opportunity $opportunity
 */
final class ContentEmbedding extends Model
{
    #[\Override]
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['kind' => EmbeddingKind::class, 'dimensions' => 'integer', 'vector' => 'array'];
    }

    /** @return BelongsTo<Opportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }
}
