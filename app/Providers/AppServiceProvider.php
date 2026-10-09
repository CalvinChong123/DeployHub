<?php

namespace App\Providers;

use App\Models\Deployment;
use App\Models\Environment;
use App\Policies\DeploymentPolicy;
use App\Policies\EnvironmentPolicy;
use App\Services\Deployment\ConnectionFactory;
use App\Services\Deployment\SshConnectionFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ConnectionFactory::class, SshConnectionFactory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(12)->letters()->numbers());
        // MVP enqueue and candidate admission must commit atomically in the primary database.
        config(['queue.connections.database.connection' => null]);
        Vite::prefetch(concurrency: 3);
        Gate::policy(Environment::class, EnvironmentPolicy::class);
        Gate::policy(Deployment::class, DeploymentPolicy::class);
        Queue::looping(function () {
            DB::table('worker_heartbeats')->updateOrInsert(['id' => hash('sha256', gethostname().':'.getmypid())], ['seen_at' => now()]);
        });
    }
}
