<?php

namespace Tests\Support;

use App\Services\Deployment\CommandResult;
use App\Services\Deployment\ConnectionLost;
use App\Services\Deployment\RemoteConnection;

class FakeRemote implements RemoteConnection
{
    public string $head;

    public string $target;

    public string $branch = 'staging';

    public string $origin = 'git@example.test:team/app.git';

    public bool $dirty = false;

    public bool $detached = false;

    public bool $diverged = false;

    public bool $failHealth = false;

    public string $healthStatus = '200';

    public bool $loseConnection = false;

    public array $commands = [];

    public function __construct()
    {
        $this->head = str_repeat('a', 40);
        $this->target = str_repeat('b', 40);
    }

    public function run(string $command): CommandResult
    {
        $this->commands[] = $command;
        if (str_contains($command, "'rev-parse' '--show-toplevel'") || str_contains($command, "'pwd' '-P'")) {
            return new CommandResult('/home/deploy/app', 0);
        }
        if (str_contains($command, "'remote' 'get-url'")) {
            return new CommandResult($this->origin, 0);
        }
        if (str_contains($command, "'symbolic-ref'")) {
            return new CommandResult($this->branch, $this->detached ? 1 : 0);
        }
        if (str_contains($command, "'status'")) {
            return new CommandResult($this->dirty ? '?? .env.local' : '', 0);
        }
        if (str_contains($command, "'HEAD^{commit}'")) {
            return new CommandResult($this->head, 0);
        }
        if (str_contains($command, "'fetch'")) {
            return new CommandResult('', 0);
        }
        if (str_contains($command, "'rev-parse'")) {
            return new CommandResult($this->target, 0);
        }
        if (str_contains($command, "'merge-base'")) {
            return new CommandResult('', $this->diverged ? 1 : 0);
        }
        if (str_contains($command, "'rev-list'")) {
            return new CommandResult($this->head === $this->target ? '0' : '1', 0);
        }
        if (str_contains($command, "'log'")) {
            return new CommandResult($this->head === $this->target ? '' : $this->target."\x1fImprove dashboard\x1fFixture author\x1f2026-10-08T12:00:00+08:00\0", 0);
        }
        if (str_contains($command, "'merge'")) {
            if ($this->loseConnection) {
                throw new ConnectionLost('Connection lost during Git update.');
            }
            $this->head = $this->target;

            return new CommandResult('Fast-forward completed.', 0);
        }
        if (str_contains($command, "'curl'")) {
            return new CommandResult($this->failHealth ? 'APP_KEY=base64:sensitive\nhealth failed' : $this->healthStatus, $this->failHealth ? 22 : 0);
        }

        return new CommandResult('Step completed.', 0);
    }
}
