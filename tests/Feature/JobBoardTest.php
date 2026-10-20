<?php

declare(strict_types=1);

use App\Actions\RunLinkCheck;
use App\Enums\LinkCheckStatus;
use App\Enums\LinkResult;
use App\Models\LinkCheck;
use App\Models\Opportunity;
use App\Services\UrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const ASHBY_ID = 'eea6dcd5-55c0-461d-9dee-79211668291b';

beforeEach(function (): void {
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host): array => ['93.184.216.34']));
});

function runCheck(string $url, array $attributes = []): array
{
    $job = Opportunity::factory()->create(['application_url' => $url, 'title' => null, 'location' => null, 'responsibilities' => null, 'salary_min' => null, 'salary_max' => null, ...$attributes]);
    $check = LinkCheck::query()->create(['kind' => 'check', 'url' => $url, 'opportunity_id' => $job->id, 'status' => LinkCheckStatus::Queued]);
    app(RunLinkCheck::class)->handle($check);

    return [$check->refresh(), $job->refresh()];
}

it('treats an Ashby job missing from the public board as gone without loading the JavaScript shell', function (): void {
    Http::fake(['api.ashbyhq.com/*' => Http::response(['jobs' => [['id' => 'ab832f21-91ad-4027-9bdf-47f3e36ce4b3', 'title' => 'Other']]])]);
    [$check, $job] = runCheck('https://jobs.ashbyhq.com/givebutter/'.ASHBY_ID);
    expect($check->result)->toBe(LinkResult::Dead)->and($check->message)->toBe(__('jobs.link_board_gone'))->and($job->status->value)->toBe('closed');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'jobs.ashbyhq.com'));
});

it('fills empty fields from a live Ashby posting', function (): void {
    Http::fake(['api.ashbyhq.com/*' => Http::response(['jobs' => [[
        'id' => ASHBY_ID, 'title' => 'Engineering Manager', 'location' => 'Remote', 'employmentType' => 'FullTime', 'workplaceType' => 'Remote', 'isRemote' => true,
        'descriptionPlain' => 'Lead the team.', 'compensation' => ['compensationTiers' => [['components' => [
            ['compensationType' => 'Salary', 'interval' => '1 YEAR', 'currencyCode' => 'USD', 'minValue' => 175000, 'maxValue' => 195000],
        ]]]],
    ]]])]);
    [$check, $job] = runCheck('https://jobs.ashbyhq.com/givebutter/'.ASHBY_ID);
    expect($check->result)->toBe(LinkResult::Ok)->and($job->title)->toBe('Engineering Manager')->and($job->employment_type->value)->toBe('full_time')
        ->and($job->work_arrangement->value)->toBe('remote')->and($job->responsibilities)->toBe('Lead the team.')
        ->and((float) $job->salary_min)->toBe(175000.0)->and($job->pay_period->value)->toBe('year')->and($job->status->value)->not->toBe('closed')
        ->and($check->page_text)->toBe("Engineering Manager\nRemote\nLead the team.");
});

it('falls back to the page when the board answers with an error', function (): void {
    Http::fake(['api.ashbyhq.com/*' => Http::response('oops', 500), 'jobs.ashbyhq.com/*' => Http::response('<html><title>Jobs</title></html>')]);
    [$check] = runCheck('https://jobs.ashbyhq.com/givebutter/'.ASHBY_ID);
    expect($check->result)->toBe(LinkResult::Ok);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'jobs.ashbyhq.com'));
});

it('reads Greenhouse and Lever postings and flags their 404s as gone', function (): void {
    Http::fake([
        'boards-api.greenhouse.io/v1/boards/acme/jobs/111*' => Http::response(['title' => 'Data Center Administrator', 'company_name' => 'Acme', 'location' => ['name' => 'Hawthorne, CA'], 'content' => '&lt;p&gt;Run the racks&lt;/p&gt;', 'pay_input_ranges' => [['min_cents' => 9000000, 'max_cents' => 12000000, 'currency_type' => 'USD']]]),
        'boards-api.greenhouse.io/v1/boards/acme/jobs/222*' => Http::response([], 404),
        'api.lever.co/v0/postings/acme/*' => Http::response([], 404),
    ]);
    [$ok, $job] = runCheck('https://boards.greenhouse.io/acme/jobs/111?gh_src=x');
    expect($ok->result)->toBe(LinkResult::Ok)->and($job->title)->toBe('Data Center Administrator')->and($job->responsibilities)->toBe('Run the racks')->and((float) $job->salary_max)->toBe(120000.0);
    expect(runCheck('https://job-boards.greenhouse.io/acme/jobs/222')[0]->result)->toBe(LinkResult::Dead);
    expect(runCheck('https://jobs.lever.co/acme/0b2f6a3e-1111-2222-3333-444455556666')[0]->result)->toBe(LinkResult::Dead);
});

it('flags a 200 page whose text says the job is gone, but not a long page that mentions it deep inside', function (string $body, LinkResult $result): void {
    Http::fake(['*' => Http::response($body)]);
    [$check, $job] = runCheck('https://careers.example/jobs/9');
    expect($check->result)->toBe($result)->and($job->status->value === 'closed')->toBe($result === LinkResult::Dead);
})->with([
    'job not found' => ['<html><body><nav>Home</nav><h1>Job not found</h1></body></html>', LinkResult::Dead],
    'no longer available' => ['<html><body><p>Sorry, this position is no longer available.</p></body></html>', LinkResult::Dead],
    'title 404' => ['<html><head><title>404 Not Found</title></head><body>Oops</body></html>', LinkResult::Dead],
    'live page' => ['<html><body><h1>Senior PHP Engineer</h1><p>'.str_repeat('We build things. ', 200).'Past roles were closed when filled.</p></body></html>', LinkResult::Ok],
]);

it('flags a posting whose structured data has expired', function (): void {
    $posting = json_encode(['@type' => 'JobPosting', 'title' => 'Old', 'hiringOrganization' => ['name' => 'Acme'], 'validThrough' => '2020-01-01']);
    Http::fake(['*' => Http::response('<script type="application/ld+json">'.$posting.'</script>')]);
    [$check, $job] = runCheck('https://careers.example/jobs/10');
    expect($check->result)->toBe(LinkResult::Dead)->and($check->message)->toBe(__('jobs.link_expired'))->and($job->status->value)->toBe('closed');
});
