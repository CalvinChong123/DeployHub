<?php

namespace App\Services\Deployment;

use App\Models\AutomationGrant;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\AutomationAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CandidateService
{
    public function __construct(private ConnectionFactory $connections, private GitInspector $inspector, private Access $access) {}

    public function snapshot(Environment $env): array
    {
        $env->refresh()->load('server', 'project');

        // Retain the retired approval field to validate existing immutable candidate digests.
        return ['environment_id' => $env->id, 'project_id' => $env->project_id, 'repository' => $env->project->repository,
            'server_id' => $env->server_id, 'host' => $env->server->host, 'port' => $env->server->port,
            'username' => $env->server->username, 'fingerprint' => $env->server->fingerprint,
            'credential_version' => $env->server->credential_version, 'path' => $env->path, 'branch' => $env->branch,
            'production' => $env->production, 'requires_approval' => $env->requires_approval,
            'pipeline_version' => $env->pipeline_version, 'pipeline' => $env->pipeline, 'hooks' => $env->enabledHooks(), 'health_url' => $env->health_url];
    }

    public function digest(array $snapshot, string $base, string $target, int $actor): string
    {
        return hash('sha256', json_encode([$this->canonical($snapshot), $base, $target, $actor], JSON_THROW_ON_ERROR));
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }

    public function inspect(Environment $env, User $actor, bool $reuseRecent = false): array
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
            $snapshot = $this->snapshot($env);
            $configurationDigest = hash('sha256', json_encode($this->canonical($snapshot), JSON_THROW_ON_ERROR));
            $cached = $env->last_preflight;
            if ($reuseRecent && $env->checked_at && ! $env->checked_at->isFuture()
                && $env->checked_at->greaterThan(now()->subSeconds(config('deployhub.preflight_reuse_seconds')))
                && ($cached['inspection_version'] ?? null) === GitInspector::VERSION
                && ($cached['configuration_digest'] ?? null) === $configurationDigest && ! isset($cached['error'])) {
                return $cached;
            }
            $started = microtime(true);
            $result = $this->inspector->inspect($this->connections->connect($env->server), $env, count($env->enabledHooks('pre')) > 0);
            if ($this->canonical($snapshot) !== $this->canonical($this->snapshot($env))) {
                throw new DeploymentException('Environment configuration changed during inspection. Refresh preflight.');
            }
            $result['configuration_digest'] = $configurationDigest;
            $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
            $env->update(['last_preflight' => $result, 'checked_at' => now(), 'status' => $result['clean'] ? 'ready' : 'local_changes']);
            Audit::record($env->project->workspace_id, $actor->id, 'environment.inspected', 'environment:'.$env->id, ['target' => $result['target']]);

            return $result;
        } catch (DeploymentException $e) {
            $env->update(['last_preflight' => ['error' => $e->getMessage()], 'checked_at' => now(), 'status' => 'blocked']);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function create(Environment $env, User $actor, string $key, ?CarbonImmutable $scheduledFor = null, ?string $timezone = null, ?AutomationGrant $automationGrant = null, ?string $expectedTargetSha = null): Deployment
    {
        abort_unless($this->access->environment($actor, $env, 'deploy'), 403);
        if ($automationGrant) {
            app(AutomationAccess::class)->validate($automationGrant, $env->id, $actor->id);
            if ($scheduledFor || ! is_string($expectedTargetSha) || ! preg_match('/^[a-f0-9]{40}$/D', $expectedTargetSha)) {
                throw new DeploymentException('Automation requires an exact commit and immediate deployment.');
            }
        }
        if ($existing = Deployment::where('environment_id', $env->id)->where('user_id', $actor->id)->where('idempotency_key', $key)->first()) {
            if (($existing->candidate['automation_grant_id'] ?? null) !== $automationGrant?->id || ($expectedTargetSha !== null && $existing->target_sha !== $expectedTargetSha)) {
                throw new DeploymentException('This preview key belongs to another authorization or commit.');
            }
            if ($existing->scheduled_for?->toIso8601String() !== $scheduledFor?->utc()->startOfSecond()->toIso8601String()) {
                throw new DeploymentException('This preview key belongs to a different deployment time.');
            }

            return $existing;
        }
        if ($scheduledFor) {
            $scheduledFor = $scheduledFor->utc()->startOfSecond();
            if ($scheduledFor->lessThanOrEqualTo(now()) || $scheduledFor->greaterThan(now()->addDays(30))) {
                throw new DeploymentException('Choose a future deployment time within 30 days.');
            }
        }
        $snapshot = $this->snapshot($env);
        $preflight = $this->inspect($env, $actor, reuseRecent: $automationGrant === null);
        if ($expectedTargetSha !== null && $preflight['target'] !== $expectedTargetSha) {
            throw new DeploymentException('Fetched branch tip does not match the requested commit. Push that commit to the configured branch and prepare again.');
        }
        if ($preflight['count'] === 0 && ! $snapshot['hooks']) {
            throw new DeploymentException('Already up to date. There are no incoming commits to deploy.');
        }
        if ($this->canonical($snapshot) !== $this->canonical($this->snapshot($env))) {
            throw new DeploymentException('Environment configuration changed. Create a fresh preview.');
        }

        if ($automationGrant) {
            app(AutomationAccess::class)->validate($automationGrant, $env->id, $actor->id);
            $snapshot['automation_grant_id'] = $automationGrant->id;
        }

        $deployment = $this->preview($env, $actor, $key, $snapshot, $preflight, $scheduledFor, $timezone);
        if (($deployment->candidate['automation_grant_id'] ?? null) !== $automationGrant?->id || ($expectedTargetSha !== null && $deployment->target_sha !== $expectedTargetSha)) {
            throw new DeploymentException('This preview key belongs to another authorization or commit.');
        }

        return $deployment;
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
        if ($this->canonical($snapshot) !== $this->canonical($this->snapshot($env))) {
            throw new DeploymentException('Environment configuration changed. Create a fresh preview.');
        }

        return $this->preview($env, $actor, $key, [...$snapshot, 'recovery_of' => $source->id], $preflight);
    }

    public function scheduleReviewed(Deployment $source, CarbonImmutable $time, string $timezone): Deployment
    {
        $time = $time->utc()->startOfSecond();
        $key = 'scheduled-preview:'.$source->id;
        if ($existing = Deployment::where('environment_id', $source->environment_id)->where('user_id', $source->user_id)->where('idempotency_key', $key)->first()) {
            if ($source->state !== 'cancelled' || ! $existing->scheduled_for->equalTo($time) || $existing->schedule_timezone !== $timezone) {
                throw new DeploymentException('This review already has a different deployment schedule. Prepare a new deployment.');
            }

            return $existing;
        }
        if ($source->state !== 'awaiting_confirmation' || $source->scheduled_for) {
            throw new DeploymentException('Only an unconfirmed deployment review can choose a new time.');
        }
        if ($time->lessThanOrEqualTo(now()) || $time->greaterThan(now()->addDays(30))) {
            throw new DeploymentException('Choose a future deployment time within 30 days.');
        }
        $this->validate($source);
        // Pin the existing review, including its exact commits and scripts, without another SSH inspection.
        $scheduled = $this->preview($source->environment, $source->user, $key, $source->candidate,
            [...$source->preflight, 'head' => $source->starting_sha, 'target' => $source->target_sha], $time, $timezone);
        $source->update(['state' => 'cancelled', 'finished_at' => now()]);
        Audit::record($source->environment->project->workspace_id, $source->user_id, 'deployment.review_scheduled', 'deployment:'.$scheduled->id, ['source_review' => $source->id]);

        return $scheduled->refresh();
    }

    private function validateRecoverySource(Deployment $source): void
    {
        if ($source->state !== 'failed' || ! $source->code_updated) {
            throw new DeploymentException('Only a failed deployment with updated code can prepare pipeline recovery. Unknown deployments require reconciliation.');
        }
    }

    private function preview(Environment $env, User $actor, string $key, array $snapshot, array $preflight, ?CarbonImmutable $scheduledFor = null, ?string $timezone = null): Deployment
    {
        if (isset($preflight['worktree_digest'])) {
            $snapshot['worktree_digest'] = $preflight['worktree_digest'];
        }
        if ($scheduledFor) {
            if ($scheduledFor->lessThanOrEqualTo(now())) {
                throw new DeploymentException('Scheduled time passed during inspection. Choose a later time.');
            }
            $snapshot['scheduled_for'] = $scheduledFor->toIso8601String();
            $snapshot['schedule_timezone'] = $timezone ?? 'UTC';
        }

        return DB::transaction(function () use ($env, $actor, $key, $snapshot, $preflight, $scheduledFor) {
            $deployment = Deployment::firstOrCreate(['environment_id' => $env->id, 'user_id' => $actor->id, 'idempotency_key' => $key],
                ['starting_sha' => $preflight['head'], 'target_sha' => $preflight['target'], 'candidate' => $snapshot,
                    'digest' => $this->digest($snapshot, $preflight['head'], $preflight['target'], $actor->id), 'preflight' => $preflight,
                    'scheduled_for' => $scheduledFor, 'schedule_timezone' => $snapshot['schedule_timezone'] ?? null,
                    'expires_at' => ($scheduledFor ?? CarbonImmutable::now())->addMinutes(config('deployhub.candidate_minutes'))]);
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
        if ($deployment->scheduled_for && in_array($deployment->state, ['awaiting_confirmation', 'awaiting_approval'], true) && $deployment->scheduled_for->lessThanOrEqualTo(now())) {
            throw new DeploymentException('Scheduled time passed before requester confirmation. Create a fresh schedule.');
        }
        if ($deployment->scheduled_for?->isFuture() && in_array($deployment->state, ['queued', 'running'], true)) {
            throw new DeploymentException('Scheduled deployment is not due yet.');
        }
        if (! $this->access->environment($deployment->user->fresh(), $env, 'deploy')) {
            throw new DeploymentException('Requester deployment permission was revoked.');
        }
        if (config('deployhub.require_mfa') && ! $deployment->user->fresh()->two_factor_confirmed_at) {
            throw new DeploymentException('Requester must configure two-factor authentication.');
        }
        $snapshot = $this->snapshot($env);
        if (isset($deployment->candidate['automation_grant_id'])) {
            app(AutomationAccess::class)->validate(AutomationGrant::find($deployment->candidate['automation_grant_id']), $env->id, $deployment->user_id);
            $snapshot['automation_grant_id'] = $deployment->candidate['automation_grant_id'];
        }
        if ($deployment->scheduled_for) {
            $snapshot['scheduled_for'] = $deployment->scheduled_for->toIso8601String();
            $snapshot['schedule_timezone'] = $deployment->schedule_timezone;
        }
        if (isset($deployment->candidate['recovery_of'])) {
            $source = Deployment::where('environment_id', $env->id)->find($deployment->candidate['recovery_of']);
            if (! $source || $deployment->starting_sha !== $source->target_sha || $deployment->target_sha !== $source->target_sha) {
                throw new DeploymentException('Recovery source does not match this exact candidate.');
            }
            $this->validateRecoverySource($source);
            $snapshot['recovery_of'] = $source->id;
        }
        if (isset($deployment->candidate['worktree_digest'])) {
            $snapshot['worktree_digest'] = $deployment->preflight['worktree_digest'] ?? null;
        }
        if ($this->canonical($deployment->candidate) !== $this->canonical($snapshot)) {
            $changed = array_values(array_filter(array_unique([...array_keys($snapshot), ...array_keys($deployment->candidate)]),
                fn ($key) => $this->canonical([$snapshot[$key] ?? null]) !== $this->canonical([$deployment->candidate[$key] ?? null])));
            throw new DeploymentException('Deployment configuration changed ('.implode(', ', $changed).'). Create a fresh preview.');
        }
        // Existing candidates retain their original digest; MySQL may reorder JSON object keys.
        $legacyDigest = hash('sha256', json_encode([$snapshot, $deployment->starting_sha, $deployment->target_sha, $deployment->user_id], JSON_THROW_ON_ERROR));
        if (! hash_equals($deployment->digest, $this->digest($snapshot, $deployment->starting_sha, $deployment->target_sha, $deployment->user_id)) && ! hash_equals($deployment->digest, $legacyDigest)) {
            throw new DeploymentException('Candidate integrity check failed. Create a fresh preview.');
        }
    }
}
