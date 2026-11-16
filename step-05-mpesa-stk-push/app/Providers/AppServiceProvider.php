<?php

namespace App\Providers;

use App\Services\Mpesa;
use Illuminate\Cache\RateLimiting\Limit;
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
        RateLimiter::for('payments', fn (Request $request) => Limit::perMinute(10)->by('ip:'.$request->ip()));

        if (! $this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            app(Mpesa::class)->callbackToken();
        }
    }
}
