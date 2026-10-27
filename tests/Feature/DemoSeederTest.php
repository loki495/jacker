<?php

declare(strict_types=1);

use App\Models\Opportunity;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds fake listings into an empty database', function () {
    $this->seed(DemoSeeder::class);

    expect(Opportunity::query()->count())->toBe(12)
        ->and(Opportunity::query()->where('application_url', 'not like', 'https://example.com/%')->exists())->toBeFalse();
});

it('refuses to run when opportunities already exist', function () {
    Opportunity::factory()->create();

    expect(fn () => $this->seed(DemoSeeder::class))->toThrow(RuntimeException::class);
    expect(Opportunity::query()->count())->toBe(1);
});
