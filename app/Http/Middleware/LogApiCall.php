<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** One line per API call for debugging remote clients. Keys, tokens and listing contents are never written. */
final class LogApiCall
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $response = $next($request);
        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $reply = json_decode((string) $response->getContent(), true);
        $reply = is_array($reply) ? $reply : [];
        $listings = $body['params']['arguments']['listings'] ?? $body['listings'] ?? null;
        $q = $request->query('q');

        Log::channel('api')->info('api', array_filter([
            'method' => $request->method(),
            'path' => '/'.preg_replace('#(/mcp/)[^/]+#', '$1[key]', preg_replace('#(/ping/)[^/]+#', '$1[key]', $request->path())),
            'status' => $response->getStatusCode(),
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'ip' => $request->header('CF-Connecting-IP') ?? $request->ip(),
            'agent' => mb_substr((string) $request->userAgent(), 0, 120),
            'rpc' => is_string($body['method'] ?? null) ? $body['method'] : null,
            'tool' => is_string($body['params']['name'] ?? null) ? $body['params']['name'] : null,
            'listings_sent' => is_array($listings) ? count($listings) : null,
            'summary' => $reply['summary'] ?? $reply['result']['structuredContent']['summary'] ?? null,
            'q' => is_string($q) ? mb_substr($q, 0, 64) : null,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));

        return $response;
    }
}
