<?php

namespace App\Mcp\Tools;

use App\Services\AutomationDeployments;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListDeploymentTargets extends DeploymentTool
{
    protected string $name = 'list_deployment_targets';

    protected string $description = 'List the currently usable deployment targets explicitly authorized by this token, including each configured repository and branch. Select the intended target before preparing. Does not contact deployment servers.';

    public function handle(AutomationDeployments $deployments): Response
    {
        return $this->result(fn () => ['targets' => $deployments->targets()]);
    }
}
