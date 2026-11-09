<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ListingMcp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class McpController extends Controller
{
    public function __invoke(Request $request, ListingMcp $mcp): JsonResponse|Response
    {
        $body = json_decode($request->getContent(), true);
        if (! is_array($body) || $body === []) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
        }
        if (array_is_list($body)) {
            $replies = array_values(array_filter(array_map(fn (mixed $m): ?array => is_array($m) ? $mcp->handle($m) : $mcp->handle([]), $body)));

            return $replies === [] ? response('', 202) : response()->json($replies);
        }
        $reply = $mcp->handle($body);

        return $reply === null ? response('', 202) : response()->json($reply);
    }
}
