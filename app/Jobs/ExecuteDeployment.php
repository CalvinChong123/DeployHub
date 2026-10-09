<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Services\Audit;
use App\Services\Deployment\DeploymentExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class ExecuteDeployment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 960;

    public bool $failOnTimeout = true;

    public string $executionToken;

    public function __construct(public int $deploymentId)
    {
        $this->executionToken = (string) Str::uuid();
    }

    public function handle(DeploymentExecutor $executor): void
    {
        $executor->execute($this->deploymentId, $this->executionToken);
    }

    public function failed(?\Throwable $exception): void
    {
        $changed = Deployment::whereKey($this->deploymentId)->where('state', 'running')->where('execution_token', $this->executionToken)->update(['state' => 'unknown', 'error' => 'Worker stopped unexpectedly. Check remote state before releasing this environment.', 'finished_at' => now()]);
        if ($changed) {
            DeploymentStep::where('deployment_id', $this->deploymentId)->where('state', 'running')->update(['state' => 'unknown', 'finished_at' => now()]);
        }
        if ($changed) {
            $deployment = Deployment::findOrFail($this->deploymentId);
            Audit::record($deployment->environment->project->workspace_id, null, 'deployment.unknown', 'deployment:'.$deployment->id, ['worker_failure' => true]);
        }
        Deployment::whereKey($this->deploymentId)->where('state', 'queued')->update(['state' => 'failed', 'error' => 'Job could not acquire the environment or start execution. Create a fresh preview.', 'finished_at' => now(), 'active_environment_id' => null]);
    }
}
