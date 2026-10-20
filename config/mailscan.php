<?php

declare(strict_types=1);

return [
    'host' => env('MAIL_SCAN_HOST', 'imap.mail.me.com'),
    'port' => (int) env('MAIL_SCAN_PORT', 993),
    'username' => env('MAIL_SCAN_USERNAME'),
    'password' => env('MAIL_SCAN_PASSWORD'),
    'folder' => env('MAIL_SCAN_FOLDER', 'INBOX'),
    'days' => (int) env('MAIL_SCAN_DAYS', 30),
    'max_messages' => (int) env('MAIL_SCAN_MAX', 200),

    // host+path regex => query keys that identify the posting; every other query part and the fragment are dropped.
    'job_links' => [
        '#^(www\.)?linkedin\.com/(comm/)?jobs/view/\d+#' => [],
        '#^([a-z]+\.)?indeed\.com/(viewjob|m/viewjob)#' => ['jk'],
        '#^(job-)?boards\.greenhouse\.io/[^/]+/jobs/\d+#' => [],
        '#^jobs\.lever\.co/[^/]+/[0-9a-f-]{36}#' => [],
        '#^jobs\.ashbyhq\.com/[^/]+/[0-9a-f-]{36}#' => [],
        '#^apply\.workable\.com/[^/]+/j/[0-9A-Fa-f]+#' => [],
        '#^[^/]+\.myworkdayjobs\.com/.+/job/#' => [],
        '#^wellfound\.com/jobs/\d+#' => [],
    ],
];
