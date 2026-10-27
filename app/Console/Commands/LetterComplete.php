<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CompleteLetterRequest;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use Illuminate\Console\Command;

final class LetterComplete extends Command
{
    #[\Override]
    protected $signature = 'letter:complete {id : AI request id} {--fail= : Mark the request failed with this message instead of reading the reply from stdin}';

    #[\Override]
    protected $description = 'Store the model reply (stdin) as the suggestions of a running cover letter request, or mark it failed.';

    public function handle(CompleteLetterRequest $complete): int
    {
        $request = AiRequest::query()->where('kind', AiRequest::KIND_LETTER)->find((int) $this->argument('id'));
        if (! $request instanceof AiRequest || $request->status !== LinkCheckStatus::Running) {
            $this->error('No running cover letter request with that id.');

            return self::FAILURE;
        }
        $failure = $this->option('fail');
        is_string($failure) && $failure !== '' ? $complete->fail($request, $failure) : $complete->handle($request, (string) stream_get_contents(STDIN));

        return self::SUCCESS;
    }
}
