<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CompleteAiRequest;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use Illuminate\Console\Command;

final class AiComplete extends Command
{
    #[\Override]
    protected $signature = 'ai:complete {id : AI request id} {--agent= : Agent that produced the reply} {--model= : Model that produced the reply} {--fail= : Mark the request failed with this message instead of reading the model output from stdin}';

    #[\Override]
    protected $description = 'Store the model output (stdin) for a running AI request, or mark it failed.';

    public function handle(CompleteAiRequest $complete): int
    {
        $request = AiRequest::query()->with('linkCheck.opportunity')->find((int) $this->argument('id'));
        if (! $request instanceof AiRequest || $request->status !== LinkCheckStatus::Running) {
            $this->error('No running AI request with that id.');

            return self::FAILURE;
        }
        foreach (['agent', 'model'] as $option) {
            if (is_string($this->option($option)) && $this->option($option) !== '') {
                $request->{$option} = $this->option($option);
            }
        }
        $failure = $this->option('fail');
        is_string($failure) && $failure !== '' ? $complete->fail($request, $failure) : $complete->handle($request, (string) stream_get_contents(STDIN));

        return self::SUCCESS;
    }
}
