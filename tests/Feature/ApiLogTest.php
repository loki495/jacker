<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.token' => 'secret-token', 'api.url_token' => 'url-key-123', 'api.rate_limit' => 60, 'logging.channels.api' => ['driver' => 'single', 'path' => storage_path('logs/api-test.log'), 'level' => 'info']]);
    File::delete(storage_path('logs/api-test.log'));
    RateLimiter::clear('api');
});

function apiLog(): string
{
    return File::exists(storage_path('logs/api-test.log')) ? File::get(storage_path('logs/api-test.log')) : '';
}

it('answers the ping with the echoed token and logs it without the key', function (): void {
    $response = $this->getJson('/api/v1/ping/url-key-123?q=check-42')->assertOk();
    expect($response->json('ok'))->toBeTrue()->and($response->json('echo'))->toBe('check-42')->and($response->json('server_time'))->not->toBeNull();
    expect(apiLog())->toContain('"path":"/api/v1/ping/[key]"', '"q":"check-42"', '"status":200')->not->toContain('url-key-123');
});

it('drops an unsafe echo value and hides the ping from a wrong key', function (): void {
    expect($this->getJson('/api/v1/ping/url-key-123?q='.urlencode('<script>'))->json('echo'))->toBeNull();
    $this->getJson('/api/v1/ping/nope')->assertNotFound();
    expect(apiLog())->toContain('"status":404');
});

it('logs MCP calls with the tool and listing counts but never contents, tokens or keys', function (): void {
    $this->postJson('/api/v1/mcp/url-key-123', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'upsert_listings', 'arguments' => ['listings' => [['company' => 'Secret Co', 'application_url' => 'https://log.test/1', 'notes' => 'private text']]]]])->assertOk();
    $this->withToken('secret-token')->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'])->assertOk();
    $log = apiLog();
    expect($log)->toContain('"tool":"upsert_listings"', '"listings_sent":1', '"created":1', '"rpc":"tools/call"', '"rpc":"ping"', '"path":"/api/v1/mcp/[key]"')
        ->not->toContain('Secret Co', 'private text', 'url-key-123', 'secret-token', 'log.test');
});

it('logs rejected calls so a client that never arrives is visible', function (): void {
    $this->getJson('/api/v1/info')->assertUnauthorized();
    $this->withToken('wrong')->getJson('/api/v1/info')->assertUnauthorized();
    expect(substr_count(apiLog(), '"status":401'))->toBe(2)->and(apiLog())->not->toContain('wrong');
});
