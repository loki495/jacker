<?php

declare(strict_types=1);

return [
    'timeout' => (int) env('LINKCHECK_TIMEOUT', 15),
    'spacing' => (int) env('LINKCHECK_SPACING', 4),
    'max_bytes' => (int) env('LINKCHECK_MAX_BYTES', 3_000_000),
    'max_redirects' => 5,
    'user_agent' => env('LINKCHECK_USER_AGENT', 'Mozilla/5.0 (compatible; JobsTracker personal link checker)'),
];
