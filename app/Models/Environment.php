<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Environment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['pipeline' => 'array', 'last_preflight' => 'array', 'production' => 'boolean', 'requires_approval' => 'boolean', 'checked_at' => 'datetime'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function deployments()
    {
        return $this->hasMany(Deployment::class);
    }

    public function permissions()
    {
        return $this->hasMany(EnvironmentPermission::class);
    }
}
