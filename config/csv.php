<?php

declare(strict_types=1);

return [
    'max_bytes' => (int) env('CSV_MAX_BYTES', 5 * 1024 * 1024),
    'max_rows' => (int) env('CSV_MAX_ROWS', 1000),
];
