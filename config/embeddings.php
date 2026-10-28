<?php

declare(strict_types=1);

return [
    'enabled' => false,
    'provider' => env('EMBEDDINGS_PROVIDER', 'openai'),
    'model' => env('EMBEDDINGS_MODEL', 'text-embedding-3-small'),
    'dimensions' => (int) env('EMBEDDINGS_DIMENSIONS', 1536),
];
