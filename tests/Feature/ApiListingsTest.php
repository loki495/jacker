<?php

declare(strict_types=1);

use App\Models\Opportunity;
use App\Support\ApplicationUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.token' => 'secret-token', 'api.max_batch' => 3, 'api.rate_limit' => 60]);
    RateLimiter::clear('api');
});

function existing(array $attributes): Opportunity
{
    return Opportunity::factory()->create($attributes + ['normalized_application_url' => ApplicationUrl::normalize($attributes['application_url'])]);
}

function postListings(array $listings, string $token = 'secret-token')
{
    return test()->withToken($token)->postJson('/api/v1/listings', ['listings' => $listings]);
}

it('is disabled without a configured token and rejects a wrong or missing one', function (): void {
    $this->getJson('/api/v1/info')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
    $this->withToken('wrong')->getJson('/api/v1/info')->assertUnauthorized();
    config(['api.token' => '']);
    $this->withToken('secret-token')->getJson('/api/v1/info')->assertNotFound();
});

it('describes the accepted fields and a stable schema version', function (): void {
    $first = $this->withToken('secret-token')->getJson('/api/v1/info')->assertOk();
    $names = array_column($first->json('fields'), 'name');
    expect($names)->toContain('application_url', 'company', 'extra_fields')
        ->and($names)->not->toContain('status', 'skill_match', 'has_applied');
    $pay = collect($first->json('fields'))->firstWhere('name', 'pay_period');
    expect($pay['values'])->toContain('year')
        ->and($first->json('limits.max_listings_per_request'))->toBe(3)
        ->and($this->withToken('secret-token')->getJson('/api/v1/info')->json('schema_version'))->toBe($first->json('schema_version'));
});

it('creates a listing and reports unknown and owner-controlled keys as ignored', function (): void {
    $response = postListings([['company' => 'Acme', 'application_url' => 'https://acme.test/jobs/1', 'title' => 'Dev', 'status' => 'offer', 'skill_match' => 5, 'perks' => 'x']])->assertOk();
    expect($response->json('summary.created'))->toBe(1)
        ->and($response->json('results.0.ignored'))->toBe(['status', 'skill_match', 'perks']);
    $job = Opportunity::query()->firstOrFail();
    expect($job->status->value)->toBe('saved')->and($job->skill_match)->toBeNull()->and($job->title)->toBe('Dev');
});

it('fills only empty fields of an existing listing and never overwrites', function (): void {
    $job = existing(['company' => 'Acme', 'application_url' => 'https://acme.test/jobs/1', 'title' => 'Mine', 'location' => null, 'requirements' => null, 'salary_min' => null, 'salary_max' => null, 'extra_fields' => ['team' => '5']]);
    $response = postListings([[
        'company' => 'Other', 'application_url' => 'https://ACME.test/jobs/1/', 'title' => 'Theirs', 'location' => 'Remote', 'requirements' => 'PHP',
        'salary_min' => 100000, 'salary_max' => 120000, 'currency' => 'USD', 'pay_period' => 'year', 'extra_fields' => ['team' => '9', 'stack' => 'Laravel'],
    ]])->assertOk();
    expect($response->json('results.0.result'))->toBe('updated')
        ->and($response->json('results.0.id'))->toBe($job->id)
        ->and($response->json('results.0.filled'))->toContain('location', 'requirements', 'salary_min', 'extra_fields')
        ->not->toContain('title', 'company');
    $job->refresh();
    expect($job->title)->toBe('Mine')->and($job->company)->not->toBe('Other')->and($job->location)->toBe('Remote')
        ->and((float) $job->salary_min)->toBe(100000.0)->and($job->extra_fields)->toBe(['team' => '5', 'stack' => 'Laravel']);
    expect(Opportunity::query()->count())->toBe(1);
});

it('does not touch pay that is already set and reports unchanged', function (): void {
    existing(['application_url' => 'https://acme.test/jobs/2', 'salary_min' => 50000, 'salary_max' => 60000, 'currency' => 'USD', 'pay_period' => 'year', 'location' => 'Here']);
    $response = postListings([['company' => 'Acme', 'application_url' => 'https://acme.test/jobs/2', 'salary_min' => 90000, 'salary_max' => 95000, 'location' => 'There']]);
    expect($response->json('results.0.result'))->toBe('unchanged');
    expect((float) Opportunity::query()->firstOrFail()->salary_min)->toBe(50000.0);
});

it('reports invalid items without failing the rest of the batch', function (): void {
    postListings(array_fill(0, 4, ['company' => 'Acme', 'application_url' => 'https://acme.test/jobs/9']))->assertUnprocessable();
    $response = postListings([
        ['company' => 'Acme', 'application_url' => 'https://acme.test/jobs/3'],
        ['company' => 'Acme'],
        ['company' => 'Acme', 'application_url' => 'https://acme.test/jobs/5', 'pay_period' => 'fortnight'],
    ])->assertOk();
    expect($response->json('summary'))->toBe(['created' => 1, 'updated' => 0, 'unchanged' => 0, 'invalid' => 2])
        ->and($response->json('results.1.errors'))->toHaveKey('application_url')
        ->and($response->json('results.2.errors'))->toHaveKey('pay_period')
        ->and(Opportunity::query()->count())->toBe(1);
});

it('rejects a malformed body and throttles repeated requests', function (): void {
    $this->withToken('secret-token')->postJson('/api/v1/listings', ['listings' => 'x'])->assertUnprocessable();
    $this->withToken('secret-token')->postJson('/api/v1/listings', [])->assertUnprocessable();
    config(['api.rate_limit' => 2]);
    RateLimiter::clear('api');
    $this->withToken('nope')->getJson('/api/v1/info');
    $this->withToken('nope')->getJson('/api/v1/info');
    $this->withToken('secret-token')->getJson('/api/v1/info')->assertTooManyRequests();
});

it('lists known listings with the fields still missing and filters to those', function (): void {
    existing(['company' => 'Full', 'application_url' => 'https://a.test/1', 'title' => 'T', 'source' => 's', 'company_url' => 'https://a.test', 'location' => 'l', 'remote_restrictions' => 'r', 'responsibilities' => 'x', 'requirements' => 'x', 'benefits' => 'x', 'hours' => 'x', 'contact' => 'x', 'notes' => 'x', 'salary_notes' => 'x', 'salary_min' => 1, 'salary_max' => 2, 'employment_type' => 'full_time', 'work_arrangement' => 'remote', 'deadline' => '2030-01-01']);
    existing(['company' => 'Sparse', 'application_url' => 'https://a.test/2', 'title' => 'T2', 'location' => null, 'salary_min' => null, 'salary_max' => null, 'employment_type' => 'unspecified', 'requirements' => '']);
    Opportunity::factory()->create(['application_url' => null, 'normalized_application_url' => null]);

    $all = $this->withToken('secret-token')->getJson('/api/v1/listings')->assertOk();
    expect($all->json('total'))->toBe(2)->and($all->json('listings.0.missing'))->toBe([])
        ->and($all->json('listings.1.application_url'))->toBe('https://a.test/2')
        ->and($all->json('listings.1.missing'))->toContain('location', 'requirements', 'employment_type', 'salary_min', 'salary_max')
        ->not->toContain('title', 'company', 'currency');

    $only = $this->withToken('secret-token')->getJson('/api/v1/listings?only_missing=1')->assertOk();
    expect($only->json('total'))->toBe(1)->and($only->json('listings.0.company'))->toBe('Sparse');
});

it('pages the known listings and validates the paging query', function (): void {
    $this->getJson('/api/v1/listings')->assertUnauthorized();
    foreach (range(1, 3) as $n) {
        existing(['application_url' => "https://b.test/$n"]);
    }
    $page = $this->withToken('secret-token')->getJson('/api/v1/listings?per_page=2&page=2')->assertOk();
    expect($page->json('listings'))->toHaveCount(1)->and($page->json('last_page'))->toBe(2)->and($page->json('total'))->toBe(3);
    $this->withToken('secret-token')->getJson('/api/v1/listings?per_page=9999')->assertUnprocessable();
    $this->withToken('secret-token')->getJson('/api/v1/listings?page=0')->assertUnprocessable();
});

it('makes a missing field disappear from the known list once it is filled', function (): void {
    $job = existing(['company' => 'Acme', 'application_url' => 'https://c.test/1', 'location' => null]);
    expect($this->withToken('secret-token')->getJson('/api/v1/listings')->json('listings.0.missing'))->toContain('location');
    postListings([['company' => 'Acme', 'application_url' => 'https://c.test/1', 'location' => 'Remote']])->assertOk();
    expect($this->withToken('secret-token')->getJson('/api/v1/listings')->json('listings.0.missing'))->not->toContain('location')
        ->and($job->refresh()->location)->toBe('Remote');
});
