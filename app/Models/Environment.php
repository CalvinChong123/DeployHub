<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Environment extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['registered_path'];

    protected static function booted(): void
    {
        static::saving(function (Environment $env) {
            $env->registered_path = $env->trashed() ? null : $env->path;
        });
        static::deleting(function (Environment $env) {
            if (! $env->isForceDeleting()) {
                $env->newQuery()->whereKey($env->id)->update(['registered_path' => null]);
            }
        });
    }

    protected function casts(): array
    {
        return ['pipeline' => 'array', 'hooks' => 'array', 'last_preflight' => 'array', 'production' => 'boolean', 'requires_approval' => 'boolean', 'checked_at' => 'datetime'];
    }

    public function enabledHooks(?string $phase = null): array
    {
        return array_values(array_filter($this->hooks ?? [], fn ($hook) => $hook['enabled'] && ($phase === null || $hook['phase'] === $phase)));
    }

    public function project()
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function deployments()
    {
        return $this->hasMany(Deployment::class);
    }

    public function latestDeployment()
    {
        return $this->hasOne(Deployment::class)->ofMany(['id' => 'max'], fn ($query) => $query
            ->whereIn('state', ['awaiting_approval', 'scheduled', 'queued', 'running', 'succeeded', 'failed', 'unknown', 'reconciled'])->withoutExpiredCandidates())
            ->select(array_map(fn ($column) => 'deployments.'.$column, ['id', 'environment_id', 'user_id', 'state', 'target_sha', 'scheduled_for', 'created_at', 'started_at', 'finished_at', 'code_updated']));
    }

    public function lastSuccessfulDeployment()
    {
        return $this->hasOne(Deployment::class)->ofMany(['id' => 'max'], fn ($query) => $query->where('state', 'succeeded'))
            ->select(array_map(fn ($column) => 'deployments.'.$column, ['id', 'environment_id', 'user_id', 'state', 'target_sha', 'created_at', 'finished_at']));
    }

    public function permissions()
    {
        return $this->hasMany(EnvironmentPermission::class);
    }
}
