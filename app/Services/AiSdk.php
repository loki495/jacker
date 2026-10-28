<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\JobAssistant;
use Laravel\Ai\Responses\AgentResponse;

final class AiSdk
{
    public function prompt(string $prompt, string $provider, string $model): AgentResponse
    {
        return JobAssistant::make()->prompt($prompt, provider: $provider, model: $model, timeout: 60);
    }
}
