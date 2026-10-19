<?php

declare(strict_types=1);

return [
    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('ALLOWED_HOSTS', 'localhost,127.0.0.1'))))),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
];
