<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\OpportunityStatus;
use App\Enums\PayPeriod;
use App\Enums\WorkArrangement;
use Database\Factories\OpportunityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $company
 * @property string|null $normalized_application_url
 * @property string|null $title
 * @property OpportunityStatus $status
 * @property bool $has_applied
 * @property Carbon|null $applied_at
 * @property Carbon|null $deadline
 * @property Carbon|null $follow_up_at
 * @property Carbon|null $archived_at
 * @property array<string, mixed>|null $extra_fields
 */
class Opportunity extends Model
{
    /** @use HasFactory<OpportunityFactory> */
    use HasFactory;

    #[\Override]
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OpportunityStatus::class,
            'employment_type' => EmploymentType::class,
            'work_arrangement' => WorkArrangement::class,
            'pay_period' => PayPeriod::class,
            'salary_min' => 'decimal:2', 'salary_max' => 'decimal:2',
            'has_applied' => 'boolean', 'applied_at' => 'date',
            'deadline' => 'date', 'follow_up_at' => 'date',
            'archived_at' => 'datetime', 'extra_fields' => 'array',
            'skill_match' => 'integer', 'desirability' => 'integer',
        ];
    }

    /** @return HasMany<OpportunityEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(OpportunityEvent::class)->latest('id');
    }
}
