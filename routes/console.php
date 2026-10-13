<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('deployhub:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('deployhub:dispatch-scheduled')->everyMinute()->withoutOverlapping(2);
Schedule::command('deployhub:prune-logs')->daily()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
