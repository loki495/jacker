<?php

declare(strict_types=1);

return [
    // Empty disables the API (every /api request answers 404). Compared as a SHA-256 digest in constant time.
    'token' => (string) env('API_TOKEN', ''),
    // Secret path segment for MCP clients that cannot send headers (POST /api/v1/mcp/{key}). Empty disables that route.
    'url_token' => (string) env('API_URL_TOKEN', ''),
    'max_batch' => (int) env('API_MAX_BATCH', 50),
    'max_page' => (int) env('API_MAX_PAGE', 500),
    'rate_limit' => (int) env('API_RATE_LIMIT', 60),
];
