<?php

declare(strict_types=1);

use App\Actions\RequestAgentApply;
use App\Actions\RequestAgentFill;
use App\Enums\AiDriver;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use App\Models\Opportunity;
use App\Services\UrlGuard;
use App\Support\AgentWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => app()->instance(UrlGuard::class, new UrlGuard(fn (string $host): array => $host === 'internal.test' ? ['192.168.1.5'] : ['93.184.216.34'])));

function fillJob(string $url = 'https://example.com/jobs/1'): Opportunity
{
    return Opportunity::factory()->create(['application_url' => $url]);
}

it('queues one fill request for a public link while the worker is online', function (): void {
    AgentWorker::register();
    $job = fillJob();

    $request = app(RequestAgentFill::class)->handle($job);

    expect($request)->toBeInstanceOf(AiRequest::class)
        ->and($request->kind)->toBe(AiRequest::KIND_FILL)
        ->and($request->refresh()->status)->toBe(LinkCheckStatus::Queued)
        ->and($request->opportunity_id)->toBe($job->id);
});

it('queues nothing when the worker is offline', function (): void {
    expect(app(RequestAgentFill::class)->handle(fillJob()))->toBeNull()
        ->and(AiRequest::query()->count())->toBe(0);
});

it('queues nothing for a duplicate pending request, a private link, a missing link or an unconfirmed AI link', function (): void {
    AgentWorker::register();
    $action = app(RequestAgentFill::class);

    $job = fillJob();
    expect($action->handle($job))->not->toBeNull()
        ->and($action->handle($job))->toBeNull();

    expect($action->handle(fillJob('https://internal.test/job')))->toBeNull()
        ->and($action->handle(fillJob('javascript:alert(1)')))->toBeNull()
        ->and($action->handle(Opportunity::factory()->create(['application_url' => null])))->toBeNull();

    $unconfirmed = Opportunity::factory()->create(['application_url' => 'https://example.com/jobs/2', 'extra_fields' => [RequestAgentApply::UNCONFIRMED => 'ai']]);
    expect($action->handle($unconfirmed))->toBeNull()
        ->and(AiRequest::query()->count())->toBe(1);
});

it('lets a new fill request be queued once the previous one finished', function (): void {
    AgentWorker::register();
    $job = fillJob();
    app(RequestAgentFill::class)->handle($job)?->update(['status' => LinkCheckStatus::Done]);

    expect(app(RequestAgentFill::class)->handle($job))->not->toBeNull();
});

it('claims a fill request after apply and prints its kind', function (): void {
    $fill = AiRequest::query()->create(['kind' => AiRequest::KIND_FILL, 'opportunity_id' => fillJob()->id, 'driver' => AiDriver::Bridge, 'agent' => 'claude']);

    $this->artisan('agent:claim')->expectsOutputToContain('"kind":"fill"')->assertSuccessful();

    expect($fill->refresh()->status)->toBe(LinkCheckStatus::Running);
});

it('completes a running fill request through the real completion command', function (bool $failed, LinkCheckStatus $status): void {
    $this->freezeSecond();
    $fill = AiRequest::query()->create(['kind' => AiRequest::KIND_FILL, 'opportunity_id' => fillJob()->id, 'driver' => AiDriver::Bridge, 'agent' => 'claude', 'status' => LinkCheckStatus::Running]);
    $message = $failed ? 'The filler could not open a window.' : 'The form is open in a visible window.';

    $this->artisan('agent:complete', ['id' => $fill->id, '--message' => $message, '--fail' => $failed])->assertSuccessful();

    expect($fill->refresh()->status)->toBe($status)
        ->and($fill->message)->toBe($message)
        ->and($fill->finished_at?->equalTo(now()))->toBeTrue();
})->with([
    'opened form' => [false, LinkCheckStatus::Done],
    'failed launch' => [true, LinkCheckStatus::Failed],
]);

it('refuses to complete a queued fill request without changing it', function (): void {
    $fill = AiRequest::query()->create(['kind' => AiRequest::KIND_FILL, 'opportunity_id' => fillJob()->id, 'driver' => AiDriver::Bridge, 'agent' => 'claude', 'message' => 'Waiting for the worker.']);
    $original = $fill->refresh()->getAttributes();

    $this->artisan('agent:complete', ['id' => $fill->id, '--message' => 'Must not be saved.', '--fail' => true])
        ->expectsOutputToContain('No running agent request with that id.')
        ->assertFailed();

    expect($fill->refresh()->getAttributes())->toBe($original);
});

it('fails a claimed fill request whose link is no longer public instead of starting a window', function (): void {
    $job = fillJob();
    $fill = AiRequest::query()->create(['kind' => AiRequest::KIND_FILL, 'opportunity_id' => $job->id, 'driver' => AiDriver::Bridge, 'agent' => 'claude']);
    $job->update(['application_url' => 'https://internal.test/job']);

    $this->artisan('agent:claim')->doesntExpectOutputToContain('"kind":"fill"')->assertSuccessful();

    expect($fill->refresh()->status)->toBe(LinkCheckStatus::Failed)->and($fill->message)->toBe(__('jobs.apply_bad_link'));
});

it('shows the open-form button only while the worker is online and queues a request when clicked', function (): void {
    $job = fillJob();
    Livewire::test('pages::detail', ['opportunity' => $job])->assertDontSee(__('jobs.fill_run'));

    AgentWorker::register();
    Livewire::test('pages::detail', ['opportunity' => $job])->assertSee(__('jobs.fill_run'))->call('openForm');

    expect(AiRequest::query()->where('kind', AiRequest::KIND_FILL)->count())->toBe(1);
    Livewire::test('pages::detail', ['opportunity' => $job])->assertSee(__('jobs.fill_working'));
});

// Sources bin/agent-worker (functions only) with a stub "node"; nothing real is launched.
function fillShell(string $dir, string $body, array $env = []): array
{
    $script = base_path('bin/agent-worker');
    $result = Process::env($env)->run(['bash', '-c', "source '$script'; APP_DIR='$dir'; artisan() { echo \"artisan \$*\"; }; $body"]);

    return [$result->exitCode(), $result->output()];
}

it('starts the filler window detached and reports it opened', function (): void {
    $dir = sys_get_temp_dir().'/jacker-fill-test-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($dir.'/tools/form-filler/node_modules');
    $marker = $dir.'/started';
    file_put_contents($dir.'/tools/form-filler/launch.mjs', "touch '$marker'\n");

    [$code, $out] = fillShell($dir, "fill_one 7 https://example.com/j; for i in 1 2 3 4 5 6 7 8 9 10; do [ -f '$marker' ] && break; sleep 0.2; done", ['DISPLAY' => ':0', 'FILLER_NODE' => 'bash', 'FILLER_SCRIPT' => $dir.'/tools/form-filler/launch.mjs']);
    $started = file_exists($marker);
    File::deleteDirectory($dir);

    expect($code)->toBe(0)->and($started)->toBeTrue()->and($out)->toContain('artisan agent:complete 7 --message=The form is open in a visible window');
});

it('fails the fill request closed without a display, a usable link or the installed filler', function (): void {
    $dir = sys_get_temp_dir().'/jacker-fill-test-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($dir.'/tools/form-filler');

    [, $noDisplay] = fillShell($dir, 'unset DISPLAY WAYLAND_DISPLAY; fill_one 1 https://example.com/j');
    [, $badLink] = fillShell($dir, 'fill_one 2 "file:///etc/passwd"', ['DISPLAY' => ':0']);
    [, $noInstall] = fillShell($dir, 'fill_one 3 https://example.com/j', ['DISPLAY' => ':0']);
    File::deleteDirectory($dir);

    expect($noDisplay)->toContain('--fail --message=the worker has no display')
        ->and($badLink)->toContain('--fail --message=the job has no usable web link')
        ->and($noInstall)->toContain('--fail --message=run npm install in tools/form-filler first');
});
