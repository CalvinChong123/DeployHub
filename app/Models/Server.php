<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    protected $guarded = [];

    protected $hidden = ['credential'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }

    public function credential()
    {
        return $this->hasOne(ServerCredential::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}
