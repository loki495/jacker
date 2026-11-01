<?php

declare(strict_types=1);

use App\Actions\RequestAgentScrape;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
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

    $this->artisan('agent:claim')->expectsOutput((string) $request->id)->assertSuccessful();
    $this->artisan('agent:claim')->doesntExpectOutputToContain((string) $request->id)->assertSuccessful();
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
