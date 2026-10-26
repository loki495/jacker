<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LinkCheckStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $source
 * @property LinkCheckStatus $status
 * @property int $pages_total
 * @property int $pages_done
 * @property int $seen
 * @property int $added
 * @property string|null $message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class ScrapeRun extends Model
{
    #[\Override]
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => LinkCheckStatus::class, 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public static function active(): bool
    {
        return self::query()->whereIn('status', [LinkCheckStatus::Queued, LinkCheckStatus::Running])->exists();
    }

    /** @return HasMany<FoundJob, $this> */
    public function foundJobs(): HasMany
    {
        return $this->hasMany(FoundJob::class);
    }
}
