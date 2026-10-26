<?php

declare(strict_types=1);

return [
    /** Tried in order by bin/ai-bridge until one returns a reply (an agent with no quota or an error falls through). */
    'chain' => [
        ['agent' => 'claude', 'model' => env('SCORE_MODEL', 'haiku')],
        ['agent' => 'codex', 'model' => env('SCORE_CODEX_MODEL', 'gpt-6-luna')],
        ['agent' => 'opencode', 'model' => env('SCORE_OPENCODE_MODEL', 'opencode/deepseek-v4.1-flash-free')],
    ],

    /** Desirability = 60% pay + 40% work mode; Eastern-only hours take one point off. Pay bands are annual USD midpoints. */
    'pay_bands' => [160_000 => 5, 140_000 => 4, 120_000 => 3, 100_000 => 2, 0 => 1],
    'pay_unknown' => 3,
    'work_mode' => ['remote' => 4, 'hybrid' => 3, 'on_site' => 2, 'unspecified' => 2.5],
    'pay_weight' => 0.6,
    'eastern_penalty' => 1,
    'annual_multiplier' => ['hour' => 2080, 'day' => 260, 'week' => 52, 'month' => 12, 'year' => 1],

    /** Judged by the model: skill match only (desirability's pay and work mode are computed in PHP). */
    'rubric' => <<<'TXT'
Skill match (integer 1-5) for a candidate whose strengths are PHP/Laravel/Livewire, DevOps/Docker/sysadmin, and payments/integrations/APIs:
- PHP or Laravel in the job title counts most; in the description only it counts less.
- DevOps, Docker, sysadmin and payments/API/integration work add a little.
- Senior and mid level are a full fit. Junior roles score down. Lead, Principal and Manager roles lose one point. Analyst and admin roles lose half a point.
- 5 = direct core-stack match at a fitting level; 3 = partial overlap; 1 = no meaningful overlap.
Also report eastern_only_hours: true only when the listing requires Eastern (US East Coast) working hours, otherwise false.
TXT,
];
