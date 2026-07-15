<?php

namespace App\Providers;

use App\Support\Context\AppContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One resolved tenant/branch context per request/job, shared by the
        // context middleware, services, and the global query scopes.
        $this->app->singleton(AppContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
