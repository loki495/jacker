<?php

declare(strict_types=1);

use App\Models\FoundJob;
use App\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function staged(string $title, array $extra = []): FoundJob
{
    return FoundJob::query()->create(['source' => 'builtin', 'url' => "https://x.test/{$title}", 'normalized_url' => "https://x.test/{$title}", 'title' => $title, 'company' => 'Co'] + $extra);
}

function titles(Testable $page): array
{
    return $page->instance()->listings->pluck('title')->all();
}

it('sorts staged listings by posted date, annualised pay or US-first location, with unknowns last', function (): void {
    staged('hourly', ['salary_min' => 60, 'salary_max' => 80, 'pay_period' => 'hour', 'location' => 'Worldwide', 'posted_at' => '2026-10-01']);
    staged('yearly', ['salary_min' => 100000, 'salary_max' => 120000, 'pay_period' => 'year', 'location' => 'United States', 'posted_at' => '2026-10-05']);
    staged('lowmonthly', ['salary_min' => 5000, 'pay_period' => 'month', 'location' => 'Canada', 'posted_at' => '2026-10-03']);
    staged('nopay');
    staged('russia', ['location' => 'Russia']);
    staged('austria', ['location' => 'Austria']);

    $page = Livewire::test('pages::job-finder');
    expect(titles($page))->toBe(['yearly', 'lowmonthly', 'hourly', 'austria', 'russia', 'nopay']);

    expect(titles($page->set('sort', 'pay')))->toBe(['hourly', 'yearly', 'lowmonthly', 'austria', 'russia', 'nopay']);
    expect(titles($page->set('sort', 'location')))->toBe(['yearly', 'hourly', 'austria', 'lowmonthly', 'russia', 'nopay']);
});

it('sorts the overview by pay and location', function (): void {
    $make = fn (string $company, array $extra = []): Opportunity => Opportunity::query()->create(['company' => $company] + $extra);
    $make('Hourly', ['salary_min' => 70, 'pay_period' => 'hour', 'location' => 'Texas']);
    $make('Yearly', ['salary_min' => 100000, 'salary_max' => 110000, 'pay_period' => 'year', 'location' => 'Alabama']);
    $make('Unknown');
    $make('Austin', ['location' => 'Austin, TX, US']);
    $make('Austria', ['location' => 'Austria']);

    $page = Livewire::test('pages::home')->set('sort', 'pay');
    expect($page->instance()->opportunities->pluck('company')->all())->toBe(['Hourly', 'Yearly', 'Austria', 'Austin', 'Unknown']);

    $page->set('sort', 'location');
    expect($page->instance()->opportunities->pluck('company')->all())->toBe(['Austin', 'Yearly', 'Austria', 'Hourly', 'Unknown']);
});
