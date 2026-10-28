<?php

declare(strict_types=1);

namespace App\Services;

use Laravel\Ai\Embeddings;
use RuntimeException;

final class TextEmbeddings
{
    /** @return list<float|int> */
    public function generate(string $text): array
    {
        $provider = (string) config('embeddings.provider');
        $dimensions = (int) config('embeddings.dimensions');
        if ($provider !== 'openai' || $dimensions < 1) {
            throw new RuntimeException('Invalid embedding configuration.');
        }
        $response = Embeddings::for([$text])->dimensions($dimensions)->generate($provider, (string) config('embeddings.model'));
        $vector = $response->embeddings[0] ?? [];
        if (count($response->embeddings) !== 1 || ! self::valid($vector, $dimensions)) {
            throw new RuntimeException('Invalid embedding vector returned by provider.');
        }

        return $vector;
    }

    /** @param array<mixed> $vector */
    public static function valid(array $vector, int $dimensions): bool
    {
        if ($dimensions < 1 || ! array_is_list($vector) || count($vector) !== $dimensions) {
            return false;
        }
        $nonzero = false;
        foreach ($vector as $value) {
            if ((! is_float($value) && ! is_int($value)) || ! is_finite((float) $value)) {
                return false;
            }
            $nonzero = $nonzero || $value != 0;
        }

        return $nonzero;
    }
}
