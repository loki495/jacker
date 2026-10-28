<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CoverLetter;
use App\Models\Opportunity;
use App\Observers\CoverLetterEmbeddingObserver;
use App\Observers\OpportunityEmbeddingObserver;
use Illuminate\Http\Middleware\TrustProxies;
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
        Opportunity::observe(OpportunityEmbeddingObserver::class);
        CoverLetter::observe(CoverLetterEmbeddingObserver::class);
    }
}
