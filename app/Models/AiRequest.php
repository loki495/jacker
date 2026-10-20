<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LinkCheckStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $link_check_id
 * @property string $kind
 * @property LinkCheckStatus $status
 * @property string|null $agent
 * @property string|null $model
 * @property string|null $output
 * @property string|null $message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class AiRequest extends Model
{
    public const KIND_EXTRACT = 'extract';

    #[\Override]
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => LinkCheckStatus::class, 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /** @return BelongsTo<LinkCheck, $this> */
    public function linkCheck(): BelongsTo
    {
        return $this->belongsTo(LinkCheck::class);
    }
}
