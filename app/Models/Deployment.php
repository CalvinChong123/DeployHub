<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deployment extends Model
{
    protected $guarded = [];

    public function toArray(): array
    {
        $data = parent::toArray();
        // Keep connection metadata in the vault/admin screens, not candidate/log responses.
        if (isset($data['candidate'])) {
            unset($data['candidate']['host'], $data['candidate']['port'], $data['candidate']['username']);
        }

        return $data;
    }

    protected static function booted(): void
    {
        static::updating(function (Deployment $deployment) {
            if ($deployment->isDirty(['environment_id', 'user_id', 'idempotency_key', 'starting_sha', 'target_sha', 'digest', 'candidate', 'preflight', 'expires_at'])) {
                throw new \LogicException('Deployment candidates are immutable. Create a fresh preview.');
            }
        });
    }

    protected function casts(): array
    {
        return ['candidate' => 'array', 'preflight' => 'array', 'expires_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'heartbeat_at' => 'datetime', 'cancel_requested' => 'boolean', 'code_updated' => 'boolean'];
    }

    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function steps()
    {
        return $this->hasMany(DeploymentStep::class);
    }

    public function approval()
    {
        return $this->hasOne(DeploymentApproval::class);
    }
}
