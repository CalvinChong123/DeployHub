<?php

namespace App\Services\Deployment;

use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Services\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DeploymentExecutor
{
    private array $knownSecrets = [];

    public function __construct(private CandidateService $candidates, private ConnectionFactory $connections, private GitInspector $inspector, private CommandBuilder $commands, private LogSanitizer $sanitizer) {}

    public function execute(int $id, ?string $executionToken = null): void
    {
        $executionToken ??= (string) Str::uuid();
        $deployment = Deployment::findOrFail($id);
        $lock = Cache::lock('environment:'.$deployment->environment_id, config('deployhub.lock_seconds'));
        if (! $lock->get()) {
            throw new DeploymentException('Environment is locked by another operation.');
        }
        $claimed = false;
        $started = microtime(true);
        $step = null;
        try {
            $claimed = Deployment::whereKey($id)->where('state', 'queued')->update(['state' => 'running', 'execution_token' => $executionToken, 'started_at' => now(), 'heartbeat_at' => now()]) === 1;
            if (! $claimed) {
                return;
            }
            $deployment->refresh();
            $env = $deployment->environment;
            $step = $this->start($deployment, 'preflight');
            $this->candidates->validate($deployment);
            $this->candidates->validateApproval($deployment);
            $remote = new HeartbeatConnection($this->connections->connect($env->server), $deployment->id, $executionToken);
            $credential = $env->server->credential;
            $this->knownSecrets = $credential ? [$credential->private_key, $credential->passphrase] : [];
            $state = $this->inspector->inspect($remote, $env);
            if ($state['head'] !== $deployment->starting_sha || $state['target'] !== $deployment->target_sha) {
                throw new DeploymentException('Repository state changed after review. Create a fresh deployment preview.');
            }
            $this->finish($step, new CommandResult('Clean tree, matching branch, origin and exact candidate verified.', 0));
            $step = null;
            $recovery = isset($deployment->candidate['recovery_of']);
            if ($recovery) {
                $deployment->update(['code_updated' => true]);
            }
            $steps = [...($recovery ? [] : ['fast_forward']), ...$deployment->candidate['pipeline'], 'verify_head'];
            foreach ($steps as $type) {
                $deployment->refresh();
                if ($deployment->state !== 'running' || $deployment->execution_token !== $executionToken) {
                    throw new ConnectionLost('Execution ownership changed. Remote state requires reconciliation.');
                }
                if ($deployment->cancel_requested) {
                    throw new DeploymentException('Cancelled at a safe step boundary. Review any completed steps before retrying.');
                }
                if (microtime(true) - $started > config('deployhub.deployment_timeout') - config('deployhub.command_timeout')) {
                    throw new DeploymentException('Deployment exceeded its execution budget.');
                }
                $this->candidates->validate($deployment);
                $this->candidates->validateApproval($deployment);
                $step = $this->start($deployment, $type);
                $command = match ($type) {
                    'fast_forward' => $this->commands->git($env->path, ['merge', '--ff-only', '--no-edit', '--no-stat', '--no-overwrite-ignore', $this->commands->sha($deployment->target_sha)]),
                    'verify_head' => $this->commands->git($env->path, ['rev-parse', '--verify', 'HEAD^{commit}']),
                    default => $this->commands->step($env->path, $type, $deployment->candidate['health_url']),
                };
                $result = $remote->run($command);
                if ($type === 'health' && $result->exitCode === 0 && ! preg_match('/^2[0-9]{2}$/D', trim($result->output))) {
                    $result = new CommandResult('Health check did not return a 2xx HTTP status. Redirects and malformed responses are failures.', 1);
                }
                $this->finish($step, $result);
                if ($result->exitCode !== 0) {
                    throw new DeploymentException('Mandatory step '.$type.' failed (exit '.$result->exitCode.'). Review the sanitized step log.');
                }
                if ($type === 'fast_forward') {
                    $deployment->update(['code_updated' => true]);
                }
                if ($type === 'verify_head' && trim($result->output) !== $deployment->target_sha) {
                    throw new ConnectionLost('Final HEAD does not match the reviewed commit. Manual reconciliation is required.');
                }
                $step = null;
            }
            $updated = Deployment::whereKey($id)->where('state', 'running')->where('execution_token', $executionToken)->update(['state' => 'succeeded', 'finished_at' => now(), 'active_environment_id' => null]);
            if ($updated !== 1) {
                throw new ConnectionLost('Execution state changed before completion. Remote state requires reconciliation.');
            }
            $env->update(['status' => 'unknown', 'last_preflight' => null]);
        } catch (\Throwable $e) {
            if (! $claimed) {
                throw $e;
            }
            $unknown = $e instanceof ConnectionLost || ! ($e instanceof DeploymentException);
            $error = $e instanceof DeploymentException ? $e->getMessage() : 'Worker encountered an unexpected error. Manual reconciliation is required.';
            if ($step && $step->fresh()->state === 'running') {
                $step->update(['state' => $unknown ? 'unknown' : 'failed', 'finished_at' => now(), 'output' => $this->sanitizer->clean($error)]);
            }
            $deployment->refresh();
            $deployment->update(['state' => $unknown ? 'unknown' : 'failed', 'error' => $this->sanitizer->clean($error), 'finished_at' => now(), 'active_environment_id' => $unknown ? $deployment->environment_id : null]);
        } finally {
            try {
                if ($claimed) {
                    $deployment->refresh();
                    Audit::record($deployment->environment->project->workspace_id, $deployment->user_id, 'deployment.'.$deployment->state, 'deployment:'.$id, ['target' => $deployment->target_sha, 'code_updated' => $deployment->code_updated]);
                }
            } finally {
                $this->knownSecrets = [];
                $lock->release();
            }
        }
    }

    private function start(Deployment $deployment, string $type): DeploymentStep
    {
        $deployment->update(['heartbeat_at' => now()]);

        return $deployment->steps()->create(['type' => $type, 'state' => 'running', 'started_at' => now()]);
    }

    private function finish(DeploymentStep $step, CommandResult $result): void
    {
        // Whole-command buffering avoids leaking secrets split over SSH chunks.
        $step->update(['state' => $result->exitCode === 0 ? 'succeeded' : 'failed', 'exit_code' => $result->exitCode, 'output' => $this->sanitizer->clean($result->output, $this->knownSecrets), 'finished_at' => now()]);
    }
}
