<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit entries are append-only.'));
        static::deleting(fn () => throw new \LogicException('Audit entries are append-only.'));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
