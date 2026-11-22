<?php

namespace App\Providers;

use App\Policies\ErpPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        foreach (['crm', 'stock', 'sales', 'admin', 'support', 'supportDiagnostics', 'hr', 'crmRead', 'productsRead', 'movementsRead', 'salesRead', 'invoicesRead', 'dashboardRead'] as $ability) {
            Gate::define($ability, [ErpPolicy::class, $ability]);
        }
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(180)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('support-diagnostics', fn (Request $r) => Limit::perMinute(5)
            ->by(($r->user()?->id ?: $r->ip()).'|'.($r->attributes->get('atlas_company_id') ?? 'none')));
    }
}
