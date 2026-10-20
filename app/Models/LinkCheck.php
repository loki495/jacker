<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LinkCheckStatus;
use App\Enums\LinkResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $opportunity_id
 * @property string $kind
 * @property string $url
 * @property LinkCheckStatus $status
 * @property LinkResult|null $result
 * @property int|null $http_status
 * @property string|null $message
 * @property list<string>|null $filled
 * @property string|null $page_text
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class LinkCheck extends Model
{
    public const KIND_CHECK = 'check';

    public const KIND_IMPORT = 'import';

    public const KIND_SAVED = 'saved';

    #[\Override]
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => LinkCheckStatus::class,
            'result' => LinkResult::class,
            'filled' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return HasMany<AiRequest, $this> */
    public function aiRequests(): HasMany
    {
        return $this->hasMany(AiRequest::class);
    }

    /** @return BelongsTo<Opportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }
}
