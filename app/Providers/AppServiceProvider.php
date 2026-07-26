<?php

namespace App\Providers;

use App\Domains\Administration\Models\Desk;
use App\Domains\Administration\Policies\DeskPolicy;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(Desk::class, DeskPolicy::class);
    }
}
