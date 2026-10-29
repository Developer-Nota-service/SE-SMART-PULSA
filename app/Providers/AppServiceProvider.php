<?php

namespace App\Providers;

use App\Mail\Transport\KirisanTransport;
use App\Services\IntegrationSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
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
        Mail::extend('kirisan', fn () => new KirisanTransport);

        // Kredensial yang diubah admin menimpa nilai env (lihat
        // IntegrationSettings). Aman bila tabel belum ada saat migrate.
        $this->app->make(IntegrationSettings::class)->applyOverrides();

        // Limiter terpisah untuk simpan kredensial: `throttle:8,1` biasa berbagi
        // kunci dengan throttle grup admin sehingga lalu lintas admin lain ikut
        // menghabiskan kuota. Limiter bernama punya kunci sendiri.
        RateLimiter::for('admin-integrations', fn (Request $request) => Limit::perMinute(8)->by((string) $request->ip()));
    }
}
