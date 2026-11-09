<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireApiToken
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('api.token');
        abort_if($token === '', 404);
        $given = (string) $request->bearerToken();
        abort_unless($given !== '' && hash_equals(hash('sha256', $token), hash('sha256', $given)), 401, 'Unauthorized', ['WWW-Authenticate' => 'Bearer']);

        return $next($request);
    }
}
