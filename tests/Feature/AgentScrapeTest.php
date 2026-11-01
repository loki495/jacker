<?php

declare(strict_types=1);

use App\Actions\RequestAgentApply;
use App\Actions\RequestAgentScrape;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\Opportunity;
use App\Support\AgentFiles;
use App\Support\AgentWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('hides the button and refuses requests while the worker is offline', function (): void {
    Livewire::test('pages::job-finder')->assertDontSee(__('scrapers.agent_run'));
    expect(app(RequestAgentScrape::class)->handle())->toBeNull()->and(AiRequest::query()->count())->toBe(0);
});

it('shows the button once the worker reports in and goes stale after the grace period', function (): void {
    $this->artisan('agent:register')->assertSuccessful();
    Livewire::test('pages::job-finder')->assertSee(__('scrapers.agent_run'));
    $this->travel(AgentWorker::STALE + 1)->seconds();
    expect(AgentWorker::online())->toBeFalse();
});

it('queues one run at a time, claims it and records the result', function (): void {
    AgentWorker::register();
    Livewire::test('pages::job-finder')->call('agentScrape')->assertSee(__('scrapers.agent_queued'))->call('agentScrape')->assertSee(__('scrapers.agent_unavailable'));
    $request = AiRequest::query()->firstOrFail();
    expect($request->kind)->toBe(AiRequest::KIND_SCRAPE)->and($request->opportunity_id)->toBeNull();

    $this->artisan('agent:claim')->expectsOutput(json_encode(['id' => $request->id, 'kind' => 'scrape', 'opportunity_id' => null, 'url' => null]))->assertSuccessful();
    $this->artisan('agent:claim')->doesntExpectOutputToContain('scrape')->assertSuccessful();
    $this->artisan('agent:complete', ['id' => $request->id, '--message' => 'Imported 3'])->assertSuccessful();
    $this->artisan('agent:complete', ['id' => $request->id])->assertFailed();

    $request->refresh();
    expect($request->status)->toBe(LinkCheckStatus::Done)->and($request->message)->toBe('Imported 3')->and(app(RequestAgentScrape::class)->handle())->toBeInstanceOf(AiRequest::class);
});

it('marks a failed run failed', function (): void {
    AgentWorker::register();
    $request = app(RequestAgentScrape::class)->handle();
    $this->artisan('agent:claim')->assertSuccessful();
    $this->artisan('agent:complete', ['id' => $request->id, '--fail' => true, '--message' => 'no CSV'])->assertSuccessful();
    expect($request->refresh()->status)->toBe(LinkCheckStatus::Failed);
});

it('queues a custom CV and letter for one job with a web link, once, and only while the worker is online', function (): void {
    $job = Opportunity::factory()->create(['application_url' => 'https://example.com/jobs/1']);
    expect(app(RequestAgentApply::class)->handle($job))->toBeNull();
    AgentWorker::register();
    Livewire::test('pages::detail', ['opportunity' => $job])->call('generateApplication')->assertSee(__('jobs.apply_queued'));
    expect(app(RequestAgentApply::class)->handle($job))->toBeNull()->and(AiRequest::query()->where('kind', AiRequest::KIND_APPLY)->count())->toBe(1);

    $request = AiRequest::query()->firstOrFail();
    $this->artisan('agent:claim')->expectsOutput(json_encode(['id' => $request->id, 'kind' => 'apply', 'opportunity_id' => $job->id, 'url' => 'https://example.com/jobs/1']))->assertSuccessful();
});

it('refuses jobs without a plain http(s) link', function (string $url): void {
    AgentWorker::register();
    expect(app(RequestAgentApply::class)->handle(Opportunity::factory()->create(['application_url' => $url])))->toBeNull();
})->with(['', 'javascript:alert(1)', 'https://example.com/a b', 'https://example.com/"x', 'ftp://example.com/x']);

it('serves a drafted PDF only for the known file names and only when it exists', function (): void {
    $scratch = sys_get_temp_dir().'/jacker-agent-test-'.bin2hex(random_bytes(4));
    app()->useStoragePath($scratch);
    $job = Opportunity::factory()->create();
    $this->get(route('opportunities.application-file', [$job, 'cv']))->assertNotFound();
    $this->get('/opportunities/'.$job->id.'/application/..%2Fx')->assertNotFound();
    $dir = dirname(AgentFiles::path($job, 'cv'));
    mkdir($dir, 0755, true);
    file_put_contents(AgentFiles::path($job, 'cv'), '%PDF-1.4 fake');
    try {
        $this->get(route('opportunities.application-file', [$job, 'cv']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(AgentFiles::available($job))->toBe(['cv']);
    } finally {
        unlink(AgentFiles::path($job, 'cv'));
        rmdir($dir);
        rmdir(dirname($dir));
        rmdir(dirname($dir, 2));
        rmdir(dirname($dir, 3));
        rmdir($scratch);
    }
});
