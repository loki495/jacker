<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FoundJobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $scrape_run_id
 * @property int|null $opportunity_id
 * @property string $source
 * @property FoundJobStatus $status
 * @property string $url
 * @property string $normalized_url
 * @property string|null $title
 * @property string|null $company
 * @property string|null $location
 * @property string|null $work_arrangement
 * @property string|null $employment_type
 * @property string|null $salary_min
 * @property string|null $salary_max
 * @property string|null $currency
 * @property string|null $pay_period
 * @property string|null $salary_notes
 * @property string|null $description
 * @property string|null $company_url
 * @property string|null $remote_restrictions
 * @property string|null $requirements
 * @property string|null $notes
 * @property Carbon|null $deadline
 * @property Carbon|null $posted_at
 */
class FoundJob extends Model
{
    #[\Override]
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => FoundJobStatus::class, 'posted_at' => 'datetime', 'deadline' => 'date'];
    }

    /** @return BelongsTo<Opportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }
}
