<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Opportunity> */
class OpportunityFactory extends Factory
{
    #[\Override]
    protected $model = Opportunity::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['company' => fake()->company(), 'title' => fake()->jobTitle(), 'status' => 'saved', 'currency' => 'USD', 'has_applied' => false, 'employment_type' => 'unspecified', 'work_arrangement' => 'unspecified', 'pay_period' => 'unspecified'];
    }
}
