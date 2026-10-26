<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CompleteScoreRequest;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use Illuminate\Console\Command;

final class ScoreComplete extends Command
{
    #[\Override]
    protected $signature = 'score:complete {id : AI request id} {--agent= : Agent that produced the reply} {--model= : Model that produced the reply} {--fail= : Mark the request failed with this message instead of reading the reply from stdin}';

    #[\Override]
    protected $description = 'Store the model reply (stdin) for a running scoring request and apply the scores, or mark it failed.';

    public function handle(CompleteScoreRequest $complete): int
    {
        $request = AiRequest::query()->with('opportunity')->where('kind', AiRequest::KIND_SCORE)->find((int) $this->argument('id'));
        if (! $request instanceof AiRequest || $request->status !== LinkCheckStatus::Running) {
            $this->error('No running scoring request with that id.');

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
