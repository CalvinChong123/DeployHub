<?php

namespace Tests\Support;

use App\Models\Server;
use App\Services\Deployment\ConnectionFactory;
use App\Services\Deployment\RemoteConnection;

class FakeConnections implements ConnectionFactory
{
    public function __construct(public RemoteConnection $remote) {}

    public function connect(Server $server): RemoteConnection
    {
        return $this->remote;
    }

    public function discover(Server $server): string
    {
        return 'SHA256:'.str_repeat('x', 43);
    }
}
