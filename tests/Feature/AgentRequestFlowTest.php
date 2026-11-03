<?php

declare(strict_types=1);

use App\Actions\ClaimPendingAiRequest;
use App\Actions\RequestAgentApply;
use App\Enums\AiDriver;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\Opportunity;
use App\Services\UrlGuard;
use App\Support\AgentWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => app()->instance(UrlGuard::class, new UrlGuard(fn (string $host): array => $host === 'internal.test' ? ['192.168.1.5'] : ['93.184.216.34'])));

function agentRequest(string $kind, ?Opportunity $job = null, array $attributes = []): AiRequest
{
    return AiRequest::query()->create($attributes + ['kind' => $kind, 'opportunity_id' => $job?->id, 'driver' => AiDriver::Bridge, 'agent' => 'claude']);
}

function publicJob(string $path = '1'): Opportunity
{
    return Opportunity::factory()->create(['application_url' => 'https://example.com/jobs/'.$path]);
}

it('claims a scrape before an apply even when the apply was queued first', function (): void {
    $apply = agentRequest(AiRequest::KIND_APPLY, publicJob());
    $scrape = agentRequest(AiRequest::KIND_SCRAPE);

    $this->artisan('agent:claim')->expectsOutputToContain('"kind":"scrape"')->assertSuccessful();
    expect($scrape->refresh()->status)->toBe(LinkCheckStatus::Running)->and($apply->refresh()->status)->toBe(LinkCheckStatus::Queued);

    $this->artisan('agent:claim')->expectsOutputToContain('"kind":"apply"')->assertSuccessful();
    expect($apply->refresh()->status)->toBe(LinkCheckStatus::Running);
});

it('fails apply requests with an unusable link and carries on to the next good one in the same call', function (): void {
    $gone = agentRequest(AiRequest::KIND_APPLY, publicJob('a'));
    $internal = agentRequest(AiRequest::KIND_APPLY, publicJob('b'));
    $good = agentRequest(AiRequest::KIND_APPLY, publicJob('c'));
    $gone->opportunity()->update(['application_url' => null]);
    $internal->opportunity()->update(['application_url' => 'https://internal.test/job']);

    $this->artisan('agent:claim')->expectsOutputToContain('"id":'.$good->id)->assertSuccessful();

    expect($gone->refresh()->status)->toBe(LinkCheckStatus::Failed)->and($gone->message)->toBe(__('jobs.apply_bad_link'))->and($gone->finished_at)->not->toBeNull()
        ->and($internal->refresh()->status)->toBe(LinkCheckStatus::Failed)
        ->and($good->refresh()->status)->toBe(LinkCheckStatus::Running);
});

it('fails an apply request whose job was deleted', function (): void {
    $request = agentRequest(AiRequest::KIND_APPLY);

    $this->artisan('agent:claim')->doesntExpectOutputToContain('apply')->assertSuccessful();
    expect($request->refresh()->status)->toBe(LinkCheckStatus::Failed)->and($request->message)->toBe(__('jobs.apply_bad_link'));
});

it('prints nothing when no request is queued', function (): void {
    $this->artisan('agent:claim')->assertSuccessful()->doesntExpectOutputToContain('kind');
});

it('stops a pending apply request from blocking a new one after three hours', function (): void {
    AgentWorker::register();
    $job = publicJob();
    $old = agentRequest(AiRequest::KIND_APPLY, $job);
    AiRequest::query()->whereKey($old->id)->update(['created_at' => now()->subHours(3)->subMinute()]);

    expect(app(RequestAgentApply::class)->pending($job))->toBeFalse()->and(app(RequestAgentApply::class)->handle($job))->toBeInstanceOf(AiRequest::class);

    AiRequest::query()->whereKey($old->id)->update(['created_at' => now()->subHours(3)->addMinute()]);
    $other = publicJob('2');
    $recent = agentRequest(AiRequest::KIND_APPLY, $other);

    expect(app(RequestAgentApply::class)->pending($other))->toBeTrue()->and(app(RequestAgentApply::class)->handle($other))->toBeNull();
    $recent->update(['status' => LinkCheckStatus::Done]);
    expect(app(RequestAgentApply::class)->pending($other))->toBeFalse();
});

it('cuts the completion message to 300 characters', function (): void {
    $request = agentRequest(AiRequest::KIND_SCRAPE, null, ['status' => LinkCheckStatus::Running]);

    $this->artisan('agent:complete', ['id' => $request->id, '--message' => str_repeat('é', 400)])->assertSuccessful();

    expect(mb_strlen((string) $request->refresh()->message))->toBe(300)->and($request->status)->toBe(LinkCheckStatus::Done);
});

it('refuses to complete a request that is not running, is not an agent kind or does not exist', function (): void {
    $queued = agentRequest(AiRequest::KIND_SCRAPE);
    $letter = agentRequest(AiRequest::KIND_LETTER, null, ['status' => LinkCheckStatus::Running]);

    $this->artisan('agent:complete', ['id' => $queued->id])->expectsOutputToContain('No running agent request')->assertFailed();
    $this->artisan('agent:complete', ['id' => $letter->id])->assertFailed();
    $this->artisan('agent:complete', ['id' => 9999])->assertFailed();
    expect($queued->refresh()->status)->toBe(LinkCheckStatus::Queued)->and($letter->refresh()->status)->toBe(LinkCheckStatus::Running);
});

it('records the general resume state from agent:register and ignores anything else', function (): void {
    expect(AgentWorker::generalResumeMissing())->toBeFalse();

    $this->artisan('agent:register', ['--general' => 'missing'])->assertSuccessful();
    expect(AgentWorker::generalResumeMissing())->toBeTrue();

    $this->artisan('agent:register', ['--general' => 'bogus'])->assertSuccessful();
    $this->artisan('agent:register', ['--general' => ''])->assertSuccessful();
    expect(AgentWorker::generalResumeMissing())->toBeTrue()->and(AgentWorker::online())->toBeTrue();

    $this->artisan('agent:register', ['--general' => 'ok'])->assertSuccessful();
    expect(AgentWorker::generalResumeMissing())->toBeFalse();
});

it('claims only the requested kind and driver, oldest first, and marks it running', function (): void {
    $api = agentRequest(AiRequest::KIND_SCRAPE, null, ['driver' => AiDriver::Sdk]);
    $letter = agentRequest(AiRequest::KIND_LETTER);
    $first = agentRequest(AiRequest::KIND_SCRAPE);
    $second = agentRequest(AiRequest::KIND_SCRAPE);
    $apply = agentRequest(AiRequest::KIND_APPLY, publicJob());
    $claim = app(ClaimPendingAiRequest::class);

    $claimed = $claim->handle(AiRequest::KIND_SCRAPE);
    expect($claimed?->id)->toBe($first->id)->and($claimed->status)->toBe(LinkCheckStatus::Running)->and($claimed->started_at)->not->toBeNull();
    expect($claim->handle(AiRequest::KIND_SCRAPE)?->id)->toBe($second->id)
        ->and($claim->handle(AiRequest::KIND_SCRAPE))->toBeNull()
        ->and($claim->handle(AiRequest::KIND_APPLY)?->id)->toBe($apply->id)
        ->and($claim->handle(AiRequest::KIND_APPLY))->toBeNull()
        ->and($claim->handle(AiRequest::KIND_LETTER)?->id)->toBe($letter->id)
        ->and($api->refresh()->status)->toBe(LinkCheckStatus::Queued)
        ->and($claim->handle(AiRequest::KIND_SCRAPE, null, AiDriver::Sdk)?->id)->toBe($api->id);
});

it('claims a specific request by id only while it is queued', function (): void {
    $request = agentRequest(AiRequest::KIND_SCRAPE);
    $claim = app(ClaimPendingAiRequest::class);

    expect($claim->handle(AiRequest::KIND_SCRAPE, $request->id + 1))->toBeNull()
        ->and($claim->handle(AiRequest::KIND_SCRAPE, $request->id)?->id)->toBe($request->id)
        ->and($claim->handle(AiRequest::KIND_SCRAPE, $request->id))->toBeNull();
});

it('reads the JSON object out of a model reply and is null for anything else', function (?string $output, ?array $expected): void {
    expect(agentRequest(AiRequest::KIND_SCORE, null, ['output' => $output])->outputFields())->toBe($expected);
})->with([
    'plain object' => ['{"a":1,"b":"x"}', ['a' => 1, 'b' => 'x']],
    'wrapped in prose and fences' => ["Here you go:\n```json\n{\"skill_match\": 4}\n```\nDone.", ['skill_match' => 4]],
    'nested braces' => ['x {"a":{"b":2}} y', ['a' => ['b' => 2]]],
    'empty object' => ['{}', null],
    'not json' => ['{nope}', null],
    'no braces' => ['plain text', null],
    'list' => ['[1,2,3]', null],
    'null output' => [null, null],
]);
