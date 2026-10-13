<?php

namespace App\Services\Deployment;

use App\Jobs\ExecuteDeployment;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\User;
use App\Services\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeploymentConfirmation
{
    public function __construct(private CandidateService $candidates) {}

    public function confirm(Deployment $deployment, User $actor, string $digest, ?CarbonImmutable $scheduledFor = null, ?string $timezone = null): Deployment
    {
        return DB::transaction(function () use ($deployment, $actor, $digest, $scheduledFor, $timezone) {
            Environment::whereKey($deployment->environment_id)->lockForUpdate()->firstOrFail();
            $deployment = Deployment::whereKey($deployment->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('confirm', $deployment);
            if (! hash_equals($deployment->digest, $digest)) {
                throw new DeploymentException('Candidate digest does not match.');
            }
            if ($scheduledFor) {
                $deployment = $this->candidates->scheduleReviewed($deployment, $scheduledFor, $timezone ?? 'UTC');
                if ($deployment->state !== 'awaiting_confirmation') {
                    return $deployment;
                }
            }
            if (in_array($deployment->state, ['queued', 'running', 'awaiting_approval', 'scheduled'], true)) {
                return $deployment;
            }
            if ($deployment->state !== 'awaiting_confirmation') {
                throw new DeploymentException('This candidate cannot be confirmed again. Create a fresh preview.');
            }
            $this->candidates->validate($deployment);
            if (! $deployment->scheduled_for && Deployment::where('active_environment_id', $deployment->environment_id)->exists()) {
                throw new DeploymentException('Another active deployment reserves this environment.');
            }
            $requires = $deployment->candidate['requires_approval'] || $deployment->candidate['production'];
            $deployment->update(['state' => $requires ? 'awaiting_approval' : ($deployment->scheduled_for ? 'scheduled' : 'queued'),
                'active_environment_id' => $deployment->scheduled_for ? null : $deployment->environment_id]);
            Audit::record($deployment->environment->project->workspace_id, $actor->id, 'deployment.confirmed', 'deployment:'.$deployment->id,
                ['digest' => $deployment->digest, 'automation_grant_id' => $deployment->candidate['automation_grant_id'] ?? null]);
            if (! $requires && ! $deployment->scheduled_for) {
                ExecuteDeployment::dispatch($deployment->id)->onConnection('database')->beforeCommit();
            }

            return $deployment;
        });
    }
}
