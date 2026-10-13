<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationGrant extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash', 'identity_fingerprint', 'target_fingerprint'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function environment()
    {
        return $this->belongsTo(Environment::class)->withTrashed();
    }
}
