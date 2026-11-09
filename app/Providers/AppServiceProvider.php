<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Observers\CoverLetterEmbeddingObserver;
use App\Observers\OpportunityEmbeddingObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        TrustProxies::at(config('access.trusted_proxies'));
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute((int) config('api.rate_limit'))->by((string) $request->ip()));
        Opportunity::observe(OpportunityEmbeddingObserver::class);
        CoverLetter::observe(CoverLetterEmbeddingObserver::class);
    }
}
