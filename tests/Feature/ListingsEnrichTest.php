<?php

declare(strict_types=1);

use App\Actions\ClaimAiRequest;
use App\Actions\ClaimPageRender;
use App\Actions\CompleteAiRequest;
use App\Actions\CompletePageRender;
use App\Actions\EnrichListings;
use App\Actions\ListKnownListings;
use App\Actions\QueueLinkChecks;
use App\Actions\RequestAiExtraction;
use App\Enums\LinkCheckStatus;
use App\Enums\LinkResult;
use App\Jobs\ProcessLinkCheck;
use App\Models\AiRequest;
use App\Models\LinkCheck;
use App\Models\Opportunity;
use App\Services\UrlGuard;
use App\Support\ApplicationUrl;
use App\Support\ListingGaps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

const ENRICH_EMPTY_PAGE = '<html><body><script>var x = 1;</script></body></html>';

const ENRICH_TEXT_PAGE = '<html><body><h1>Plain posting</h1><p>Some text</p></body></html>';

beforeEach(function (): void {
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host): array => ['93.184.216.34']));
    config(['ai.features.extract.driver' => 'bridge']);
});

/** A listing with every enrichable field filled, so it has no gaps. */
function enrichComplete(array $attributes = []): Opportunity
{
    $url = $attributes['application_url'] ?? 'https://full.test/'.fake()->unique()->numberBetween(1, 99999);

    return Opportunity::factory()->create($attributes + [
        'application_url' => $url, 'normalized_application_url' => ApplicationUrl::normalize($url), 'source' => 's', 'company_url' => 'https://a.test',
        'location' => 'l', 'remote_restrictions' => 'r', 'responsibilities' => 'x', 'requirements' => 'x', 'benefits' => 'x', 'hours' => 'x', 'contact' => 'x', 'notes' => 'x',
        'salary_notes' => 'x', 'salary_min' => 1, 'salary_max' => 2, 'employment_type' => 'full_time', 'work_arrangement' => 'remote', 'deadline' => '2030-01-01',
    ]);
}

/** A listing with an application link and gaps (the factory leaves most optional fields empty). */
function enrichGappy(array $attributes = []): Opportunity
{
    $url = $attributes['application_url'] ?? 'https://jobs.example/'.fake()->unique()->numberBetween(1, 99999);

    return Opportunity::factory()->create($attributes + ['application_url' => $url, 'normalized_application_url' => ApplicationUrl::normalize($url)]);
}

function enrichCheck(Opportunity $job, array $attributes = []): LinkCheck
{
    $check = LinkCheck::query()->create(array_merge(['kind' => 'check', 'url' => $job->application_url, 'opportunity_id' => $job->id, 'status' => LinkCheckStatus::Done, 'result' => LinkResult::Ok, 'enrich' => true], $attributes));
    if (isset($attributes['created_at'])) {
        $check->forceFill(['created_at' => $attributes['created_at'], 'finished_at' => $attributes['created_at']])->saveQuietly();
    }

    return $check;
}

// Intent: the schema additions the rest of the feature stores its state in.
it('adds link_checks.enrich defaulting to false and keeps ai_requests.mode for the fill only constant', function (): void {
    expect(Schema::hasColumn('link_checks', 'enrich'))->toBeTrue()->and(Schema::hasColumn('ai_requests', 'mode'))->toBeTrue()->and(AiRequest::MODE_FILL_ONLY)->toBe('fill_only');
    $check = LinkCheck::query()->create(['kind' => 'check', 'url' => 'https://jobs.example/1', 'status' => LinkCheckStatus::Queued]);
    expect((bool) $check->refresh()->enrich)->toBeFalse();
});

// Intent: ListingGaps is the extracted anyMissing group; ListKnownListings must keep returning exactly the same rows.
it('matches listings with any enrichable gap and skips complete ones', function (): void {
    $full = enrichComplete();
    $noLocation = enrichComplete(['location' => null]);
    $blankText = enrichComplete(['requirements' => '']);
    $unspecifiedType = enrichComplete(['employment_type' => 'unspecified']);
    $noDeadline = enrichComplete(['deadline' => null]);
    $noSalary = enrichComplete(['salary_min' => null, 'salary_max' => null]);
    $oneSalary = enrichComplete(['salary_max' => null]);

    $ids = Opportunity::query()->where(fn (Builder $q): Builder => ListingGaps::apply($q))->orderBy('id')->pluck('id')->all();
    expect($ids)->toBe([$noLocation->id, $blankText->id, $unspecifiedType->id, $noDeadline->id, $noSalary->id])
        ->and($ids)->not->toContain($full->id, $oneSalary->id);
});

it('does not count extra fields or pay period as gaps', function (): void {
    $job = enrichComplete(['extra_fields' => null, 'pay_period' => 'unspecified']);
    expect(Opportunity::query()->where(fn (Builder $q): Builder => ListingGaps::apply($q))->whereKey($job->id)->exists())->toBeFalse();
});

it('keeps ListKnownListings only_missing identical to the gap group', function (): void {
    enrichComplete(['company' => 'Full']);
    enrichComplete(['company' => 'Sparse', 'location' => null]);
    $listed = collect(app(ListKnownListings::class)->handle(onlyMissing: true)['listings'])->pluck('company')->all();
    expect($listed)->toBe(['Sparse']);
});

// Intent: forOpportunities marks enrich checks; the default stays a plain check.
it('flags checks queued for enrichment and leaves ordinary checks unflagged', function (): void {
    Bus::fake();
    $a = enrichGappy();
    $b = enrichGappy();
    expect(app(QueueLinkChecks::class)->forOpportunities(collect([$a]), true))->toBe(1)
        ->and(app(QueueLinkChecks::class)->forOpportunities(collect([$b])))->toBe(1);
    expect((bool) LinkCheck::query()->where('opportunity_id', $a->id)->sole()->enrich)->toBeTrue()
        ->and((bool) LinkCheck::query()->where('opportunity_id', $b->id)->sole()->enrich)->toBeFalse();
});

// Intent: a dead enrich check reports the link state but never closes the listing; ordinary dead checks still close.
it('marks a dead listing dead without closing it on an enrich check, and still closes on a plain check', function (): void {
    Http::fake(['*' => Http::response('gone', 404)]);
    $enrich = enrichGappy();
    $plain = enrichGappy();
    app(QueueLinkChecks::class)->forOpportunities(collect([$enrich]), true);
    app(QueueLinkChecks::class)->forOpportunities(collect([$plain]));
    expect($enrich->refresh()->link_status)->toBe('dead')->and($enrich->status->value)->toBe('saved')
        ->and($plain->refresh()->link_status)->toBe('dead')->and($plain->status->value)->toBe('closed');
    expect(LinkCheck::query()->where('opportunity_id', $enrich->id)->sole()->result)->toBe(LinkResult::Dead);
});

// Intent: the extraction chain after an enrich check. Ok and blocked chain; dead, error and failed do not; plain checks never do.
it('chains a fill only extraction behind a queued render when the enrich check has no page text', function (): void {
    Http::fake(['*' => Http::response(ENRICH_EMPTY_PAGE)]);
    $job = enrichGappy();
    app(QueueLinkChecks::class)->forOpportunities(collect([$job]), true);
    $check = LinkCheck::query()->where('opportunity_id', $job->id)->sole();

    expect($check->page_text)->toBeNull()->and($check->result)->toBe(LinkResult::Ok);
    $render = AiRequest::query()->where('kind', 'render')->sole();
    $extract = AiRequest::query()->where('kind', 'extract')->sole();
    expect($render->link_check_id)->toBe($check->id)->and($render->status)->toBe(LinkCheckStatus::Queued)
        ->and($extract->link_check_id)->toBe($check->id)->and($extract->mode)->toBe(AiRequest::MODE_FILL_ONLY)->and($extract->status)->toBe(LinkCheckStatus::Queued);

    expect(app(ClaimAiRequest::class)->handle())->toBeNull();
    app(ClaimPageRender::class)->handle();
    expect(app(ClaimAiRequest::class)->handle())->toBeNull();
    app(CompletePageRender::class)->handle($render->refresh()->load('linkCheck'), 'Rendered posting text');
    $claimed = app(ClaimAiRequest::class)->handle();
    expect($claimed['id'])->toBe($extract->id)->and(preg_match('/<<<PAGE-([0-9a-f]{24})>>>\nRendered posting text\n<<<END-PAGE-\1>>>/', $claimed['prompt']))->toBe(1);
});

it('chains a fill only extraction for an ok enrich check that kept page text', function (): void {
    Http::fake(['*' => Http::response(ENRICH_TEXT_PAGE)]);
    $job = enrichGappy();
    app(QueueLinkChecks::class)->forOpportunities(collect([$job]), true);
    expect(AiRequest::query()->where('kind', 'extract')->sole()->mode)->toBe(AiRequest::MODE_FILL_ONLY)
        ->and(LinkCheck::query()->where('opportunity_id', $job->id)->sole()->page_text)->toContain('Plain posting');
});

it('still chains an extraction when the enrich check was blocked', function (): void {
    Http::fake(['*' => Http::response('denied', 403)]);
    $job = enrichGappy();
    app(QueueLinkChecks::class)->forOpportunities(collect([$job]), true);
    expect(LinkCheck::query()->where('opportunity_id', $job->id)->sole()->result)->toBe(LinkResult::Blocked)
        ->and(AiRequest::query()->where('kind', 'extract')->sole()->mode)->toBe(AiRequest::MODE_FILL_ONLY);
});

it('does not chain an extraction for dead, error or failed enrich checks', function (int $status): void {
    Http::fake(['*' => Http::response('nope', $status)]);
    $job = enrichGappy();
    app(QueueLinkChecks::class)->forOpportunities(collect([$job]), true);
    expect(AiRequest::query()->count())->toBe(0);
})->with([404, 410, 500]);

it('does not chain an extraction when the fetch itself throws', function (): void {
    Http::fake(fn () => throw new RuntimeException('boom'));
    $job = enrichGappy();
    app(QueueLinkChecks::class)->forOpportunities(collect([$job]), true);
    expect(LinkCheck::query()->where('opportunity_id', $job->id)->sole()->status)->toBe(LinkCheckStatus::Failed)->and(AiRequest::query()->count())->toBe(0);
});

it('does not chain an extraction for an ordinary check', function (): void {
    Http::fake(['*' => Http::response(ENRICH_TEXT_PAGE)]);
    $job = enrichGappy();
    $check = LinkCheck::query()->create(['kind' => 'check', 'url' => $job->application_url, 'opportunity_id' => $job->id, 'status' => LinkCheckStatus::Queued]);
    ProcessLinkCheck::dispatchSync($check->id);
    expect($check->refresh()->status)->toBe(LinkCheckStatus::Done)->and(AiRequest::query()->count())->toBe(0);
});

// Intent: RequestAiExtraction stores the mode it is given and stays unchanged (null) otherwise.
it('stores the extraction mode and leaves it null by default', function (): void {
    $check = LinkCheck::query()->create(['kind' => 'import', 'url' => '', 'status' => LinkCheckStatus::Failed, 'page_text' => 'Acme']);
    $other = LinkCheck::query()->create(['kind' => 'import', 'url' => '', 'status' => LinkCheckStatus::Failed, 'page_text' => 'Acme']);
    expect(app(RequestAiExtraction::class)->handle($check, AiRequest::MODE_FILL_ONLY)->mode)->toBe('fill_only')
        ->and(app(RequestAiExtraction::class)->handle($other)->mode)->toBeNull();
});

// Intent: a fill only completion never replaces a value the listing already has, and never reports replacements.
it('fills only empty fields in fill only mode and keeps existing long text and the apply link', function (): void {
    $job = Opportunity::factory()->create([
        'application_url' => 'https://www.linkedin.com/jobs/view/1', 'normalized_application_url' => ApplicationUrl::normalize('https://www.linkedin.com/jobs/view/1'),
        'title' => 'Keep title', 'benefits' => 'Old benefits', 'responsibilities' => 'Old duties', 'requirements' => null, 'hours' => null, 'location' => null,
    ]);
    $check = LinkCheck::query()->create(['opportunity_id' => $job->id, 'kind' => 'check', 'url' => $job->application_url, 'status' => LinkCheckStatus::Done, 'page_text' => "Role\nApply at https://acme.example/apply/9"]);
    $request = app(RequestAiExtraction::class)->handle($check, AiRequest::MODE_FILL_ONLY);
    $request->update(['status' => LinkCheckStatus::Running]);
    app(CompleteAiRequest::class)->handle($request->load('linkCheck.opportunity'), json_encode([
        'title' => 'Other', 'benefits' => 'New benefits', 'responsibilities' => 'New duties', 'requirements' => 'New reqs', 'hours' => '9 to 5', 'location' => 'Austin, TX',
        'application_url' => 'https://acme.example/apply/9',
    ]));
    $job->refresh();
    expect($job->title)->toBe('Keep title')->and($job->benefits)->toBe('Old benefits')->and($job->responsibilities)->toBe('Old duties')
        ->and($job->requirements)->toBe('New reqs')->and($job->hours)->toBe('9 to 5')->and($job->location)->toBe('Austin, TX')
        ->and($job->application_url)->toBe('https://www.linkedin.com/jobs/view/1');
    $request->refresh();
    expect($request->status)->toBe(LinkCheckStatus::Done)->and($request->replaced)->toBeNull();
});

it('keeps the explicit pass replacing long text when the mode is not fill only', function (): void {
    $job = Opportunity::factory()->create(['benefits' => 'Old benefits']);
    $check = LinkCheck::query()->create(['opportunity_id' => $job->id, 'kind' => 'check', 'url' => '', 'status' => LinkCheckStatus::Done, 'page_text' => 'Acme']);
    $request = app(RequestAiExtraction::class)->handle($check);
    $request->update(['status' => LinkCheckStatus::Running]);
    app(CompleteAiRequest::class)->handle($request->load('linkCheck.opportunity'), json_encode(['benefits' => 'New benefits']));
    expect($job->refresh()->benefits)->toBe('New benefits')->and($request->refresh()->replaced)->toBe(['benefits' => 'Old benefits']);
});

// Intent: EnrichListings candidate selection, cap, cooldown, linkedin skip and dry run.
it('picks unarchived unclosed listings with a link and gaps, in id order, capped by the limit', function (): void {
    Bus::fake();
    $first = enrichGappy();
    enrichComplete();
    enrichGappy(['archived_at' => now()]);
    enrichGappy(['status' => 'closed']);
    Opportunity::factory()->create(['application_url' => null]);
    $second = enrichGappy();
    $third = enrichGappy();

    $result = app(EnrichListings::class)->handle(2, false);
    expect(collect($result['candidates'])->pluck('id')->all())->toBe([$first->id, $second->id])->and($result['queued'])->toBe(2)
        ->and($result['skipped_linkedin'])->toBe(0)
        ->and(LinkCheck::query()->where('enrich', true)->pluck('opportunity_id')->all())->toBe([$first->id, $second->id])
        ->and(LinkCheck::query()->where('opportunity_id', $third->id)->exists())->toBeFalse();
});

it('skips and counts linkedin hosts', function (): void {
    Bus::fake();
    enrichGappy(['application_url' => 'https://www.linkedin.com/jobs/view/1']);
    enrichGappy(['application_url' => 'https://linkedin.com/jobs/view/2']);
    $other = enrichGappy();
    $result = app(EnrichListings::class)->handle(10, false);
    expect(collect($result['candidates'])->pluck('id')->all())->toBe([$other->id])->and($result['skipped_linkedin'])->toBe(2)->and($result['queued'])->toBe(1)
        ->and(LinkCheck::query()->count())->toBe(1);
});

it('skips listings with a pending check', function (): void {
    Bus::fake();
    $pending = enrichGappy();
    LinkCheck::query()->create(['kind' => 'check', 'url' => $pending->application_url, 'opportunity_id' => $pending->id, 'status' => LinkCheckStatus::Queued]);
    $running = enrichGappy();
    LinkCheck::query()->create(['kind' => 'check', 'url' => $running->application_url, 'opportunity_id' => $running->id, 'status' => LinkCheckStatus::Running, 'enrich' => true]);
    $free = enrichGappy();
    $result = app(EnrichListings::class)->handle(10, false);
    expect(collect($result['candidates'])->pluck('id')->all())->toBe([$free->id]);
});

it('honours the enrich cooldown and ignores ordinary checks', function (): void {
    Bus::fake();
    expect(config('linkcheck.enrich_cooldown_days'))->toBe(7);
    $recent = enrichGappy();
    enrichCheck($recent, ['created_at' => now()->subDays(6)]);
    $old = enrichGappy();
    enrichCheck($old, ['created_at' => now()->subDays(8)]);
    $plainRecent = enrichGappy();
    enrichCheck($plainRecent, ['enrich' => false, 'created_at' => now()->subDay()]);
    $never = enrichGappy();

    $result = app(EnrichListings::class)->handle(10, false);
    expect(collect($result['candidates'])->pluck('id')->all())->toBe([$old->id, $plainRecent->id, $never->id]);
});

it('reads the cooldown from config', function (): void {
    Bus::fake();
    config(['linkcheck.enrich_cooldown_days' => 2]);
    $job = enrichGappy();
    enrichCheck($job, ['created_at' => now()->subDays(3)]);
    $result = app(EnrichListings::class)->handle(10, false);
    expect(collect($result['candidates'])->pluck('id')->all())->toBe([$job->id]);
});

it('writes nothing on a dry run but still lists candidates and counts linkedin', function (): void {
    Http::fake();
    Bus::fake();
    $job = enrichGappy();
    enrichGappy(['application_url' => 'https://www.linkedin.com/jobs/view/1']);
    $result = app(EnrichListings::class)->handle(5, true);
    expect(collect($result['candidates'])->pluck('id')->all())->toBe([$job->id])->and($result['queued'])->toBe(0)->and($result['skipped_linkedin'])->toBe(1)
        ->and(LinkCheck::query()->count())->toBe(0)->and(AiRequest::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});

it('returns an empty result when nothing needs enriching', function (): void {
    $result = app(EnrichListings::class)->handle(5, false);
    expect($result['candidates'])->toBe([])->and($result['queued'])->toBe(0)->and($result['skipped_linkedin'])->toBe(0)->and($result['gone'])->toHaveCount(0);
});

it('reports unclosed dead listings as gone without changing them', function (): void {
    Bus::fake();
    $gone = enrichComplete(['link_status' => 'dead', 'company' => 'Gone Inc']);
    enrichComplete(['link_status' => 'dead', 'status' => 'closed']);
    enrichComplete(['link_status' => 'ok']);
    $result = app(EnrichListings::class)->handle(5, true);
    expect($result['gone'])->toHaveCount(1)->and($result['gone']->first()->id)->toBe($gone->id)->and($gone->refresh()->status->value)->toBe('saved');
});

// Intent: the full run through the sync queue, with the page fetch faked.
it('queues, fetches and chains a whole enrich run and leaves a dead listing open and reported', function (): void {
    Http::fake(['https://dead.example/*' => Http::response('gone', 404), 'https://live.example/*' => Http::response(ENRICH_EMPTY_PAGE)]);
    $dead = enrichGappy(['application_url' => 'https://dead.example/1', 'company' => 'Deadco', 'title' => 'Dead role']);
    $live = enrichGappy(['application_url' => 'https://live.example/1']);

    $result = app(EnrichListings::class)->handle(5, false);
    expect($result['queued'])->toBe(2)->and($dead->refresh()->link_status)->toBe('dead')->and($dead->status->value)->toBe('saved')
        ->and($live->refresh()->link_status)->toBe('ok')
        ->and(AiRequest::query()->where('kind', 'extract')->sole()->linkCheck->opportunity_id)->toBe($live->id)
        ->and($result['gone']->pluck('id')->all())->toBe([$dead->id]);
});

// Intent: the artisan command, its limit validation and its output; it never changes status.
it('rejects a limit that is not an integer from 1 to 50 and does nothing', function (string $limit): void {
    Http::fake();
    enrichGappy();
    $exit = Artisan::call('listings:enrich', ['--limit' => $limit]);
    expect($exit)->toBe(1)->and(trim(Artisan::output()))->not->toBe('')->and(LinkCheck::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['0', '51', '-1', 'abc', '2.5', '', '1e1']);

it('accepts the boundary limits and defaults to five', function (): void {
    Bus::fake();
    foreach (range(1, 7) as $ignored) {
        enrichGappy();
    }
    $this->artisan('listings:enrich', ['--limit' => 1])->assertSuccessful();
    expect(LinkCheck::query()->count())->toBe(1);
    $this->artisan('listings:enrich', ['--limit' => 50])->assertSuccessful();
    expect(LinkCheck::query()->count())->toBe(7);
});

it('queues five by default', function (): void {
    Bus::fake();
    foreach (range(1, 7) as $ignored) {
        enrichGappy();
    }
    $this->artisan('listings:enrich')->assertSuccessful();
    expect(LinkCheck::query()->where('enrich', true)->count())->toBe(5);
});

it('writes nothing with --dry-run and still succeeds', function (): void {
    Http::fake();
    Bus::fake();
    enrichGappy();
    $this->artisan('listings:enrich', ['--dry-run' => true])->assertSuccessful();
    expect(LinkCheck::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('prints the found gone list with id, company and title and never closes the listing', function (): void {
    Http::fake(['*' => Http::response('gone', 404)]);
    $dead = enrichGappy(['company' => 'Deadco', 'title' => 'Dead role']);
    $this->artisan('listings:enrich', ['--limit' => 5])
        ->expectsOutputToContain('Found gone (status not changed)')->expectsOutputToContain('Deadco')->expectsOutputToContain('Dead role')->expectsOutputToContain((string) $dead->id)
        ->assertSuccessful();
    expect($dead->refresh()->status->value)->toBe('saved')->and($dead->link_status)->toBe('dead');
});

it('omits the found gone list when nothing is gone', function (): void {
    Bus::fake();
    enrichGappy();
    $this->artisan('listings:enrich')->doesntExpectOutputToContain('Found gone (status not changed)')->assertSuccessful();
});
