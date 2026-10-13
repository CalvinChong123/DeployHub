<?php

namespace App\Mcp\Tools;

use App\Services\Deployment\DeploymentException;
use App\Services\Deployment\LogSanitizer;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

abstract class DeploymentTool extends Tool
{
    protected function result(callable $operation): Response
    {
        try {
            return Response::json($operation());
        } catch (DeploymentException $error) {
            return Response::error(app(LogSanitizer::class)->clean($error->getMessage()));
        }
    }
}
