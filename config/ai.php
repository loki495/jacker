<?php

declare(strict_types=1);

return [
    'agent' => env('AI_AGENT', 'claude'),
    'model' => env('AI_MODEL', 'haiku'),
    'max_output_chars' => 20_000,
    /** Tried in order by bin/ai-bridge until one returns a reply (an agent with no quota or an error falls through). */
    'chain' => [
        ['agent' => 'claude', 'model' => env('AI_MODEL', 'haiku')],
        ['agent' => 'codex', 'model' => env('AI_CODEX_MODEL', 'gpt-6-luna')],
        ['agent' => 'opencode', 'model' => env('AI_OPENCODE_MODEL', 'opencode/deepseek-v4.1-flash-free')],
    ],
];
