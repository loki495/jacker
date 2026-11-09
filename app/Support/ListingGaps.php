<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Builder;

final class ListingGaps
{
    /**
     * Adds an OR group matching a listing with any enrichable gap; wrap the call in a where closure.
     *
     * @param  Builder<Opportunity>  $query
     * @return Builder<Opportunity>
     */
    public static function apply(Builder $query): Builder
    {
        $enums = ['employment_type', 'work_arrangement'];
        foreach (ListingSchema::names() as $name) {
            if (in_array($name, [...ListingSchema::IDENTITY, 'extra_fields', 'currency', 'pay_period', 'salary_min', 'salary_max'], true)) {
                continue;
            }
            match (true) {
                in_array($name, $enums, true) => $query->orWhere($name, 'unspecified'),
                $name === 'deadline' => $query->orWhereNull($name),
                default => $query->orWhereNull($name)->orWhere($name, ''),
            };
        }

        return $query->orWhere(fn (Builder $q): Builder => $q->whereNull('salary_min')->whereNull('salary_max'));
    }
}
