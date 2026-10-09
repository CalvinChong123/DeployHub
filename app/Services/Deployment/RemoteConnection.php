<?php

namespace App\Services\Deployment;

interface RemoteConnection
{
    public function run(string $command): CommandResult;
}
