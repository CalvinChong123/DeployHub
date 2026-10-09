<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $guarded = [];

    public function environments()
    {
        return $this->hasMany(Environment::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}
