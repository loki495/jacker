<?php

declare(strict_types=1);

use App\Actions\ClaimAiRequest;
use App\Actions\ClaimPageRender;
use App\Actions\CompletePageRender;
use App\Actions\RequestAiExtraction;
use App\Actions\RequestPageRender;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\LinkCheck;
use App\Services\UrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host): array => $host === 'internal.test' ? ['192.168.1.5'] : ['93.184.216.34']));
});

function checkFor(string $url, string $kind = 'check'): LinkCheck
{
    return LinkCheck::query()->create(['kind' => $kind, 'url' => $url, 'status' => LinkCheckStatus::Done, 'page_text' => 'Jobs']);
}

it('queues one render per fetched public link and refuses saved text, private addresses and duplicates', function (): void {
    $check = checkFor('https://jobs.example/1');
    expect(app(RequestPageRender::class)->handle($check))->toBeInstanceOf(AiRequest::class)->and(app(RequestPageRender::class)->handle($check))->toBeNull()
        ->and(app(RequestPageRender::class)->handle(checkFor('', 'saved')))->toBeNull()
        ->and(app(RequestPageRender::class)->handle(checkFor('http://internal.test/job')))->toBeNull()
        ->and(app(RequestPageRender::class)->handle(checkFor('ftp://jobs.example/x')))->toBeNull()
        ->and(AiRequest::query()->where('kind', 'render')->count())->toBe(1);
});

it('claims renders separately from AI extractions and replaces the page text on completion', function (): void {
    $check = checkFor('https://jobs.example/1');
    $render = app(RequestPageRender::class)->handle($check);
    expect(app(ClaimPageRender::class)->handle())->toBe(['id' => $render->id, 'url' => 'https://jobs.example/1'])->and(app(ClaimPageRender::class)->handle())->toBeNull();

    app(CompletePageRender::class)->handle($render->refresh()->load('linkCheck'), "Senior Engineer\n\n   Remote   role\nApply now");
    expect($render->refresh()->status)->toBe(LinkCheckStatus::Done)->and($check->refresh()->page_text)->toBe("Senior Engineer\nRemote role\nApply now");
});

it('fails an empty render and a request whose address turned private, without touching the page text', function (): void {
    $check = checkFor('https://jobs.example/1');
    $render = app(RequestPageRender::class)->handle($check);
    $render->update(['status' => LinkCheckStatus::Running]);
    app(CompletePageRender::class)->handle($render->load('linkCheck'), "  \n ");
    expect($render->refresh()->status)->toBe(LinkCheckStatus::Failed)->and($render->message)->toBe(__('jobs.render_empty'))->and($check->refresh()->page_text)->toBe('Jobs');

    $stale = AiRequest::query()->create(['link_check_id' => checkFor('http://internal.test/job')->id, 'kind' => 'render']);
    expect(app(ClaimPageRender::class)->handle())->toBeNull()->and($stale->refresh()->status)->toBe(LinkCheckStatus::Failed);
});

it('answers render:allow and drives claim and complete through artisan', function (): void {
    $this->artisan('render:allow', ['url' => 'https://jobs.example/a'])->expectsOutput('93.184.216.34')->assertSuccessful();
    $this->artisan('render:allow', ['url' => 'http://internal.test/a'])->assertFailed();
    $this->artisan('render:allow', ['url' => 'file:///etc/passwd'])->assertFailed();

    $render = app(RequestPageRender::class)->handle(checkFor('https://jobs.example/1'));
    $this->artisan('render:claim')->expectsOutputToContain('"id":'.$render->id)->assertSuccessful();
    $this->artisan('render:complete', ['id' => $render->id, '--fail' => 'timed out'])->assertSuccessful();
    expect($render->refresh()->status)->toBe(LinkCheckStatus::Failed)->and($render->message)->toBe('timed out');
    $this->artisan('render:complete', ['id' => $render->id, '--fail' => 'again'])->assertFailed();
});

it('queues the render before the AI pass from one button and holds the extraction until the render ends', function (): void {
    $check = checkFor('https://jobs.example/1');
    $page = Livewire::test('pages::link-checks')->assertSee(__('jobs.ai_extract'))->call('readWithAi', $check->id)
        ->assertSee(__('jobs.render_state_queued'))->assertSee(__('jobs.ai_state_queued'))->assertDontSee(__('jobs.ai_extract'));
    $render = AiRequest::query()->where('kind', 'render')->firstOrFail();
    expect(AiRequest::query()->where('kind', 'extract')->count())->toBe(1)->and(app(ClaimAiRequest::class)->handle())->toBeNull();

    app(ClaimPageRender::class)->handle();
    expect(app(ClaimAiRequest::class)->handle())->toBeNull();
    app(CompletePageRender::class)->handle($render->refresh()->load('linkCheck'), 'Rendered posting text');
    $claimed = app(ClaimAiRequest::class)->handle();
    expect(preg_match('/<<<PAGE-([0-9a-f]{24})>>>\nRendered posting text\n<<<END-PAGE-\1>>>/', $claimed['prompt']))->toBe(1);
    $page->call('$refresh')->assertSee(__('jobs.render_state_done', ['message' => $render->refresh()->message]));
});

it('still runs the AI pass on the old text when the render fails, and skips the render for saved text', function (): void {
    $check = checkFor('https://jobs.example/1');
    app(RequestAiExtraction::class)->handle($check);
    $render = AiRequest::query()->where('kind', 'render')->firstOrFail();
    $render->update(['status' => LinkCheckStatus::Running]);
    app(CompletePageRender::class)->fail($render, 'timed out');
    expect(preg_match('/<<<PAGE-([0-9a-f]{24})>>>\nJobs\n<<<END-PAGE-\1>>>/', app(ClaimAiRequest::class)->handle()['prompt']))->toBe(1);

    app(RequestAiExtraction::class)->handle(checkFor('', 'saved'));
    expect(AiRequest::query()->where('kind', 'render')->count())->toBe(1);
    expect(app(RequestAiExtraction::class)->handle(LinkCheck::query()->create(['kind' => 'saved', 'url' => '', 'status' => LinkCheckStatus::Done])))->toBeNull();
});

it('keeps the latest render and the latest AI request apart when one is newer than the other', function (): void {
    $check = checkFor('https://jobs.example/1');
    $extract = AiRequest::query()->create(['link_check_id' => $check->id, 'kind' => 'extract']);
    $render = AiRequest::query()->create(['link_check_id' => $check->id, 'kind' => 'render']);
    $fresh = LinkCheck::query()->with('latestAiRequest', 'latestRenderRequest')->findOrFail($check->id);
    expect($fresh->latestAiRequest->id)->toBe($extract->id)->and($fresh->latestRenderRequest->id)->toBe($render->id);
});
