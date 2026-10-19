<?php

namespace App\Services;

use App\Models\AutomationGrant;
use App\Models\Deployment;
use App\Services\Deployment\CandidateService;
use App\Services\Deployment\DeploymentConfirmation;
use App\Services\Deployment\DeploymentException;
use App\Services\Deployment\LogSanitizer;
use Illuminate\Support\Facades\RateLimiter;

class AutomationDeployments
{
    public function __construct(private AutomationAccess $access, private CandidateService $candidates, private DeploymentConfirmation $confirmation, private LogSanitizer $sanitizer) {}

    public function grant(): AutomationGrant
    {
        return $this->access->validate(request()->attributes->get('automation_grant'));
    }

    public function targets(): array
    {
        $grant = $this->grant();

        return $this->access->targets($grant)->map(fn ($environment) => ['environment_id' => $environment->id, 'project' => $this->sanitizer->clean($environment->project->name),
            'environment' => $this->sanitizer->clean($environment->name), 'repository' => $this->sanitizer->clean($environment->project->repository),
            'branch' => $environment->branch, 'server' => $this->sanitizer->clean($environment->server->name),
            'production' => $environment->production, 'requires_independent_approval' => false,
            'expires_at' => $grant->expires_at->toIso8601String()])->all();
    }

    public function prepare(int $environmentId, string $expectedSha, string $key): array
    {
        $grant = $this->grant();
        $grant = $this->access->validate($grant, $environmentId);
        $rateKey = 'automation:prepare:'.$grant->id;
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            throw new DeploymentException('Too many preview requests. Retry after one minute using the same idempotency key.');
        }
        RateLimiter::hit($rateKey, 60);
        $deployment = $this->candidates->create($grant->environments->find($environmentId), $grant->user, $key, automationGrant: $grant, expectedTargetSha: $expectedSha);

        return $this->review($deployment->refresh());
    }

    public function execute(int $id, string $digest): array
    {
        $grant = $this->grant();
        $deployment = $this->deployment($grant, $id);
        if ($deployment->scheduled_for || isset($deployment->candidate['recovery_of'])) {
            throw new DeploymentException('This automation grant only supports immediate deployments.');
        }
        if (! hash_equals($deployment->digest, $digest)) {
            throw new DeploymentException('Candidate digest does not match.');
        }
        // Retrying after a lost response reports the same run, including terminal states.
        if (! in_array($deployment->state, ['awaiting_confirmation', 'awaiting_approval'], true)) {
            return $this->review($deployment);
        }
        $deployment = $this->confirmation->confirm($deployment, $grant->user, $digest);

        return $this->review($deployment);
    }

    public function status(int $id): array
    {
        $deployment = $this->deployment($this->grant(), $id);
        $data = $this->review($deployment);
        $data['steps'] = $deployment->steps()->orderBy('id')->get()->map(fn ($step) => [
            'type' => $step->type, 'state' => $step->state, 'exit_code' => $step->exit_code,
            'output' => $this->sanitizer->clean($step->output ?? ''),
            'started_at' => $step->started_at?->toIso8601String(), 'finished_at' => $step->finished_at?->toIso8601String(),
        ])->all();

        return $data;
    }

    private function deployment(AutomationGrant $grant, int $id): Deployment
    {
        $deployment = Deployment::where('user_id', $grant->user_id)->find($id);
        if (! $deployment || ($deployment->candidate['automation_grant_id'] ?? null) !== $grant->id) {
            throw new DeploymentException('Deployment is not available under this automation grant.');
        }
        $this->access->validate($grant, $deployment->environment_id, $deployment->user_id);

        return $deployment;
    }

    private function review(Deployment $deployment): array
    {
        $data = ['deployment_id' => $deployment->id, 'environment_id' => $deployment->environment_id,
            'state' => $deployment->state, 'starting_sha' => $deployment->starting_sha, 'target_sha' => $deployment->target_sha,
            'requires_independent_approval' => false,
            'digest' => $deployment->digest, 'expires_at' => $deployment->expires_at->toIso8601String(),
            'code_updated' => $deployment->code_updated, 'error' => $this->sanitizer->clean($deployment->error ?? ''),
            'review_url' => route('deployments.show', $deployment), 'worker_online' => app(WorkerHealth::class)->status()['online'],
            'workflow' => ['pipeline' => $deployment->candidate['pipeline'], 'hooks' => array_map(fn ($hook) => [
                'name' => $this->sanitizer->clean($hook['name']), 'phase' => $hook['phase'], 'type' => $hook['type'] ?? 'shell',
                'script' => $this->sanitizer->clean($hook['script']),
            ], $deployment->candidate['hooks'] ?? [])],
            'commits' => array_map(fn ($commit) => ['sha' => $commit['sha'], 'subject' => $this->sanitizer->clean($commit['subject'])], $deployment->preflight['commits'] ?? [])];
        $data['message'] = match ($deployment->state) {
            'awaiting_confirmation' => 'Preview only. Review the frozen workflow, then call execute_deployment with this deployment ID and digest.',
            'queued' => 'Queued; execution has not started. Poll get_deployment_status until a terminal state.',
            'awaiting_approval' => 'Legacy pending request. Independent approval is retired. Review and call execute_deployment to confirm this exact candidate; it will not execute automatically.',
            'running' => 'Deployment is running. Poll get_deployment_status.',
            'succeeded' => 'Deployment pipeline completed successfully.',
            'unknown' => 'Remote state is uncertain. An administrator must reconcile it; do not automatically retry.',
            default => 'Review the recorded outcome and step logs. Do not automatically create a replacement deployment.',
        };

        return $data;
    }
}
