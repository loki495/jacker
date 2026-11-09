<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Guards the MCP route for clients that cannot send headers: the key is a path segment, and any mismatch looks like a missing page. */
final class RequireUrlKey
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('api.url_token');
        $given = (string) $request->route('key');
        abort_unless($token !== '' && hash_equals(hash('sha256', $token), hash('sha256', $given)), 404);

        return $next($request);
    }
}
