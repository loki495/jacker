<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\McpController;
use App\Http\Controllers\Api\PingController;
use App\Http\Middleware\LogApiCall;
use App\Http\Middleware\RequireApiToken;
use App\Http\Middleware\RequireUrlKey;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware([LogApiCall::class, 'throttle:api', RequireApiToken::class])->group(function (): void {
    Route::get('/info', [ListingController::class, 'info']);
    Route::get('/profile', [ListingController::class, 'profile']);
    Route::get('/listings', [ListingController::class, 'index']);
    Route::post('/listings', [ListingController::class, 'store']);
    Route::post('/mcp', McpController::class);
});

Route::prefix('v1')->middleware([LogApiCall::class, 'throttle:api', RequireUrlKey::class])->group(function (): void {
    Route::post('/mcp/{key}', McpController::class);
    Route::get('/ping/{key}', PingController::class);
});
