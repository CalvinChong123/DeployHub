<?php

namespace App\Services\Deployment;

use App\Models\Deployment;
use App\Models\Environment;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CandidateService
{
    public function __construct(private ConnectionFactory $connections, private GitInspector $inspector, private Access $access) {}

    public function snapshot(Environment $env): array
    {
        $env->refresh()->load('server', 'project');

        return ['environment_id' => $env->id, 'project_id' => $env->project_id, 'repository' => $env->project->repository,
            'server_id' => $env->server_id, 'host' => $env->server->host, 'port' => $env->server->port,
            'username' => $env->server->username, 'fingerprint' => $env->server->fingerprint,
            'credential_version' => $env->server->credential_version, 'path' => $env->path, 'branch' => $env->branch,
            'production' => $env->production, 'requires_approval' => $env->requires_approval,
            'pipeline_version' => $env->pipeline_version, 'pipeline' => $env->pipeline, 'health_url' => $env->health_url];
    }

    public function digest(array $snapshot, string $base, string $target, int $actor): string
    {
        return hash('sha256', json_encode([$snapshot, $base, $target, $actor], JSON_THROW_ON_ERROR));
    }

    public function inspect(Environment $env, User $actor): array
    {
        abort_unless($this->access->environment($actor, $env), 403);
        $lock = Cache::lock('environment:'.$env->id, config('deployhub.lock_seconds'));
        if (! $lock->get()) {
            throw new DeploymentException('Another repository operation is running. Try again later.');
        }
        try {
            if (Deployment::where('active_environment_id', $env->id)->exists()) {
                throw new DeploymentException('An active deployment is reserving this environment.');
            }
            $result = $this->inspector->inspect($this->connections->connect($env->server), $env);
            $env->update(['last_preflight' => $result, 'checked_at' => now(), 'status' => 'ready']);
            Audit::record($env->project->workspace_id, $actor->id, 'environment.inspected', 'environment:'.$env->id, ['target' => $result['target']]);

            return $result;
        } catch (DeploymentException $e) {
            $env->update(['last_preflight' => ['error' => $e->getMessage()], 'checked_at' => now(), 'status' => 'blocked']);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function create(Environment $env, User $actor, string $key): Deployment
    {
        abort_unless($this->access->environment($actor, $env, 'deploy'), 403);
        if ($existing = Deployment::where('environment_id', $env->id)->where('user_id', $actor->id)->where('idempotency_key', $key)->first()) {
            return $existing;
        }
        $snapshot = $this->snapshot($env);
        $preflight = $this->inspect($env, $actor);
        if ($preflight['count'] === 0) {
            throw new DeploymentException('Already up to date. There are no incoming commits to deploy.');
        }
        if ($snapshot !== $this->snapshot($env)) {
            throw new DeploymentException('Environment configuration changed. Create a fresh preview.');
        }

        return $this->preview($env, $actor, $key, $snapshot, $preflight);
    }

    public function recover(Deployment $source, User $actor, string $key): Deployment
    {
        $env = $source->environment;
        abort_unless($this->access->environment($actor, $env, 'deploy'), 403);
        $this->validateRecoverySource($source);
        if ($existing = Deployment::where('environment_id', $env->id)->where('user_id', $actor->id)->where('idempotency_key', $key)->first()) {
            if (($existing->candidate['recovery_of'] ?? null) !== $source->id) {
                throw new DeploymentException('This preview key belongs to another deployment request.');
            }

            return $existing;
        }
        $snapshot = $this->snapshot($env);
        $preflight = $this->inspect($env, $actor);
        if ($preflight['head'] !== $source->target_sha || $preflight['target'] !== $source->target_sha) {
            throw new DeploymentException('Recovery requires HEAD and fetched origin to match the failed deployment commit. Review a new deployment if origin has advanced.');
        }
        if ($snapshot !== $this->snapshot($env)) {
            throw new DeploymentException('Environment configuration changed. Create a fresh preview.');
        }

        return $this->preview($env, $actor, $key, [...$snapshot, 'recovery_of' => $source->id], $preflight);
    }

    private function validateRecoverySource(Deployment $source): void
    {
        if ($source->state !== 'failed' || ! $source->code_updated) {
            throw new DeploymentException('Only a failed deployment with updated code can prepare pipeline recovery. Unknown deployments require reconciliation.');
        }
    }

    private function preview(Environment $env, User $actor, string $key, array $snapshot, array $preflight): Deployment
    {
        return DB::transaction(function () use ($env, $actor, $key, $snapshot, $preflight) {
            $deployment = Deployment::firstOrCreate(['environment_id' => $env->id, 'user_id' => $actor->id, 'idempotency_key' => $key],
                ['starting_sha' => $preflight['head'], 'target_sha' => $preflight['target'], 'candidate' => $snapshot,
                    'digest' => $this->digest($snapshot, $preflight['head'], $preflight['target'], $actor->id), 'preflight' => $preflight,
                    'expires_at' => now()->addMinutes(config('deployhub.candidate_minutes'))]);
            Audit::record($env->project->workspace_id, $actor->id, 'deployment.previewed', 'deployment:'.$deployment->id, ['target' => $deployment->target_sha]);

            return $deployment;
        });
    }

    public function validate(Deployment $deployment): void
    {
        $env = $deployment->environment;
        if ($deployment->expires_at->isPast()) {
            throw new DeploymentException('Deployment candidate has expired. Create a fresh preview.');
        }
        if (! $this->access->environment($deployment->user->fresh(), $env, 'deploy')) {
            throw new DeploymentException('Requester deployment permission was revoked.');
        }
        if (config('deployhub.require_mfa') && ! $deployment->user->fresh()->two_factor_confirmed_at) {
            throw new DeploymentException('Requester must configure two-factor authentication.');
        }
        $snapshot = $this->snapshot($env);
        if (isset($deployment->candidate['recovery_of'])) {
            $source = Deployment::where('environment_id', $env->id)->find($deployment->candidate['recovery_of']);
            if (! $source || $deployment->starting_sha !== $source->target_sha || $deployment->target_sha !== $source->target_sha) {
                throw new DeploymentException('Recovery source does not match this exact candidate.');
            }
            $this->validateRecoverySource($source);
            $snapshot['recovery_of'] = $source->id;
        }
        if ($deployment->candidate !== $snapshot || ! hash_equals($deployment->digest, $this->digest($snapshot, $deployment->starting_sha, $deployment->target_sha, $deployment->user_id))) {
            throw new DeploymentException('Environment, credentials or pipeline changed. Create a fresh preview.');
        }
    }

    public function validateApproval(Deployment $deployment): void
    {
        if (! $deployment->candidate['requires_approval'] && ! $deployment->candidate['production']) {
            return;
        }
        $approval = $deployment->approval()->first();
        $approver = $approval ? User::find($approval->user_id) : null;
        if (! $approval || $approval->outcome !== 'approved' || $approval->expires_at->isPast() || ! hash_equals($deployment->digest, $approval->digest) || ! $approver || $approver->id === $deployment->user_id || ! $this->access->environment($approver, $deployment->environment, 'approve') || (config('deployhub.require_mfa') && ! $approver->two_factor_confirmed_at)) {
            throw new DeploymentException('Independent approval is missing, expired or no longer authorized.');
        }
    }
}
