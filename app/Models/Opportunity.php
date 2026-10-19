<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\OpportunityStatus;
use App\Enums\PayPeriod;
use App\Enums\WorkArrangement;
use Database\Factories\OpportunityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $company
 * @property string|null $normalized_application_url
 * @property string|null $title
 * @property string|null $currency
 * @property string|null $salary_min
 * @property string|null $salary_max
 * @property PayPeriod|null $pay_period
 * @property string|null $link_status
 * @property Carbon|null $link_checked_at
 * @property string|null $application_url
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
            'archived_at' => 'datetime', 'link_checked_at' => 'datetime', 'extra_fields' => 'array',
            'skill_match' => 'integer', 'desirability' => 'integer',
        ];
    }

    /**
     * Highest combined skill match and desirability first; desirability breaks ties.
     *
     * @param  Builder<Opportunity>  $query
     */
    public function scopeBestFirst(Builder $query): void
    {
        $query->orderByRaw('COALESCE(skill_match, 0) + COALESCE(desirability, 0) DESC')->orderByRaw('COALESCE(desirability, 0) DESC');
    }

    public function payRange(): ?string
    {
        if ($this->salary_min === null && $this->salary_max === null) {
            return null;
        }

        $symbol = ($this->currency ?: 'USD') === 'USD' ? '$' : ($this->currency ?: '').' ';
        $format = fn (?string $amount): ?string => $amount === null ? null : $symbol.number_format((float) $amount, 0);
        $range = $this->salary_min === $this->salary_max
            ? $format($this->salary_min)
            : implode(' – ', array_filter([$format($this->salary_min), $format($this->salary_max)]));

        return $this->pay_period === null || $this->pay_period === PayPeriod::Unspecified
            ? $range
            : __('jobs.pay_per', ['amount' => $range, 'period' => mb_strtolower($this->pay_period->label())]);
    }

    /** @return list<string> */
    public function listItems(string $field): array
    {
        $parts = preg_split('/\R|;/u', (string) $this->{$field}) ?: [];

        return array_values(array_filter(array_map(fn (string $part): string => trim($part, " \t-•*"), $parts), fn (string $part): bool => $part !== ''));
    }

    /** @return HasMany<OpportunityEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(OpportunityEvent::class)->latest('id');
    }
}
