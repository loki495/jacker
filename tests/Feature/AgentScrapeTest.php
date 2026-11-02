<?php

declare(strict_types=1);

use App\Actions\PreloadApplicationMarkdown;
use App\Actions\RequestAgentApply;
use App\Actions\RequestAgentScrape;
use App\Actions\SaveCoverLetter;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Models\Resume;
use App\Services\UrlGuard;
use App\Support\AgentFiles;
use App\Support\AgentWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => app()->instance(UrlGuard::class, new UrlGuard(fn (string $host): array => $host === 'internal.test' ? ['192.168.1.5'] : ['93.184.216.34'])));

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

it('queues a custom resume and letter for one job with a web link, once, and only while the worker is online', function (): void {
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
})->with(['', 'javascript:alert(1)', 'https://example.com/a b', 'https://example.com/"x', 'ftp://example.com/x', 'https://example.com/x#frag', 'https://internal.test/job', 'http://192.168.1.5/x']);

it('serves a drafted PDF only for the known file names and only when it exists', function (): void {
    $scratch = sys_get_temp_dir().'/jacker-agent-test-'.bin2hex(random_bytes(4));
    app()->useStoragePath($scratch);
    $job = Opportunity::factory()->create();
    $this->get(route('opportunities.application-file', [$job, 'resume']))->assertNotFound();
    $this->get('/opportunities/'.$job->id.'/application/..%2Fx')->assertNotFound();
    $dir = dirname(AgentFiles::path($job, 'resume'));
    mkdir($dir, 0755, true);
    file_put_contents(AgentFiles::path($job, 'resume'), '%PDF-1.4 fake');
    try {
        $this->get(route('opportunities.application-file', [$job, 'resume']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(AgentFiles::available($job))->toBe(['resume']);
    } finally {
        unlink(AgentFiles::path($job, 'resume'));
        rmdir($dir);
        rmdir(dirname($dir));
        rmdir(dirname($dir, 2));
        rmdir(dirname($dir, 3));
        rmdir($scratch);
    }
});

it('fails a queued apply request whose link turned non-public instead of handing it to the worker', function (): void {
    AgentWorker::register();
    $job = Opportunity::factory()->create(['application_url' => 'https://example.com/jobs/1']);
    $request = app(RequestAgentApply::class)->handle($job);
    $job->update(['application_url' => 'https://internal.test/job']);

    $this->artisan('agent:claim')->doesntExpectOutputToContain('apply')->assertSuccessful();
    expect($request->refresh()->status)->toBe(LinkCheckStatus::Failed)->and($request->message)->toBe(__('jobs.apply_bad_link'));
});

function applyScratch(Opportunity $job, array $files): string
{
    $scratch = sys_get_temp_dir().'/jacker-md-test-'.bin2hex(random_bytes(4));
    app()->useStoragePath($scratch);
    mkdir(dirname(AgentFiles::markdownPath($job, 'resume')), 0755, true);
    foreach ($files as $name => $content) {
        file_put_contents(AgentFiles::markdownPath($job, $name), $content);
    }

    return $scratch;
}

it('preloads the letter and resume editors from the stored Markdown', function (): void {
    $job = Opportunity::factory()->create();
    $scratch = applyScratch($job, ['resume' => "## Experience\n- Built things\n", 'letter' => "Dear team,\n\nHello.\n"]);
    try {
        $this->artisan('agent:preload', ['opportunity' => $job->id])->expectsOutput('letter,resume')->assertSuccessful();
        expect($job->coverLetter()->first()?->body)->toBe("Dear team,\n\nHello.")->and($job->resume()->first()?->body)->toBe("## Experience\n- Built things");
    } finally {
        File::deleteDirectory($scratch);
    }
});

it('never overwrites an existing letter or resume', function (): void {
    $job = Opportunity::factory()->create();
    app(SaveCoverLetter::class)->handle($job, 'My own letter');
    Resume::query()->create(['opportunity_id' => $job->id, 'body' => 'My own resume']);
    $scratch = applyScratch($job, ['resume' => 'new resume', 'letter' => 'new letter']);
    try {
        expect(app(PreloadApplicationMarkdown::class)->handle($job))->toBe([])
            ->and($job->coverLetter()->first()?->body)->toBe('My own letter')
            ->and($job->resume()->first()?->body)->toBe('My own resume');
    } finally {
        File::deleteDirectory($scratch);
    }
});

it('fills only the editor that is empty', function (): void {
    $job = Opportunity::factory()->create();
    app(SaveCoverLetter::class)->handle($job, 'Mine');
    $scratch = applyScratch($job, ['resume' => 'r', 'letter' => 'l']);
    try {
        expect(app(PreloadApplicationMarkdown::class)->handle($job))->toBe(['resume'])->and($job->coverLetter()->first()?->body)->toBe('Mine');
    } finally {
        File::deleteDirectory($scratch);
    }
});

it('ignores missing, blank, binary, oversized and symlinked Markdown', function (): void {
    $job = Opportunity::factory()->create();
    $scratch = applyScratch($job, ['resume' => "  \n", 'letter' => "\xff\xfe bad"]);
    try {
        expect(app(PreloadApplicationMarkdown::class)->handle($job))->toBe([]);

        file_put_contents(AgentFiles::markdownPath($job, 'resume'), str_repeat('a', PreloadApplicationMarkdown::MAX + 1));
        unlink(AgentFiles::markdownPath($job, 'letter'));
        expect(app(PreloadApplicationMarkdown::class)->handle($job))->toBe([]);

        $target = $scratch.'/secret.md';
        file_put_contents($target, 'secret');
        unlink(AgentFiles::markdownPath($job, 'resume'));
        symlink($target, AgentFiles::markdownPath($job, 'resume'));
        symlink($target, AgentFiles::markdownPath($job, 'letter'));
        expect(app(PreloadApplicationMarkdown::class)->handle($job))->toBe([])->and(Resume::query()->count())->toBe(0)->and(CoverLetter::query()->count())->toBe(0);
    } finally {
        File::deleteDirectory($scratch);
    }
});

it('rejects agent:preload for an unknown job', function (): void {
    $this->artisan('agent:preload', ['opportunity' => 999999])->assertFailed();
});

it('removes the resume with its job', function (): void {
    $job = Opportunity::factory()->create();
    Resume::query()->create(['opportunity_id' => $job->id, 'body' => 'x']);
    $job->delete();
    expect(Resume::query()->count())->toBe(0);
});
