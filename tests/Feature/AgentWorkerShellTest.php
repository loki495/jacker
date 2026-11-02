<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

// Sources bin/agent-worker (functions only) with stubbed render, artisan and run_session; nothing real is launched.
function workerShell(string $app, string $body, array $env = []): array
{
    $script = base_path('bin/agent-worker');
    $result = Process::env($env + ['AGENT_WORKER_TEST' => '1'])->run(['bash', '-c', "source '$script'; APP_DIR='$app'; artisan() { echo \"artisan \$*\"; }; $body"]);

    return [$result->exitCode(), $result->output()];
}

beforeEach(function (): void {
    $this->app_dir = sys_get_temp_dir().'/jacker-worker-test-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->app_dir);
    $this->render = $this->app_dir.'-render.sh';
    file_put_contents($this->render, "#!/usr/bin/env bash\n[ \"\$STUB_FAIL\" = 1 ] && { echo boom >&2; exit 1; }\nprintf '%s\\n' \"posting for \$2\"; yes x | head -c 200000\n");
    chmod($this->render, 0755);
});

afterEach(function (): void {
    File::deleteDirectory($this->app_dir);
    @unlink($this->render);
});

it('fetches the posting text through the render script, capped', function (): void {
    $out = $this->app_dir.'/p.txt';
    [$code] = workerShell($this->app_dir, "fetch_posting https://example.com/j '$out'", ['RENDER_NODE' => $this->render, 'AGENT_POSTING_MAX_BYTES' => '1000']);
    expect($code)->toBe(0)->and(filesize($out))->toBe(1000)->and(file_get_contents($out))->toStartWith('posting for');
});

it('fails closed and leaves no file when the render fails', function (): void {
    $out = $this->app_dir.'/p.txt';
    [$code] = workerShell($this->app_dir, "echo old >'$out'; fetch_posting https://example.com/j '$out'", ['RENDER_NODE' => $this->render, 'STUB_FAIL' => '1']);
    expect($code)->not->toBe(0)->and(file_exists($out))->toBeFalse();
});

it('gives the session the posting file and removes the scratch dir after success and after a failed render', function (): void {
    $scratch = $this->app_dir.'/storage/app/agent/apply/7/scratch';
    [, $out] = workerShell($this->app_dir, 'run_session() { cat >"'.$this->app_dir.'/task"; ls "'.$scratch.'" >"'.$this->app_dir.'/ls"; echo no json; }; apply_one 1 7 https://example.com/j', ['RENDER_NODE' => $this->render]);
    expect($out)->toContain('did not produce a resume')->and(file_get_contents($this->app_dir.'/ls'))->toContain('posting.txt')
        ->and(file_get_contents($this->app_dir.'/task'))->toContain($scratch.'/posting.txt')->toContain('nothing is to be fetched')
        ->and(is_dir($scratch))->toBeFalse();

    [, $out] = workerShell($this->app_dir, 'run_session() { echo SESSION; }; apply_one 1 7 https://example.com/j', ['RENDER_NODE' => $this->render, 'STUB_FAIL' => '1']);
    expect($out)->toContain('could not be rendered')->not->toContain('SESSION')->and(is_dir($scratch))->toBeFalse();
});

it('removes the scratch dir when the worker is terminated mid-session', function (): void {
    $scratch = $this->app_dir.'/storage/app/agent/apply/7/scratch';
    [$code] = workerShell($this->app_dir, 'run_session() { kill -TERM $$; sleep 1; }; apply_one 1 7 https://example.com/j', ['RENDER_NODE' => $this->render]);
    expect($code)->toBe(143)->and(is_dir($scratch))->toBeFalse();
});
