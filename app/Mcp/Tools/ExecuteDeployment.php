<?php

namespace App\Mcp\Tools;

use App\Services\AutomationDeployments;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class ExecuteDeployment extends DeploymentTool
{
    protected string $name = 'execute_deployment';

    protected string $description = 'Confirm the exact preview after reviewing prepare_deployment output and the user has requested deployment. Unprotected targets enter the queue without website confirmation; production and protected targets await independent approval. Neither queued nor awaiting_approval means success. Retries never start another run.';

    public function schema(JsonSchema $schema): array
    {
        return ['deployment_id' => $schema->integer()->min(1)->required(),
            'digest' => $schema->string()->pattern('^[a-f0-9]{64}$')->description('Exact 64-character digest returned by prepare_deployment.')->required()];
    }

    public function handle(Request $request, AutomationDeployments $deployments): Response
    {
        $data = $request->validate(['deployment_id' => 'required|integer|min:1', 'digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);

        return $this->result(fn () => $deployments->execute((int) $data['deployment_id'], $data['digest']));
    }
}
