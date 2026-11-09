<?php

declare(strict_types=1);

use App\Models\Opportunity;
use App\Support\ApplicationUrl;
use App\Support\CoverLetterSettings;
use App\Support\SearchProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.token' => 'secret-token', 'api.max_batch' => 3, 'api.rate_limit' => 60]);
    RateLimiter::clear('api');
});

function rpc(string $method, array $params = [], int|string|null $id = 1)
{
    return test()->withToken('secret-token')->postJson('/api/v1/mcp', array_filter(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], fn ($v): bool => $v !== null && $v !== []));
}

function tool(string $name, array $arguments = [])
{
    return rpc('tools/call', ['name' => $name, 'arguments' => $arguments]);
}

it('requires the bearer token', function (): void {
    $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
    config(['api.token' => '']);
    $this->withToken('secret-token')->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertNotFound();
});

it('negotiates the protocol version and answers ping', function (): void {
    $init = rpc('initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']])->assertOk();
    expect($init->json('result.protocolVersion'))->toBe('2025-03-26')
        ->and($init->json('result.capabilities.tools'))->toHaveKey('listChanged')
        ->and($init->json('result.serverInfo.name'))->toBe('jacker')
        ->and($init->json('result.instructions'))->toContain('upsert_listings');
    expect(rpc('initialize', ['protocolVersion' => '1999-01-01'])->json('result.protocolVersion'))->toBe('2025-06-18');
    expect(rpc('ping')->json('result'))->toBe([])->and(rpc('ping', id: 'abc')->json('id'))->toBe('abc');
});

it('accepts notifications without a body reply', function (): void {
    $this->withToken('secret-token')->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202)->assertNoContent(202);
});

it('lists the four tools with an input schema built from the field list', function (): void {
    $tools = collect(rpc('tools/list')->json('result.tools'))->keyBy('name');
    expect($tools->keys()->all())->toBe(['get_listing_schema', 'get_search_profile', 'list_known_listings', 'upsert_listings']);
    $item = $tools['upsert_listings']['inputSchema']['properties']['listings']['items'];
    expect($item['required'])->toBe(['company', 'application_url'])
        ->and($item['properties'])->toHaveKeys(['title', 'salary_min', 'pay_period', 'extra_fields'])
        ->and($item['properties']['pay_period']['enum'])->toContain('year')
        ->and($item['properties'])->not->toHaveKey('status')
        ->and($tools['upsert_listings']['inputSchema']['properties']['listings']['maxItems'])->toBe(3);
});

it('returns the schema through the tool', function (): void {
    $result = tool('get_listing_schema')->json('result');
    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['fields'])->not->toBeEmpty()
        ->and(json_decode($result['content'][0]['text'], true)['schema_version'])->toBe($result['structuredContent']['schema_version']);
});

it('upserts through the tool and lists what is still missing', function (): void {
    $created = tool('upsert_listings', ['listings' => [['company' => 'Acme', 'application_url' => 'https://m.test/1', 'title' => 'Dev']]])->json('result');
    expect($created['isError'])->toBeFalse()->and($created['structuredContent']['summary']['created'])->toBe(1);
    $known = tool('list_known_listings', ['only_missing' => true])->json('result.structuredContent');
    expect($known['total'])->toBe(1)->and($known['listings'][0]['missing'])->toContain('location');
    $again = tool('upsert_listings', ['listings' => [['company' => 'Acme', 'application_url' => 'https://m.test/1', 'location' => 'Remote']]])->json('result.structuredContent');
    expect($again['results'][0]['result'])->toBe('updated')->and($again['results'][0]['filled'])->toBe(['location']);
    expect(Opportunity::query()->count())->toBe(1)->and(Opportunity::query()->first()->location)->toBe('Remote');
});

it('reports tool-level failures as isError and protocol failures as JSON-RPC errors', function (): void {
    expect(tool('upsert_listings', ['listings' => 'x'])->json('result.isError'))->toBeTrue();
    expect(tool('upsert_listings', ['listings' => array_fill(0, 4, ['company' => 'A', 'application_url' => 'https://m.test/9'])])->json('result.isError'))->toBeTrue();
    expect(tool('upsert_listings', ['listings' => ['nope']])->json('result.isError'))->toBeTrue();
    $invalid = tool('upsert_listings', ['listings' => [['company' => 'A']]])->json('result');
    expect($invalid['isError'])->toBeFalse()->and($invalid['structuredContent']['results'][0]['errors'])->toHaveKey('application_url');
    expect(tool('nope')->json('error.code'))->toBe(-32602)
        ->and(rpc('resources/list')->json('error.code'))->toBe(-32601);
    $this->withToken('secret-token')->postJson('/api/v1/mcp', ['id' => 1, 'method' => 'ping'])->assertOk()->assertJsonPath('error.code', -32600);
    $this->call('POST', '/api/v1/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer secret-token'], '{not json')->assertOk()->assertJsonPath('error.code', -32700);
    expect(Opportunity::query()->count())->toBe(0);
});

it('handles a batch of messages', function (): void {
    $response = $this->withToken('secret-token')->postJson('/api/v1/mcp', [
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
    ])->assertOk();
    expect($response->json())->toHaveCount(2)->and($response->json('1.id'))->toBe(2);
});

it('does not expose get on the endpoint', function (): void {
    $this->withToken('secret-token')->getJson('/api/v1/mcp')->assertStatus(405);
    expect(ApplicationUrl::normalize('https://m.test/1'))->toBe('https://m.test/1');
});

it('serves the search profile through the tool without private contact details', function (): void {
    SearchProfile::save('I want Laravel roles.');
    CoverLetterSettings::save('profile.name', 'Ada Lovelace');
    CoverLetterSettings::save('profile.email', 'ada@example.com');
    CoverLetterSettings::save('profile.phone', '555-0100');
    $result = tool('get_search_profile')->json('result');
    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['about'])->toBe('I want Laravel roles.')
        ->and($result['structuredContent']['name'])->toBe('Ada Lovelace')
        ->and($result['content'][0]['text'])->not->toContain('ada@example.com', '555-0100');
});

it('serves the MCP endpoint at a secret URL for clients that cannot send headers', function (): void {
    config(['api.url_token' => 'url-key-123']);
    $list = $this->postJson('/api/v1/mcp/url-key-123', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();
    expect(array_column($list->json('result.tools'), 'name'))->toContain('upsert_listings', 'get_search_profile');
    $created = $this->postJson('/api/v1/mcp/url-key-123', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'upsert_listings', 'arguments' => ['listings' => [['company' => 'Acme', 'application_url' => 'https://u.test/1']]]]]);
    expect($created->json('result.structuredContent.summary.created'))->toBe(1)->and(Opportunity::query()->count())->toBe(1);
});

it('hides the secret URL route when the key is wrong, empty or unset', function (): void {
    $call = fn (string $key) => $this->postJson('/api/v1/mcp/'.$key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
    config(['api.url_token' => '']);
    $call('anything')->assertNotFound();
    config(['api.url_token' => 'url-key-123']);
    $call('wrong')->assertNotFound();
    $call('url-key-1234')->assertNotFound();
    expect(Opportunity::query()->count())->toBe(0);
});

it('keeps the URL key separate from the bearer routes', function (): void {
    config(['api.url_token' => 'url-key-123']);
    $this->withToken('url-key-123')->getJson('/api/v1/info')->assertUnauthorized();
    $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
    $this->getJson('/api/v1/mcp/url-key-123')->assertStatus(405);
    $this->postJson('/api/v1/mcp/secret-token', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertNotFound();
});

it('throttles guesses at the secret URL', function (): void {
    config(['api.url_token' => 'url-key-123', 'api.rate_limit' => 2]);
    RateLimiter::clear('api');
    $this->postJson('/api/v1/mcp/a', [])->assertNotFound();
    $this->postJson('/api/v1/mcp/b', [])->assertNotFound();
    $this->postJson('/api/v1/mcp/url-key-123', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertTooManyRequests();
});
