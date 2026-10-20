<?php

declare(strict_types=1);

return [
    'agent' => env('AI_AGENT', 'claude'),
    'model' => env('AI_MODEL', 'haiku'),
    'max_output_chars' => 20_000,
];
