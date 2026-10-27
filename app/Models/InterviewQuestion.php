<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OpportunityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property OpportunityStatus $stage
 * @property string $question
 * @property list<string>|null $options
 * @property int $position
 */
class InterviewQuestion extends Model
{
    #[\Override]
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /** @return list<OpportunityStatus> */
    public static function stages(): array
    {
        return array_values(array_filter(OpportunityStatus::cases(), fn (OpportunityStatus $status): bool => $status->impliesApplied()));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['stage' => OpportunityStatus::class, 'options' => 'array', 'position' => 'integer'];
    }

    /** @return HasMany<QuestionAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(QuestionAnswer::class);
    }
}
