<?php

namespace App\Console\Commands;

use App\Models\Deployment;
use App\Services\Audit;
use Illuminate\Console\Command;

class ReconcileDeployments extends Command
{
    protected $signature = 'deployhub:reconcile';

    protected $description = 'Expire unexecuted candidates and quarantine orphaned running jobs';

    public function handle(): int
    {
        foreach (Deployment::whereIn('state', ['awaiting_confirmation', 'awaiting_approval', 'scheduled', 'queued'])->where('expires_at', '<', now())->get() as $d) {
            $changed = Deployment::whereKey($d->id)->whereIn('state', ['awaiting_confirmation', 'awaiting_approval', 'scheduled', 'queued'])->update(['state' => 'expired', 'active_environment_id' => null, 'finished_at' => now()]);
            if ($changed) {
                Audit::record($d->environment->project->workspace_id, null, 'deployment.expired', 'deployment:'.$d->id);
            }
        }
        foreach (Deployment::where('state', 'running')->where('heartbeat_at', '<', now()->subSeconds(config('deployhub.lock_seconds')))->get() as $d) {
            $changed = Deployment::whereKey($d->id)->where('state', 'running')->update(['state' => 'unknown', 'finished_at' => now(), 'error' => 'Worker heartbeat expired. Verify remote processes and repository state before reconciliation.']);
            if ($changed) {
                $d->steps()->where('state', 'running')->update(['state' => 'unknown', 'finished_at' => now()]);
                Audit::record($d->environment->project->workspace_id, null, 'deployment.unknown', 'deployment:'.$d->id);
            }
        }
        $this->info('Expired candidates released; unknown jobs remain quarantined for administrator reconciliation.');

        return self::SUCCESS;
    }
}
