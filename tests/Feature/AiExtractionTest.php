<?php

declare(strict_types=1);

use App\Actions\ClaimAiRequest;
use App\Actions\CompleteAiRequest;
use App\Actions\RequestAiExtraction;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\LinkCheck;
use App\Models\Opportunity;
use App\Services\PageText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function noDataCheck(?Opportunity $opportunity = null): LinkCheck
{
    return LinkCheck::query()->create([
        'opportunity_id' => $opportunity?->id, 'kind' => $opportunity instanceof Opportunity ? 'check' : 'import', 'url' => 'https://jobs.example/p/1',
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
