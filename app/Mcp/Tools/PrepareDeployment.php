<?php

namespace App\Mcp\Tools;

use App\Services\AutomationDeployments;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class PrepareDeployment extends DeploymentTool
{
    protected string $name = 'prepare_deployment';

    protected string $description = 'Inspect the authorized repository and freeze a deployment preview for the exact pushed commit. Requires the configured branch tip to equal expected_sha. No deployment commands run. Use one UUID idempotency key per intended deployment and reuse it on retries.';

    public function schema(JsonSchema $schema): array
    {
        return ['environment_id' => $schema->integer()->min(1)->required(),
            'expected_sha' => $schema->string()->pattern('^[a-f0-9]{40}$')->description('Full 40-character lowercase Git commit SHA already pushed to the configured branch.')->required(),
            'idempotency_key' => $schema->string()->format('uuid')->description('UUID generated once for this deployment intent; reuse for retries.')->required()];
    }

    public function handle(Request $request, AutomationDeployments $deployments): Response
    {
        $data = $request->validate(['environment_id' => 'required|integer|min:1', 'expected_sha' => ['required', 'string', 'regex:/^[a-f0-9]{40}$/D'], 'idempotency_key' => 'required|uuid']);

        return $this->result(fn () => $deployments->prepare((int) $data['environment_id'], $data['expected_sha'], $data['idempotency_key']));
    }
}
