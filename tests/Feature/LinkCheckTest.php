<?php

declare(strict_types=1);

use App\Actions\QueueLinkChecks;
use App\Actions\RunLinkCheck;
use App\Enums\LinkCheckStatus;
use App\Enums\LinkResult;
use App\Jobs\ProcessLinkCheck;
use App\Models\LinkCheck;
use App\Models\Opportunity;
use App\Services\JobPageFetcher;
use App\Services\JobPostingParser;
use App\Services\UrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const POSTING = [
    '@context' => 'https://schema.org', '@type' => 'JobPosting', 'title' => 'Senior PHP Engineer',
    'hiringOrganization' => ['@type' => 'Organization', 'name' => 'Acme', 'sameAs' => 'https://acme.example'],
    'jobLocationType' => 'TELECOMMUTE', 'employmentType' => ['FULL_TIME'],
    'jobLocation' => ['@type' => 'Place', 'address' => ['addressLocality' => 'Austin', 'addressRegion' => 'TX', 'addressCountry' => ['name' => 'US']]],
    'baseSalary' => ['@type' => 'MonetaryAmount', 'currency' => 'USD', 'value' => ['minValue' => 120000, 'maxValue' => 150000, 'unitText' => 'YEAR']],
    'jobBenefits' => '<ul><li>Medical</li><li>401(k)</li></ul>', 'responsibilities' => 'Build &amp; run APIs',
    'qualifications' => 'PHP, Laravel',
];

function page(array $data = POSTING, bool $graph = false): string
{
    $json = json_encode($graph ? ['@graph' => [['@type' => 'WebSite'], $data]] : $data);

    return '<html><head><script type="application/ld+json">'.$json.'</script></head><body>Job</body></html>';
}

beforeEach(function (): void {
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host): array => match ($host) {
        'internal.test' => ['192.168.1.5'], 'mixed.test' => ['93.184.216.34', '10.0.0.1'], default => ['93.184.216.34']
    }));
});

it('parses JobPosting data including graphs and ignores pages without it', function (): void {
    $fields = (new JobPostingParser)->parse(page(graph: true));
    expect($fields)->toMatchArray([
        'title' => 'Senior PHP Engineer', 'company' => 'Acme', 'company_url' => 'https://acme.example', 'work_arrangement' => 'remote',
        'employment_type' => 'full_time', 'location' => 'Austin, TX, US', 'salary_min' => '120000', 'salary_max' => '150000', 'currency' => 'USD', 'pay_period' => 'year',
        'responsibilities' => 'Build & run APIs', 'requirements' => 'PHP, Laravel',
    ])->and($fields['benefits'])->toBe("Medical\n401(k)");
    expect((new JobPostingParser)->parse('<html><script type="application/ld+json">{not json</script></html>'))->toBe([])
        ->and((new JobPostingParser)->parse('<html>plain</html>'))->toBe([]);
});

it('refuses private addresses, odd schemes and unresolvable hosts without fetching', function (string $url): void {
    Http::fake();
    $check = LinkCheck::query()->create(['kind' => 'import', 'url' => $url, 'status' => LinkCheckStatus::Queued]);
    app(RunLinkCheck::class)->handle($check);
    expect($check->refresh()->status)->toBe(LinkCheckStatus::Failed)->and($check->result)->toBe(LinkResult::Error)->and($check->message)->not->toBeEmpty();
    Http::assertNothingSent();
})->with(['http://internal.test/job', 'http://127.0.0.1/job', 'http://[::1]/job', 'http://[::ffff:127.0.0.1]/job', 'http://100.64.0.1/job', 'http://198.18.0.1/job', 'http://[64:ff9b::7f00:1]/job', 'http://mixed.test/job', 'http://[fd00::1]/job', 'ftp://example.com/job', 'file:///etc/passwd']);

it('checks redirect hops against the guard', function (): void {
    Http::fake(['https://jobs.example/start' => Http::response('', 302, ['Location' => 'http://internal.test/admin'])]);
    $check = LinkCheck::query()->create(['kind' => 'import', 'url' => 'https://jobs.example/start', 'status' => LinkCheckStatus::Queued]);
    app(RunLinkCheck::class)->handle($check);
    expect($check->refresh()->status)->toBe(LinkCheckStatus::Failed);
    Http::assertSentCount(1);
});

it('fills only empty fields on an existing job and never overwrites', function (): void {
    Http::fake(['*' => Http::response(page())]);
    $job = Opportunity::factory()->create(['application_url' => 'https://jobs.example/1', 'company' => 'Mine', 'title' => 'My title', 'location' => null, 'salary_min' => null, 'salary_max' => null, 'pay_period' => 'unspecified', 'benefits' => 'Kept', 'work_arrangement' => 'hybrid']);
    $check = LinkCheck::query()->create(['kind' => 'check', 'url' => $job->application_url, 'opportunity_id' => $job->id, 'status' => LinkCheckStatus::Queued]);
    app(RunLinkCheck::class)->handle($check);
    $job->refresh();
    expect($job->company)->toBe('Mine')->and($job->title)->toBe('My title')->and($job->benefits)->toBe('Kept')->and($job->work_arrangement->value)->toBe('hybrid')
        ->and($job->location)->toBe('Austin, TX, US')->and($job->payRange())->toBe('$120,000 – $150,000 per year')->and($job->link_status)->toBe('ok')->and($job->link_checked_at)->not->toBeNull();
    expect($check->refresh()->status)->toBe(LinkCheckStatus::Done)->and($check->filled)->not->toContain('benefits');
});

it('flags dead and blocked links without touching job data', function (int $status, LinkResult $result, LinkCheckStatus $state): void {
    Http::fake(['*' => Http::response('nope', $status)]);
    $job = Opportunity::factory()->create(['application_url' => 'https://jobs.example/2', 'title' => 'Same']);
    $check = LinkCheck::query()->create(['kind' => 'check', 'url' => $job->application_url, 'opportunity_id' => $job->id, 'status' => LinkCheckStatus::Queued]);
    app(RunLinkCheck::class)->handle($check);
    expect($check->refresh()->result)->toBe($result)->and($check->status)->toBe($state)->and($check->http_status)->toBe($status)
        ->and($job->refresh()->link_status)->toBe($result->value)->and($job->title)->toBe('Same')->and($job->status->value)->not->toBe('closed');
})->with([[404, LinkResult::Dead, LinkCheckStatus::Done], [410, LinkResult::Dead, LinkCheckStatus::Done], [403, LinkResult::Blocked, LinkCheckStatus::Done], [429, LinkResult::Blocked, LinkCheckStatus::Done], [500, LinkResult::Error, LinkCheckStatus::Failed]]);

it('builds the address pin from the normalised scheme, host and port', function (string $url, string $expected): void {
    $pin = new ReflectionMethod(JobPageFetcher::class, 'pin');
    expect($pin->invoke(app(JobPageFetcher::class), $url, ['2606:4700::1', '93.184.216.34']))->toBe($expected);
})->with([
    ['HTTP://Example.COM/x', 'example.com:80:93.184.216.34'],
    ['https://example.com/x', 'example.com:443:93.184.216.34'],
    ['http://example.com:8080/x', 'example.com:8080:93.184.216.34'],
]);

it('discards a page fetched from an address the guard did not vet', function (): void {
    $check = new ReflectionMethod(JobPageFetcher::class, 'assertConnectedToVetted');
    $fetcher = app(JobPageFetcher::class);
    expect(fn () => $check->invoke($fetcher, '10.0.0.5', ['93.184.216.34']))->toThrow(RuntimeException::class);
    $check->invoke($fetcher, '93.184.216.34', ['93.184.216.34']);
    $check->invoke($fetcher, '2606:4700:0:0:0:0:0:1', ['2606:4700::1']);
});

it('reports a live page with no structured data as done with nothing filled', function (): void {
    Http::fake(['*' => Http::response('<html>plain</html>')]);
    $job = Opportunity::factory()->create(['application_url' => 'https://jobs.example/3']);
    $check = LinkCheck::query()->create(['kind' => 'check', 'url' => $job->application_url, 'opportunity_id' => $job->id, 'status' => LinkCheckStatus::Queued]);
    app(RunLinkCheck::class)->handle($check);
    expect($check->refresh()->status)->toBe(LinkCheckStatus::Done)->and($check->message)->toBe(__('jobs.link_no_data'));
});

it('creates a listing from a link, rejects duplicates and pages without a company', function (): void {
    Http::fake(['https://jobs.example/new' => fn () => Http::response(page()), 'https://jobs.example/bare' => Http::response(page(['@type' => 'JobPosting', 'title' => 'No company']))]);
    $queue = app(QueueLinkChecks::class);
    $result = $queue->forUrls(['https://jobs.example/new', 'https://jobs.example/new', 'not a url', 'ftp://x.example/a', 'https://jobs.example/bare']);
    expect($result['queued'])->toBe(2)->and($result['rejected'])->toBe(['not a url', 'ftp://x.example/a']);
    $job = Opportunity::query()->where('application_url', 'https://jobs.example/new')->sole();
    expect($job->company)->toBe('Acme')->and($job->source)->toBe(__('jobs.url_import_source'))->and($job->events)->toHaveCount(1);
    $bare = LinkCheck::query()->where('url', 'https://jobs.example/bare')->sole();
    expect($bare->status)->toBe(LinkCheckStatus::Failed)->and($bare->message)->toBe(__('jobs.link_no_data'))->and(Opportunity::query()->count())->toBe(1);
    $again = LinkCheck::query()->create(['kind' => 'import', 'url' => 'https://jobs.example/new', 'status' => LinkCheckStatus::Queued]);
    app(RunLinkCheck::class)->handle($again);
    expect($again->refresh()->status)->toBe(LinkCheckStatus::Failed)->and($again->message)->toContain(__('jobs.duplicate_url'))->and(Opportunity::query()->count())->toBe(1);
});

it('queues only active unclosed jobs with links, once, and spaces them out', function (): void {
    Bus::fake();
    $a = Opportunity::factory()->create(['application_url' => 'https://jobs.example/a']);
    Opportunity::factory()->create(['application_url' => 'https://jobs.example/b', 'archived_at' => now()]);
    Opportunity::factory()->create(['application_url' => 'https://jobs.example/c', 'status' => 'closed']);
    Opportunity::factory()->create(['application_url' => null]);
    $d = Opportunity::factory()->create(['application_url' => 'https://jobs.example/d']);
    $queue = app(QueueLinkChecks::class);
    expect($queue->forActiveJobs())->toBe(2)->and($queue->forActiveJobs())->toBe(0);
    Bus::assertDispatched(ProcessLinkCheck::class, 2);
    expect(LinkCheck::query()->pluck('opportunity_id')->all())->toBe([$a->id, $d->id]);
});

it('records an unexpected failure and rethrows only in debug mode', function (): void {
    $check = LinkCheck::query()->create(['kind' => 'import', 'url' => 'https://jobs.example/x', 'status' => LinkCheckStatus::Queued]);
    Http::fake(fn () => throw new LogicException('boom'));
    config(['app.debug' => false]);
    new ProcessLinkCheck($check->id)->handle(app(RunLinkCheck::class));
    expect($check->refresh()->status)->toBe(LinkCheckStatus::Failed)->and($check->message)->toBe(__('jobs.link_unexpected'));
    $check->update(['status' => LinkCheckStatus::Queued]);
    config(['app.debug' => true]);
    expect(fn () => new ProcessLinkCheck($check->id)->handle(app(RunLinkCheck::class)))->toThrow(LogicException::class);
});

it('drives link checks from the log page and the detail page', function (): void {
    Bus::fake();
    $job = Opportunity::factory()->create(['application_url' => 'https://jobs.example/ui']);
    Livewire::test('pages::link-checks')->call('addUrls')->assertHasErrors(['urls' => 'required'])
        ->set('urls', "https://jobs.example/one\njunk")->call('addUrls')->assertHasNoErrors()->assertSee('Skipped')->call('checkAll')->assertSee($job->application_url);
    expect(LinkCheck::query()->count())->toBe(2);
    Livewire::test('pages::detail', ['opportunity' => $job])->call('checkLink');
    expect(LinkCheck::query()->where('opportunity_id', $job->id)->count())->toBe(1);
    Livewire::test('pages::detail', ['opportunity' => $job])->call('checkLink')->assertHasNoErrors();
    expect(LinkCheck::query()->where('opportunity_id', $job->id)->count())->toBe(1);
});
