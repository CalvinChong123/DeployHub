<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerCredential extends Model
{
    protected $guarded = [];

    protected $hidden = ['private_key', 'passphrase'];

    protected function casts(): array
    {
        return ['private_key' => 'encrypted', 'passphrase' => 'encrypted'];
    }
}
