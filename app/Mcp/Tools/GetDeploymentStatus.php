<?php

namespace App\Mcp\Tools;

use App\Services\AutomationDeployments;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetDeploymentStatus extends DeploymentTool
{
    protected string $name = 'get_deployment_status';

    protected string $description = 'Read the state and sanitized step logs of a deployment created by this automation token. Poll at intervals of at least five seconds. Report success only for succeeded; failed or unknown requires review.';

    public function schema(JsonSchema $schema): array
    {
        return ['deployment_id' => $schema->integer()->min(1)->required()];
    }

    public function handle(Request $request, AutomationDeployments $deployments): Response
    {
        $data = $request->validate(['deployment_id' => 'required|integer|min:1']);

        return $this->result(fn () => $deployments->status((int) $data['deployment_id']));
    }
}
