<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LinkCheckStatus;
use App\Models\AiRequest;
use Illuminate\Console\Command;

final class AgentComplete extends Command
{
    #[\Override]
    protected $signature = 'agent:complete {id : AI request id} {--message= : Result summary} {--fail : Mark the request failed}';

    #[\Override]
    protected $description = 'Finish a running agent request with a short result message.';

    public function handle(): int
    {
        $request = AiRequest::query()->whereIn('kind', [AiRequest::KIND_SCRAPE, AiRequest::KIND_APPLY])->find((int) $this->argument('id'));
        if (! $request instanceof AiRequest || $request->status !== LinkCheckStatus::Running) {
            $this->error('No running agent request with that id.');

            return self::FAILURE;
        }
        $request->update([
            'status' => $this->option('fail') ? LinkCheckStatus::Failed : LinkCheckStatus::Done,
            'message' => mb_substr((string) $this->option('message'), 0, 300),
            'finished_at' => now(),
        ]);

        return self::SUCCESS;
    }
}
