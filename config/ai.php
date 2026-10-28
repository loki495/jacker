<?php

declare(strict_types=1);

return [
    'default' => 'anthropic',
    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => false,
        ],
    ],
    'features' => [
        'extract' => ['driver' => env('AI_EXTRACT_DRIVER', 'bridge'), 'provider' => env('AI_EXTRACT_PROVIDER', 'anthropic'), 'model' => env('AI_EXTRACT_MODEL', 'claude-haiku-4-5')],
        'score' => ['driver' => env('AI_SCORE_DRIVER', 'bridge'), 'provider' => env('AI_SCORE_PROVIDER', 'anthropic'), 'model' => env('AI_SCORE_MODEL', 'claude-haiku-4-5')],
        'letter' => ['driver' => env('AI_LETTER_DRIVER', 'bridge'), 'provider' => env('AI_LETTER_PROVIDER', 'anthropic'), 'model' => env('AI_LETTER_MODEL', 'claude-haiku-4-5')],
    ],
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
