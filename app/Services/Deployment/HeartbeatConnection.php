<?php

namespace App\Services\Deployment;

use App\Models\Deployment;

class HeartbeatConnection implements RemoteConnection
{
    public function __construct(private RemoteConnection $remote, private int $deploymentId, private string $executionToken) {}

    public function run(string $command): CommandResult
    {
        $query = Deployment::whereKey($this->deploymentId)->where('state', 'running')->where('execution_token', $this->executionToken);
        if (! $query->exists()) {
            throw new ConnectionLost('Execution ownership changed. Remote state requires reconciliation.');
        }
        $query->update(['heartbeat_at' => now()]);

        return $this->remote->run($command);
    }
}
