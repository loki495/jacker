<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\ListKnownListings;
use App\Actions\UpsertListings;
use App\Support\ListingSchema;
use App\Support\SearchProfile;

/** A minimal stateless MCP server (Streamable HTTP, JSON responses only) over the listing actions. */
final readonly class ListingMcp
{
    private const array VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(private ListKnownListings $known, private UpsertListings $upsert) {}

    /**
     * @param  array<mixed>  $message
     * @return array<string, mixed>|null Null for a notification, which gets no reply.
     */
    public function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($method)) {
            return $this->error($id, -32600, 'Invalid request');
        }
        if (! array_key_exists('id', $message)) {
            return null;
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($method) {
            'initialize' => $this->result($id, $this->initialize($params)),
            'ping' => $this->result($id, new \stdClass),
            'tools/list' => $this->result($id, ['tools' => $this->tools()]),
            'tools/call' => $this->call($id, $params),
            default => $this->error($id, -32601, 'Method not found'),
        };
    }

    /**
     * @param  array<mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $asked = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => in_array($asked, self::VERSIONS, true) ? $asked : self::VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'jacker', 'version' => ListingSchema::version()],
            'instructions' => __('api.mcp_instructions'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function tools(): array
    {
        $properties = [];
        foreach (ListingSchema::fields() as $field) {
            $properties[$field['name']] = match ($field['type']) {
                'number' => ['type' => 'number', 'description' => $field['description']],
                'object' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => $field['description']],
                'enum' => ['type' => 'string', 'enum' => $field['values'], 'description' => $field['description']],
                default => ['type' => 'string', 'description' => $field['description']],
            };
        }

        return [
            [
                'name' => 'get_listing_schema',
                'description' => __('api.purpose_info').' Call it first and again when schema_version changes.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'get_search_profile',
                'description' => __('api.purpose_profile'),
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'list_known_listings',
                'description' => __('api.purpose_known'),
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'page' => ['type' => 'integer', 'minimum' => 1],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => (int) config('api.max_page')],
                    'only_missing' => ['type' => 'boolean', 'description' => 'Only listings that still have fields to fill.'],
                ]],
            ],
            [
                'name' => 'upsert_listings',
                'description' => __('api.purpose_upsert'),
                'inputSchema' => ['type' => 'object', 'required' => ['listings'], 'properties' => ['listings' => [
                    'type' => 'array', 'minItems' => 1, 'maxItems' => (int) config('api.max_batch'),
                    'items' => ['type' => 'object', 'required' => ListingSchema::IDENTITY, 'properties' => $properties],
                ]]],
            ],
        ];
    }

    /** @param array<mixed> $params
     * @return array<string, mixed>
     */
    private function call(mixed $id, array $params): array
    {
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $payload = match ($params['name'] ?? null) {
            'get_listing_schema' => ListingSchema::describe(),
            'get_search_profile' => SearchProfile::payload(),
            'list_known_listings' => ['schema_version' => ListingSchema::version()] + $this->known->handle(
                max(1, (int) ($arguments['page'] ?? 1)),
                (int) ($arguments['per_page'] ?? 100),
                filter_var($arguments['only_missing'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ),
            'upsert_listings' => $this->upsertTool($arguments),
            default => null,
        };
        if ($payload === null) {
            return $this->error($id, -32602, 'Unknown tool');
        }
        $failed = isset($payload['error']);

        return $this->result($id, [
            'content' => [['type' => 'text', 'text' => (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            'structuredContent' => $payload,
            'isError' => $failed,
        ]);
    }

    /**
     * @param  array<mixed>  $arguments
     * @return array<string, mixed>
     */
    private function upsertTool(array $arguments): array
    {
        $items = $arguments['listings'] ?? null;
        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > (int) config('api.max_batch')
            || array_filter($items, fn (mixed $item): bool => ! is_array($item)) !== []) {
            return ['error' => 'listings must be a list of 1 to '.(int) config('api.max_batch').' objects'];
        }

        return ['schema_version' => ListingSchema::version()] + $this->upsert->handle($items);
    }

    /** @return array<string, mixed> */
    private function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
