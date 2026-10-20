<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CompletePageRender;
use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use Illuminate\Console\Command;

final class RenderComplete extends Command
{
    #[\Override]
    protected $signature = 'render:complete {id : Render request id} {--fail= : Mark the request failed with this message instead of reading the rendered text from stdin}';

    #[\Override]
    protected $description = 'Store the rendered page text (stdin) for a running render request, or mark it failed.';

    public function handle(CompletePageRender $complete): int
    {
        $request = AiRequest::query()->with('linkCheck')->where('kind', AiRequest::KIND_RENDER)->find((int) $this->argument('id'));
        if (! $request instanceof AiRequest || $request->status !== LinkCheckStatus::Running) {
            $this->error('No running render request with that id.');

            return self::FAILURE;
        }
        $failure = $this->option('fail');
        is_string($failure) && $failure !== '' ? $complete->fail($request, $failure) : $complete->handle($request, (string) stream_get_contents(STDIN));

        return self::SUCCESS;
    }
}
