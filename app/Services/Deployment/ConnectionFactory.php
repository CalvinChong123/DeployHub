<?php

namespace App\Services\Deployment;

use App\Models\Server;

interface ConnectionFactory
{
    public function connect(Server $server): RemoteConnection;

    public function discover(Server $server): string;
}
