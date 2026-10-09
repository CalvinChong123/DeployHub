<?php

namespace App\Services;

use App\Models\Deployment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class WorkerHealth
{
    public function status(): array
    {
        $idle = DB::table('worker_heartbeats')->max('seen_at');
        $busy = Deployment::where('state', 'running')->max('heartbeat_at');
        $idleTime = $idle ? Carbon::parse($idle, 'UTC') : null;
        $busyTime = $busy ? Carbon::parse($busy, 'UTC') : null;
        $seen = $idleTime && $busyTime ? ($idleTime->greaterThan($busyTime) ? $idleTime : $busyTime) : ($idleTime ?? $busyTime);

        return ['online' => ($idleTime && $idleTime->greaterThan(now()->subSeconds(90))) || ($busyTime && $busyTime->greaterThan(now()->subSeconds(150))), 'seen_at' => $seen?->toIso8601String()];
    }
}
