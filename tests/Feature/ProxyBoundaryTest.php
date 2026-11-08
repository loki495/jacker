<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('uses forwarded scheme and port only from a configured trusted proxy', function (string $remoteAddress, string $scheme, int $port, string $url): void {
    config()->set('access.allowed_hosts', ['localhost']);
    config()->set('access.trusted_proxies', ['192.0.2.10']);
    TrustProxies::flushState();
    $this->app->getProvider(AppServiceProvider::class)->boot();

    Route::get('/_tests/proxy-boundary', fn (Request $request): array => [
        'scheme' => $request->getScheme(),
        'secure' => $request->isSecure(),
        'port' => $request->getPort(),
        'url' => url('/_tests/proxy-boundary'),
    ]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
        ->get('http://localhost:8096/_tests/proxy-boundary');

    $response->assertOk();
    expect($response->json())->toBe([
        'scheme' => $scheme,
        'secure' => $scheme === 'https',
        'port' => $port,
        'url' => $url,
    ]);
})->with([
    'trusted final proxy' => ['192.0.2.10', 'https', 443, 'https://localhost/_tests/proxy-boundary'],
    'untrusted client' => ['192.0.2.20', 'http', 8096, 'http://localhost:8096/_tests/proxy-boundary'],
]);
