<?php

namespace App\Mcp\Tools;

use App\Services\AutomationDeployments;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListDeploymentTargets extends DeploymentTool
{
    protected string $name = 'list_deployment_targets';

    protected string $description = 'List the deployment target authorized by this token, including the configured repository, branch and independent approval requirements. Does not contact the server.';

    public function handle(AutomationDeployments $deployments): Response
    {
        return $this->result(fn () => ['targets' => [$deployments->target()]]);
    }
}
