<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Proof of life for a client that cannot send headers: echoes a short token so a call can be matched to the API log. */
final class PingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = $request->query('q');

        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'echo' => is_string($q) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $q) ? $q : null,
        ]);
    }
}
