<?php

declare(strict_types=1);

use App\Actions\AcceptFoundJob;
use App\Actions\StartScrapeRuns;
use App\Enums\FoundJobStatus;
use App\Enums\LinkCheckStatus;
use App\Models\FoundJob;
use App\Models\Opportunity;
use App\Models\ScrapeRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function himalayasBody(): string
{
    return json_encode(['jobs' => [
        ['title' => 'Senior PHP Developer', 'companyName' => 'Acme', 'guid' => 'https://himalayas.app/companies/acme/jobs/senior-php', 'employmentType' => 'Full Time', 'minSalary' => 90000, 'maxSalary' => 120000, 'currency' => 'usd', 'salaryPeriod' => 'annual', 'locationRestrictions' => ['Canada', 'United States'], 'timezoneRestrictions' => [-5, -4], 'seniority' => ['Senior'], 'companySlug' => 'acme', 'description' => '<p>Build Laravel apps</p>', 'pubDate' => 1792675549, 'expiryDate' => 1795259343],
        ['title' => 'PHP Engineer (anywhere)', 'companyName' => 'Global', 'guid' => 'https://himalayas.app/companies/global/jobs/php', 'locationRestrictions' => [], 'description' => 'PHP'],
        ['title' => 'PHP Engineer EU', 'companyName' => 'Euro', 'guid' => 'https://himalayas.app/companies/euro/jobs/php', 'locationRestrictions' => ['Italy', 'France'], 'description' => 'PHP'],
        ['title' => 'Senior UI Designer', 'companyName' => 'Design Co', 'guid' => 'https://himalayas.app/companies/design/jobs/ui', 'description' => '<p>Figma only</p>'],
        ['title' => 'Junior PHP Developer', 'companyName' => 'Small', 'guid' => 'https://himalayas.app/companies/small/jobs/junior-php', 'description' => 'PHP'],
        ['title' => 'Bad link PHP', 'companyName' => 'Evil', 'guid' => 'javascript:alert(1)', 'description' => 'PHP'],
    ]]);
}

function runSource(string $source): ScrapeRun
{
    expect(app(StartScrapeRuns::class)->handle([$source]))->toBe(1);

    return ScrapeRun::query()->latest('id')->firstOrFail();
}

beforeEach(function (): void {
    Sleep::fake();
    config(['scrapers.max_pages' => 1]);
});

it('stages matching Himalayas listings, drops off-topic, junior and non-https ones, and stays idempotent', function (): void {
    Http::fake(['himalayas.app/*' => Http::response(himalayasBody())]);

    $run = runSource('himalayas');

    expect($run->refresh())->status->toBe(LinkCheckStatus::Done)->seen->toBe(6)->added->toBe(2)->pages_done->toBe(1)->pages_total->toBe(1);
    expect(FoundJob::query()->orderBy('id')->pluck('location', 'title')->all())->toBe(['Senior PHP Developer' => 'United States +1', 'PHP Engineer (anywhere)' => 'Worldwide']);
    $found = FoundJob::query()->where('company', 'Acme')->sole();
    expect($found)->title->toBe('Senior PHP Developer')->work_arrangement->toBe('remote')->employment_type->toBe('full_time')
        ->currency->toBe('USD')->pay_period->toBe('year')->description->toBe('Build Laravel apps')
        ->company_url->toBe('https://himalayas.app/companies/acme')->remote_restrictions->toBe("Eligible countries: Canada, United States\nTime zones (UTC offsets): -5, -4")
        ->notes->toBe("Seniority: Senior\nEmployment type: Full Time");
    expect($found->deadline?->toDateString())->toBe('2026-11-21');
    expect((float) $found->salary_min)->toBe(90000.0);
    Sleep::assertNeverSlept();

    Livewire::test('pages::job-finder')->call('run', 'himalayas');
    expect(FoundJob::query()->count())->toBe(2);
});

it('converts staged pay to dollars at the fixed rate and keeps the original in the notes', function (): void {
    config(['scrapers.usd_rates' => ['EUR' => 1.1]]);
    $job = fn (string $slug, string $currency): array => ['title' => 'PHP Dev', 'companyName' => $slug, 'guid' => "https://himalayas.app/companies/{$slug}/jobs/php", 'minSalary' => 5000, 'maxSalary' => 6000, 'currency' => $currency, 'salaryPeriod' => 'monthly', 'locationRestrictions' => [], 'description' => 'PHP'];
    Http::fake(['himalayas.app/*' => Http::response(json_encode(['jobs' => [$job('euro', 'EUR'), $job('dollar', 'USD'), $job('odd', 'XYZ')]]))]);

    runSource('himalayas');

    $euro = FoundJob::query()->where('company', 'euro')->sole();
    expect($euro)->currency->toBe('USD')->and((float) $euro->salary_min)->toBe(5500.0)->and((float) $euro->salary_max)->toBe(6600.0)
        ->and($euro->salary_notes)->toBe('Posted as EUR 5,000–6,000 per month; converted at about 1 EUR = 1.1 USD (fixed rate in config/scrapers.php)');
    $dollar = FoundJob::query()->where('company', 'dollar')->sole();
    expect($dollar)->currency->toBe('USD')->salary_notes->toBeNull()->and((float) $dollar->salary_min)->toBe(5000.0);
    $odd = FoundJob::query()->where('company', 'odd')->sole();
    expect($odd->currency)->toBe('XYZ')->and((float) $odd->salary_min)->toBe(5000.0);
});

it('keeps the sort and filter in the address so they survive a reload', function (): void {
    Livewire::withQueryParams(['sort' => 'pay', 'filter' => 'dismissed'])->test('pages::job-finder')->assertSet('sort', 'pay')->assertSet('filter', 'dismissed');
});

it('skips listings already tracked as opportunities', function (): void {
    Opportunity::query()->create(['company' => 'Acme', 'application_url' => 'https://himalayas.app/companies/acme/jobs/senior-php', 'normalized_application_url' => 'https://himalayas.app/companies/acme/jobs/senior-php']);
    Http::fake(['himalayas.app/*' => Http::response(himalayasBody())]);

    expect(runSource('himalayas')->refresh()->added)->toBe(1);
    expect(FoundJob::query()->pluck('title')->all())->toBe(['PHP Engineer (anywhere)']);
});

it('reads We Work Remotely RSS items and filters by keyword', function (): void {
    $item = fn (string $title, string $text, string $region = 'Anywhere in the World'): string => "<item><title>{$title}</title><region>{$region}</region><category>Back-End Programming</category><description>&lt;p&gt;{$text}&lt;/p&gt;</description><pubDate>Tue, 06 Oct 2026 07:31:05 +0000</pubDate><link>https://weworkremotely.com/remote-jobs/".md5($title).'</link></item>';
    Http::fake(['weworkremotely.com/*' => Http::response('<rss><channel>'.$item('Acme: Laravel Engineer', 'We use Laravel').$item('Beta: Go Engineer', 'Only Go').$item('Euro: PHP Engineer', 'PHP', 'Europe Only').$item('Amer: PHP Engineer', 'PHP', 'Remote - Americas').'</channel></rss>')]);
    config(['scrapers.weworkremotely_feeds' => ['https://weworkremotely.com/feed.rss']]);

    runSource('weworkremotely');

    expect(FoundJob::query()->orderBy('id')->pluck('location', 'company')->all())->toBe(['Acme' => 'Worldwide', 'Amer' => 'Remote - Americas']);
    $found = FoundJob::query()->where('company', 'Acme')->sole();
    expect($found)->title->toBe('Laravel Engineer')->description->toBe('We use Laravel')->notes->toBe('Category: Back-End Programming');
    expect($found->posted_at?->toDateString())->toBe('2026-10-06');
});

it('reads Wellfound listings from the embedded Apollo cache', function (): void {
    $data = ['props' => ['pageProps' => ['apolloState' => ['data' => [
        'StartupResult:1' => ['name' => 'Mayura', 'slug' => 'mayura', 'highConcept' => 'Tech services', 'companySize' => 'SIZE_51_200', 'highlightedJobListings' => [['__ref' => 'JobListingSearchResult:9'], ['__ref' => 'JobListingSearchResult:10']]],
        'JobListingSearchResult:9' => ['id' => '9', 'slug' => 'laravel-developer', 'title' => 'Laravel Developer', 'remote' => true, 'jobType' => 'full-time', 'locationNames' => ['India'], 'acceptedRemoteLocationNames' => ['United States'], 'compensation' => '$100k – $140k • No equity', 'yearsExperienceMin' => 3, 'yearsExperienceMax' => null, 'primaryRoleTitle' => 'Software Engineer', 'description' => 'Laravel work', 'liveStartAt' => 1786364692],
        'JobListingSearchResult:10' => ['id' => '10', 'slug' => 'php-india', 'title' => 'PHP Developer', 'remote' => true, 'acceptedRemoteLocationNames' => ['India'], 'compensation' => '₹4L – ₹6L', 'description' => 'PHP work'],
    ]]]]];
    Http::fake(['wellfound.com/*' => Http::response('<html><script id="__NEXT_DATA__" type="application/json">'.json_encode($data).'</script></html>')]);

    runSource('wellfound');

    $found = FoundJob::query()->sole();
    expect($found)->url->toBe('https://wellfound.com/jobs/9-laravel-developer')->company->toBe('Mayura')->salary_notes->toBe('$100k – $140k • No equity')->work_arrangement->toBe('remote')
        ->location->toBe('United States')->currency->toBe('USD')->pay_period->toBe('year')->company_url->toBe('https://wellfound.com/company/mayura')
        ->requirements->toBe('3+ years of experience')->notes->toBe("Role: Software Engineer\nCompany: Tech services\nCompany size: 51–200 people");
    expect((float) $found->salary_min)->toBe(100000.0)->and((float) $found->salary_max)->toBe(140000.0);
});

it('reads Built In job cards without a keyword filter, since the site searches', function (): void {
    $card = '<div data-id="job-card"><a data-id="company-title" href="/company/salve-0"><span>Salve</span></a><h2><a data-id="job-card-title" data-alias="/job/senior-full-stack-engineer/9546746">Senior Full-Stack Engineer</a></h2>'
        .'<div><div><i class="fa-location-dot"></i></div> <div><span>United States</span></div></div>'
        .'<div><div><i class="fa-sack-dollar"></i></div> <span>110K-130K Annually</span></div><div><div><i class="fa-trophy"></i></div> <span>Senior level</span></div>'
        .'<div class="fs-sm fw-regular mb-md text-gray-04">Manage integrations.</div><span class="fs-xs mx-sm">PHP</span><span class="fs-xs mx-sm">Laravel</span></div>';
    Http::fake(['builtin.com/*' => Http::response("<html><body>{$card}</body></html>")]);

    runSource('builtin');

    expect(FoundJob::query()->sole())->url->toBe('https://builtin.com/job/senior-full-stack-engineer/9546746')->company->toBe('Salve')->title->toBe('Senior Full-Stack Engineer')
        ->location->toBe('United States')->currency->toBe('USD')->pay_period->toBe('year')->company_url->toBe('https://builtin.com/company/salve-0')
        ->description->toBe('Manage integrations.')->requirements->toBe('Top skills: PHP, Laravel')->notes->toBe('Level: Senior level');
});

it('stops at the first refusal instead of trying the remaining pages', function (): void {
    config(['scrapers.max_pages' => 3]);
    Http::fake(['builtin.com/*' => Http::response('blocked', 403)]);

    $run = runSource('builtin')->refresh();

    expect($run)->status->toBe(LinkCheckStatus::Failed)->message->toContain('403')->pages_done->toBe(0);
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('caps requests at max_pages and spaces them', function (): void {
    config(['scrapers.max_pages' => 3, 'scrapers.spacing' => 5]);
    Http::fake(['himalayas.app/*' => Http::response(json_encode(['jobs' => []]))]);

    runSource('himalayas');

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
    Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(5)->seconds()]);
});

it('marks the run failed when the site is unreachable', function (): void {
    Http::fake(fn () => throw new ConnectionException('down'));

    expect(runSource('himalayas')->refresh())->status->toBe(LinkCheckStatus::Failed);
});

it('refuses to start while a run is queued or running and ignores unknown sources', function (): void {
    ScrapeRun::query()->create(['source' => 'himalayas', 'status' => LinkCheckStatus::Running]);

    expect(app(StartScrapeRuns::class)->handle(['wellfound']))->toBe(0);

    ScrapeRun::query()->delete();
    expect(app(StartScrapeRuns::class)->handle(['nonsense']))->toBe(0);
});

it('accepts a staged listing into the tracker and dismisses one whose link is already tracked', function (): void {
    $found = FoundJob::query()->create(['source' => 'himalayas', 'url' => 'https://x.test/a', 'normalized_url' => 'https://x.test/a', 'title' => 'PHP Dev', 'company' => 'Acme', 'work_arrangement' => 'remote', 'salary_min' => 100, 'pay_period' => 'year', 'currency' => 'USD', 'description' => 'Do things',
        'company_url' => 'https://acme.test', 'location' => 'Worldwide', 'remote_restrictions' => 'Eligible countries: Canada', 'requirements' => '3+ years', 'notes' => 'Level: Senior', 'deadline' => '2026-12-01', 'posted_at' => '2026-10-06 00:00:00']);

    $opportunity = app(AcceptFoundJob::class)->handle($found);

    expect($opportunity)->company->toBe('Acme')->title->toBe('PHP Dev')->source->toBe('Himalayas')->application_url->toBe('https://x.test/a')
        ->company_url->toBe('https://acme.test')->location->toBe('Worldwide')->remote_restrictions->toBe('Eligible countries: Canada')->requirements->toBe('3+ years')
        ->notes->toBe("Level: Senior\nPosted Oct 6, 2026")->responsibilities->toBe('Do things');
    expect($opportunity->deadline?->toDateString())->toBe('2026-12-01');
    expect($found->refresh())->status->toBe(FoundJobStatus::Accepted)->opportunity_id->toBe($opportunity->id);

    $again = FoundJob::query()->create(['source' => 'wellfound', 'url' => 'https://x.test/a/', 'normalized_url' => 'https://x.test/a/dup', 'title' => 'PHP Dev', 'company' => 'Acme']);
    expect(fn () => app(AcceptFoundJob::class)->handle($again))->toThrow(ValidationException::class);
    expect($again->refresh()->status)->toBe(FoundJobStatus::Dismissed);
});

it('lets the page dismiss, restore and launch runs', function (): void {
    Http::fake(['himalayas.app/*' => Http::response(himalayasBody())]);
    $found = FoundJob::query()->create(['source' => 'builtin', 'url' => 'https://x.test/b', 'normalized_url' => 'https://x.test/b', 'title' => 'PHP Dev', 'company' => 'Acme']);

    $page = Livewire::test('pages::job-finder')->assertSee('Acme')->set('selected', [$found->id])->call('dismiss', $found->id)->assertSet('selected', []);
    expect($found->refresh()->status)->toBe(FoundJobStatus::Dismissed);
    $page->call('restore', $found->id);
    expect($found->refresh()->status)->toBe(FoundJobStatus::New);

    $page->call('run', 'himalayas')->assertSee('queued');
    expect(ScrapeRun::query()->count())->toBe(1);
});

it('adds the selected listings across pages, keeps the unselected ones and clears the selection', function (): void {
    foreach (range(1, 30) as $n) {
        FoundJob::query()->create(['source' => 'builtin', 'url' => "https://x.test/{$n}", 'normalized_url' => "https://x.test/{$n}", 'title' => "Job {$n}", 'company' => "Co {$n}", 'posted_at' => now()->subMinutes($n)]);
    }
    $first = FoundJob::query()->where('title', 'Job 1')->value('id');
    $last = FoundJob::query()->where('title', 'Job 30')->value('id');
    $keep = FoundJob::query()->where('title', 'Job 2')->value('id');

    $page = Livewire::test('pages::job-finder')->set('selected', [$first])->call('gotoPage', 2)->assertSet('selected', [$first]);
    $page->set('selected', [$first, $last])->call('addSelected')->assertSet('selected', [])->assertSee('2 added to your tracker');

    expect(Opportunity::query()->whereIn('application_url', ['https://x.test/1', 'https://x.test/30'])->count())->toBe(2);
    expect(FoundJob::query()->whereKey([$first, $last])->pluck('status')->unique()->all())->toEqual([FoundJobStatus::Accepted]);
    expect(FoundJob::query()->whereKey($keep)->value('status'))->toBe(FoundJobStatus::New);
    expect(FoundJob::query()->where('status', FoundJobStatus::New)->count())->toBe(28);
});

it('reports a duplicate link and an empty selection instead of failing', function (): void {
    Opportunity::query()->create(['company' => 'Acme', 'application_url' => 'https://x.test/d', 'normalized_application_url' => 'https://x.test/d']);
    $dupe = FoundJob::query()->create(['source' => 'builtin', 'url' => 'https://x.test/d', 'normalized_url' => 'https://x.test/d#', 'title' => 'Dup', 'company' => 'Acme']);
    $ok = FoundJob::query()->create(['source' => 'builtin', 'url' => 'https://x.test/e', 'normalized_url' => 'https://x.test/e', 'title' => 'Fine', 'company' => 'Acme']);

    Livewire::test('pages::job-finder')->call('addSelected')->assertSee('Select at least one')
        ->set('selected', [$dupe->id, $ok->id])->call('addSelected')->assertSee('1 added to your tracker; 1 skipped')->assertSet('selected', []);
    expect($dupe->refresh()->status)->toBe(FoundJobStatus::Dismissed)->and($ok->refresh()->status)->toBe(FoundJobStatus::Accepted);
});

it('is never scheduled', function (): void {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => $event->command.($event->description ?? ''));

    expect($commands->filter(fn (string $name): bool => str_contains(strtolower($name), 'scrap')))->toBeEmpty();
});

it('filters the found listings by source and ignores an unknown source', function (): void {
    FoundJob::query()->create(['source' => 'himalayas', 'url' => 'https://x.test/h', 'normalized_url' => 'https://x.test/h', 'title' => 'From Himalayas', 'company' => 'A']);
    FoundJob::query()->create(['source' => 'wellfound', 'url' => 'https://x.test/w', 'normalized_url' => 'https://x.test/w', 'title' => 'From Wellfound', 'company' => 'B']);

    Livewire::test('pages::job-finder')->set('source', 'wellfound')
        ->assertSee('From Wellfound')->assertDontSee('From Himalayas');
    Livewire::withQueryParams(['source' => 'nope'])->test('pages::job-finder')
        ->assertSee('From Wellfound')->assertSee('From Himalayas');
});
