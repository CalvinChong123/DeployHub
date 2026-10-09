<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnvironmentPermission extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['can_view' => 'boolean', 'can_deploy' => 'boolean', 'can_approve' => 'boolean'];
    }
}
