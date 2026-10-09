<?php

namespace App\Services\Deployment;

readonly class CommandResult
{
    public function __construct(public string $output, public int $exitCode) {}
}
