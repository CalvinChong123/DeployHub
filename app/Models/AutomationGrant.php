<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationGrant extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash', 'identity_fingerprint'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function environments()
    {
        return $this->belongsToMany(Environment::class, 'automation_grant_targets')->withTrashed()
            ->using(AutomationGrantTarget::class)->withPivot('target_fingerprint')->orderBy('environments.id');
    }
}
