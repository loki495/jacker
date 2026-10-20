<?php

declare(strict_types=1);

use App\Actions\ClaimAiRequest;
use App\Actions\CompleteAiRequest;
use App\Actions\RequestAiExtraction;
use App\Actions\RunLinkCheck;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\LinkCheck;
use App\Models\Opportunity;
use App\Services\PageText;
use App\Services\UrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host): array => ['93.184.216.34']));
});

function noDataCheck(?Opportunity $opportunity = null): LinkCheck
{
    return LinkCheck::query()->create([
        'opportunity_id' => $opportunity?->id, 'kind' => $opportunity instanceof Opportunity ? 'check' : 'import', 'url' => '',
        'status' => LinkCheckStatus::Failed, 'page_text' => "Acme\nWelcome", 'message' => 'no data',
    ]);
}

it('reduces html to readable capped text', function (): void {
    $html = '<html><head><style>.a{}</style><script>var x=1;</script></head><body><nav>Menu</nav><h1>PHP Dev</h1><p>Build&nbsp;APIs</p><ul><li>One</li><li>Two</li></ul></body></html>';
    $text = (new PageText)->extract($html, 1000);
    expect($text)->toContain("PHP Dev\nBuild APIs\nOne\nTwo")->not->toContain('Menu')->not->toContain('var x');
    expect(mb_strlen((new PageText)->extract($html, 5)))->toBe(5);
});

it('queues one request per eligible check and refuses duplicates or ineligible checks', function (): void {
    $check = noDataCheck();
    expect(app(RequestAiExtraction::class)->handle($check))->toBeInstanceOf(AiRequest::class)
        ->and(app(RequestAiExtraction::class)->handle($check))->toBeNull();
    $empty = noDataCheck();
    $empty->update(['page_text' => null]);
    expect(app(RequestAiExtraction::class)->handle($empty))->toBeNull();
});

it('claims the oldest queued request with a prompt that fences the page text', function (): void {
    $request = app(RequestAiExtraction::class)->handle(noDataCheck());
    $claimed = app(ClaimAiRequest::class)->handle();
    expect($claimed['id'])->toBe($request->id)->and($claimed['prompt'])->toContain("=====PAGE=====\nAcme\nWelcome\n=====END PAGE=====");
    expect($request->refresh()->status)->toBe(LinkCheckStatus::Running)->and(app(ClaimAiRequest::class)->handle())->toBeNull();
});

it('creates a listing from valid model output, dropping unknown keys and invalid enums', function (): void {
    $request = app(RequestAiExtraction::class)->handle(noDataCheck());
    $request->update(['status' => LinkCheckStatus::Running]);
    app(CompleteAiRequest::class)->handle($request->load('linkCheck'), "```json\n".json_encode(['company' => 'Acme', 'title' => 'PHP Dev', 'work_arrangement' => 'sideways', 'employment_type' => 'contract', 'salary_min' => '90000', 'evil' => 'x'])."\n```");
    $job = Opportunity::query()->firstOrFail();
    expect($job->company)->toBe('Acme')->and($job->employment_type->value)->toBe('contract')->and($job->work_arrangement->value)->toBe('unspecified')
        ->and($request->refresh()->status)->toBe(LinkCheckStatus::Done)->and($request->linkCheck->opportunity_id)->toBe($job->id);
});

it('fills only empty fields of an existing job', function (): void {
    $job = Opportunity::factory()->create(['title' => 'Keep me', 'location' => null]);
    $request = app(RequestAiExtraction::class)->handle(noDataCheck($job));
    $request->update(['status' => LinkCheckStatus::Running]);
    app(CompleteAiRequest::class)->handle($request->load('linkCheck.opportunity'), json_encode(['title' => 'Overwrite', 'location' => 'Austin, TX']));
    expect($job->refresh()->title)->toBe('Keep me')->and($job->location)->toBe('Austin, TX');
});

it('marks the request failed on unusable output without touching jobs', function (): void {
    $request = app(RequestAiExtraction::class)->handle(noDataCheck());
    $request->update(['status' => LinkCheckStatus::Running]);
    app(CompleteAiRequest::class)->handle($request->load('linkCheck'), 'Sorry, I cannot do that.');
    expect($request->refresh()->status)->toBe(LinkCheckStatus::Failed)->and($request->message)->toBe(__('jobs.ai_bad_output'))->and(Opportunity::query()->count())->toBe(0);
});

it('offers the AI button only after explicit click and queues through the page', function (): void {
    $check = noDataCheck();
    Livewire::test('pages::link-checks')->assertSee(__('jobs.ai_extract'))->call('readWithAi', $check->id)->assertSee(__('jobs.ai_queued'))->assertDontSee(__('jobs.ai_extract'));
    expect(AiRequest::query()->count())->toBe(1);
});

it('claims and completes through the artisan commands', function (): void {
    $request = app(RequestAiExtraction::class)->handle(noDataCheck());
    $this->artisan('ai:claim')->expectsOutputToContain('"id":'.$request->id)->assertSuccessful();
    $this->artisan('ai:complete', ['id' => $request->id, '--fail' => 'no reply'])->assertSuccessful();
    expect($request->refresh()->status)->toBe(LinkCheckStatus::Failed)->and($request->message)->toBe('no reply');
    $this->artisan('ai:complete', ['id' => $request->id, '--fail' => 'again'])->assertFailed();
});

it('shows the AI request state in the log from pending to its result', function (): void {
    $check = noDataCheck();
    $page = Livewire::test('pages::link-checks')->call('readWithAi', $check->id)->assertSee(__('jobs.ai_state_queued'))->assertDontSee(__('jobs.ai_extract'));
    $request = AiRequest::query()->firstOrFail();

    $request->update(['status' => LinkCheckStatus::Running]);
    $page->call('$refresh')->assertSee(__('jobs.ai_state_running'))->assertDontSee(__('jobs.ai_extract'));

    app(CompleteAiRequest::class)->handle($request->load('linkCheck'), 'Sorry, I cannot do that.');
    $page->call('$refresh')->assertSee(__('jobs.ai_state_failed', ['message' => __('jobs.ai_bad_output')]))->assertSee(__('jobs.ai_extract'))
        ->assertSee(__('jobs.ai_raw_reply'))->assertSee('Sorry, I cannot do that.');

    app(RequestAiExtraction::class)->handle($check->refresh());
    $second = AiRequest::query()->latest('id')->firstOrFail();
    $second->update(['status' => LinkCheckStatus::Running]);
    app(CompleteAiRequest::class)->handle($second->load('linkCheck'), json_encode(['company' => 'Acme', 'title' => 'Dev']));
    $page->call('$refresh')->assertSee('AI: ')->assertSee($second->refresh()->message);
});

it('keeps the page text of a page with structured data and allows an AI pass that only fills empty fields', function (): void {
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host): array => ['93.184.216.34']));
    Http::fake(['*' => Http::response('<html><head><script type="application/ld+json">{"@type":"JobPosting","title":"Dev","hiringOrganization":{"name":"Acme"},"description":"Build things"}</script></head><body><h1>Dev at Acme</h1><p>Hybrid role in Austin</p></body></html>')]);
    $job = Opportunity::factory()->create(['application_url' => 'https://jobs.example/9', 'title' => 'Mine', 'location' => null, 'hours' => null]);
    $check = LinkCheck::query()->create(['kind' => 'check', 'url' => $job->application_url, 'opportunity_id' => $job->id, 'status' => LinkCheckStatus::Queued]);
    app(RunLinkCheck::class)->handle($check);
    expect($check->refresh()->page_text)->toContain('Hybrid role in Austin');

    $request = app(RequestAiExtraction::class)->handle($check);
    expect($request)->toBeInstanceOf(AiRequest::class);
    $request->update(['status' => LinkCheckStatus::Running]);
    app(CompleteAiRequest::class)->handle($request->load('linkCheck.opportunity'), json_encode(['title' => 'Overwrite', 'location' => 'Austin, TX']));
    expect($job->refresh()->title)->toBe('Mine')->and($job->location)->toBe('Austin, TX');
});

it('lets an explicit AI pass replace long text fields and keeps the earlier values', function (): void {
    $job = Opportunity::factory()->create(['title' => 'Keep me', 'location' => 'Keep too', 'responsibilities' => 'Old cut-off text', 'requirements' => null, 'benefits' => 'Same']);
    $request = app(RequestAiExtraction::class)->handle(noDataCheck($job));
    $request->update(['status' => LinkCheckStatus::Running]);
    app(CompleteAiRequest::class)->handle($request->load('linkCheck.opportunity'), json_encode(['title' => 'Overwrite', 'location' => 'Overwrite', 'responsibilities' => "Full\nlist", 'requirements' => 'New reqs', 'benefits' => 'Same']));
    $job->refresh();
    expect($job->title)->toBe('Keep me')->and($job->location)->toBe('Keep too')->and($job->responsibilities)->toBe("Full\nlist")->and($job->requirements)->toBe('New reqs')
        ->and($request->refresh()->replaced)->toBe(['responsibilities' => 'Old cut-off text']);

    Livewire::test('pages::link-checks')->assertSee(__('jobs.ai_replaced', ['fields' => 'responsibilities']))->assertSee('Old cut-off text');
});
