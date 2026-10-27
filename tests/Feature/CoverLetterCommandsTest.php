<?php

declare(strict_types=1);

use App\Actions\ClaimLetterRequest;
use App\Actions\CompleteLetterRequest;
use App\Actions\RequestLetterSuggestions;
use App\Actions\SaveCoverLetter;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Support\CoverLetterSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function queuedLetterRequest(): AiRequest
{
    $job = Opportunity::factory()->create(['company' => 'Acme Co', 'status' => 'saved', 'has_applied' => false, 'archived_at' => null]);
    app(SaveCoverLetter::class)->handle($job, 'My letter');
    app(RequestLetterSuggestions::class)->handle($job->coverLetter()->firstOrFail());

    return AiRequest::query()->firstOrFail();
}

it('letter:claim prints JSON and marks the request running', function (): void {
    $request = queuedLetterRequest();
    CoverLetterSettings::save('cover.agent', 'codex');
    $this->artisan('letter:claim')->assertSuccessful()->expectsOutputToContain('"agent":"codex"');
    expect($request->refresh()->status)->toBe(LinkCheckStatus::Running);
});

it('letter:claim prints nothing when none is queued', function (): void {
    $this->artisan('letter:claim')->assertSuccessful()->doesntExpectOutputToContain('{');
});

it('letter:claim output has the expected keys', function (): void {
    $request = queuedLetterRequest();
    $json = app(ClaimLetterRequest::class)->handle();
    expect($json)->toHaveKeys(['id', 'agent', 'model', 'thinking', 'prompt'])->and($json['id'])->toBe($request->id);
});

it('letter:complete fails without a running request', function (): void {
    $request = queuedLetterRequest();
    $this->artisan('letter:complete', ['id' => $request->id, '--fail' => 'x'])->expectsOutputToContain('No running cover letter request')->assertFailed();
    $this->artisan('letter:complete', ['id' => 9999])->assertFailed();
});

it('letter:complete --fail marks the request failed and keeps the letter', function (): void {
    $request = queuedLetterRequest();
    $request->update(['status' => LinkCheckStatus::Running]);
    $this->artisan('letter:complete', ['id' => $request->id, '--fail' => 'boom'])->assertSuccessful();
    expect($request->refresh())->status->toBe(LinkCheckStatus::Failed)->message->toBe('boom')
        ->and(CoverLetter::query()->firstOrFail())->body->toBe('My letter')->suggestions->toBeNull();
});

it('fails a request when the saved agent is not allowed', function (): void {
    $request = queuedLetterRequest();
    CoverLetterSettings::save('cover.agent', 'evilbot');
    expect(app(ClaimLetterRequest::class)->handle())->toBeNull()
        ->and($request->refresh())->status->toBe(LinkCheckStatus::Failed)->message->toBe(__('letter.request_unusable'));
});

it('fails a request whose letter was deleted after queueing', function (): void {
    $request = queuedLetterRequest();
    CoverLetter::query()->delete();
    expect(app(ClaimLetterRequest::class)->handle())->toBeNull()
        ->and($request->refresh())->status->toBe(LinkCheckStatus::Failed)->message->toBe(__('letter.request_unusable'));
});

it('truncates output longer than the configured maximum', function (): void {
    $request = queuedLetterRequest();
    config(['ai.max_output_chars' => 50]);
    app(CompleteLetterRequest::class)->handle($request, str_repeat('a', 200));
    expect(CoverLetter::query()->firstOrFail()->suggestions)->toBe(str_repeat('a', 50))
        ->and($request->refresh()->status)->toBe(LinkCheckStatus::Done);
});
