<?php

declare(strict_types=1);

return [
    'timeout' => (int) env('LINKCHECK_TIMEOUT', 15),
    'spacing' => (int) env('LINKCHECK_SPACING', 4),
    'max_bytes' => (int) env('LINKCHECK_MAX_BYTES', 3_000_000),
    'max_text_chars' => (int) env('LINKCHECK_MAX_TEXT_CHARS', 20_000),
    // Error text near the top of a page that still answered 200 (matched case-insensitively against the first gone_window characters).
    'gone_window' => 1500,
    'gone_patterns' => [
        '/\b(job|position|posting|role|vacancy|listing)( opening)? (was )?not found\b/i',
        '/\b(job|position|posting|role|vacancy|listing)\b[^.\n]{0,40}\b(is|has been|was|are) (no longer (available|open|active|accepting)|closed|filled|expired|removed)\b/i',
        '/\bno longer accepting applications\b/i',
        '/\b(this|the) (job|position|posting) (has )?(expired|been (closed|filled|removed))\b/i',
        '/\bpage (you are|you\'re) looking for (could not|cannot|can\'t|does not|doesn\'t)\b/i',
        '/^\s*(error )?404\b/i',
    ],
    'max_redirects' => 5,
    'user_agent' => env('LINKCHECK_USER_AGENT', 'Mozilla/5.0 (compatible; JobsTracker personal link checker)'),
];
