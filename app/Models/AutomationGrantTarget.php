<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class AutomationGrantTarget extends Pivot
{
    protected $hidden = ['target_fingerprint'];
}
