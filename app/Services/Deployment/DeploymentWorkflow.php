<?php

namespace App\Services\Deployment;

use Illuminate\Support\Str;

class DeploymentWorkflow
{
    public const OPTIONS = [
        'hard_reset' => ['name' => 'Hard reset tracked changes', 'phase' => 'pre', 'script' => 'git reset --hard HEAD'],
        'composer' => ['name' => 'Install Composer dependencies', 'phase' => 'post', 'script' => 'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --no-plugins'],
        'cache_clear' => ['name' => 'Clear Laravel caches', 'phase' => 'post', 'script' => 'php artisan optimize:clear --no-interaction'],
        'optimize' => ['name' => 'Optimize Laravel', 'phase' => 'post', 'script' => 'php artisan optimize --no-interaction'],
        'queue_restart' => ['name' => 'Restart Laravel queues', 'phase' => 'post', 'script' => 'php artisan queue:restart --no-interaction'],
        'health' => ['name' => 'HTTPS health check', 'phase' => 'post', 'script' => 'Check the configured HTTPS URL for a 2xx response'],
    ];

    public static function defaults(array $pipeline = [], array $hooks = []): array
    {
        $existing = array_column($hooks, 'type');
        $defaults = [];
        foreach (self::OPTIONS as $type => $option) {
            if (! in_array($type, $existing, true)) {
                $defaults[] = ['id' => (string) Str::uuid(), 'type' => $type, ...$option, 'enabled' => in_array($type, $pipeline, true)];
            }
        }
        $pre = array_filter($defaults, fn ($hook) => $hook['phase'] === 'pre');
        $post = array_filter($defaults, fn ($hook) => $hook['phase'] === 'post' && $hook['type'] !== 'health');
        $health = array_filter($defaults, fn ($hook) => $hook['type'] === 'health');

        return [...array_values($pre), ...array_values(array_filter($hooks, fn ($hook) => $hook['phase'] === 'pre')),
            ...array_values($post), ...array_values(array_filter($hooks, fn ($hook) => $hook['phase'] === 'post')), ...array_values($health)];
    }
}
