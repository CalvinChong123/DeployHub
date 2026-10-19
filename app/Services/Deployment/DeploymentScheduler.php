<?php

namespace App\Services\Deployment;

use App\Jobs\ExecuteDeployment;
use App\Models\Deployment;
use App\Models\Environment;
use App\Services\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DeploymentScheduler
{
    public function __construct(private CandidateService $candidates, private LogSanitizer $sanitizer) {}

    public function status(): array
    {
        $seen = Cache::get('deployhub:scheduler_seen_at');

        return ['online' => $seen && CarbonImmutable::parse($seen)->greaterThan(now()->subSeconds(90)), 'seen_at' => $seen];
    }

    public function dispatchDue(): array
    {
        Cache::put('deployhub:scheduler_seen_at', now()->toIso8601String(), 180);
        $counts = ['queued' => 0, 'failed' => 0, 'expired' => 0, 'waiting' => 0];
        $due = Deployment::whereNotNull('scheduled_for')->where('scheduled_for', '<=', now())
            ->whereIn('state', ['scheduled', 'awaiting_confirmation', 'awaiting_approval'])
            ->orderBy('scheduled_for')->orderBy('id')->limit(100)->get(['id', 'environment_id']);
        foreach ($due as $item) {
            $lock = Cache::lock('environment:'.$item->environment_id, config('deployhub.lock_seconds'));
            if (! $lock->get()) {
                $counts['waiting']++;

                continue;
            }
            try {
                $outcome = DB::transaction(function () use ($item) {
                    Environment::withTrashed()->whereKey($item->environment_id)->lockForUpdate()->firstOrFail();
                    $deployment = Deployment::whereKey($item->id)->lockForUpdate()->firstOrFail();
                    if (! in_array($deployment->state, ['scheduled', 'awaiting_confirmation', 'awaiting_approval'], true)
                        || $deployment->scheduled_for->isFuture()) {
                        return null;
                    }
                    if ($deployment->state !== 'scheduled' || $deployment->expires_at->isPast()) {
                        $reason = $deployment->state === 'scheduled' ? 'Scheduled execution window expired.' : 'Scheduled time passed without requester confirmation.';
                        $deployment->update(['state' => 'expired', 'error' => $reason, 'finished_at' => now(), 'active_environment_id' => null]);
                        Audit::record($deployment->environment->project->workspace_id, null, 'deployment.schedule_expired', 'deployment:'.$deployment->id);

                        return 'expired';
                    }
                    try {
                        $this->candidates->validate($deployment);
                    } catch (DeploymentException $error) {
                        $deployment->update(['state' => 'failed', 'error' => $this->sanitizer->clean($error->getMessage()), 'finished_at' => now(), 'active_environment_id' => null]);
                        Audit::record($deployment->environment->project->workspace_id, null, 'deployment.schedule_failed', 'deployment:'.$deployment->id);

                        return 'failed';
                    }
                    if (Deployment::where('active_environment_id', $deployment->environment_id)->exists()) {
                        return 'waiting';
                    }
                    $deployment->update(['state' => 'queued', 'active_environment_id' => $deployment->environment_id]);
                    ExecuteDeployment::dispatch($deployment->id)->onConnection('database')->beforeCommit();
                    Audit::record($deployment->environment->project->workspace_id, $deployment->user_id, 'deployment.schedule_queued', 'deployment:'.$deployment->id, ['scheduled_for' => $deployment->scheduled_for->toIso8601String()]);

                    return 'queued';
                });
                if ($outcome) {
                    $counts[$outcome]++;
                }
            } finally {
                $lock->release();
            }
        }

        return $counts;
    }
}
