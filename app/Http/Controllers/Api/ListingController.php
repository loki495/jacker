<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ListKnownListings;
use App\Actions\UpsertListings;
use App\Http\Controllers\Controller;
use App\Support\ListingSchema;
use App\Support\SearchProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ListingController extends Controller
{
    public function index(Request $request, ListKnownListings $known): JsonResponse
    {
        $query = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('api.max_page')],
            'only_missing' => ['nullable', 'boolean'],
        ]);

        return response()->json(['schema_version' => ListingSchema::version()] + $known->handle(
            (int) ($query['page'] ?? 1),
            (int) ($query['per_page'] ?? 100),
            $request->boolean('only_missing'),
        ));
    }

    public function store(Request $request, UpsertListings $upsert): JsonResponse
    {
        $request->validate([
            'listings' => ['required', 'array', 'min:1', 'max:'.(int) config('api.max_batch')],
            'listings.*' => ['array'],
        ]);

        return response()->json(['schema_version' => ListingSchema::version()] + $upsert->handle(array_values($request->input('listings'))));
    }

    public function profile(): JsonResponse
    {
        return response()->json(SearchProfile::payload());
    }

    public function info(): JsonResponse
    {
        return response()->json(ListingSchema::describe());
    }
}
