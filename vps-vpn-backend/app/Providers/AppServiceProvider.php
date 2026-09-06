<?php

namespace App\Providers;

use App\Services\RouterOs\RouterOsClient;
use App\Services\Vpn\AlokasiIp;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Keduanya butuh nilai dari config, jadi tidak bisa di-autowire.
        $this->app->bind(RouterOsClient::class, fn () => RouterOsClient::dariConfig());
        $this->app->bind(AlokasiIp::class, fn () => AlokasiIp::dariConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
