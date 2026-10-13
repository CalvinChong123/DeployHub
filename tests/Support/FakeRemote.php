<?php

namespace Tests\Support;

use App\Services\Deployment\CommandBuilder;
use App\Services\Deployment\CommandResult;
use App\Services\Deployment\ConnectionLost;
use App\Services\Deployment\RemoteConnection;

class FakeRemote implements RemoteConnection
{
    public string $head;

    public string $target;

    public string $branch = 'staging';

    public string $origin = 'git@example.test:team/app.git';

    public string $root = '/home/deploy/app';

    public bool $dirty = false;

    public bool $detached = false;

    public bool $diverged = false;

    public bool $failHealth = false;

    public string $healthStatus = '200';

    public bool $loseConnection = false;

    public array $commands = [];

    public string $worktreeContent = 'local work';

    public array $hookHandlers = [];

    public function __construct()
    {
        $this->head = str_repeat('a', 40);
        $this->target = str_repeat('b', 40);
    }

    public function run(string $command): CommandResult
    {
        $this->commands[] = $command;
        if (str_contains($command, " && 'sh' '-c' ")) {
            $script = substr($command, strpos($command, " && 'sh' '-c' ") + strlen(" && 'sh' '-c' "));
            $script = str_replace("'\"'\"'", "'", substr($script, 1, -1));
            $lines = explode("\n", $script);
            $output = '';
            for ($i = 0; $i < count($lines); $i += 4) {
                if (! preg_match("/^(?:printf).*'(DH[a-f0-9]+):([a-z][a-z0-9_]*):start'$/", $lines[$i], $match)) {
                    throw new \RuntimeException('Invalid fixture batch.');
                }
                $length = count($this->commands);
                $result = $this->run(substr($lines[$i + 1], 0, -5));
                array_splice($this->commands, $length);
                $output .= "\n".$match[1].':'.$match[2].":start\n".$result->output."\n".$match[1].':'.$match[2].':end:'.$result->exitCode."\n";
            }

            return new CommandResult($output, 0);
        }
        if (str_contains($command, "'reset' '--hard' 'HEAD'")) {
            $this->dirty = false;

            return new CommandResult('Tracked files reset.', 0);
        }
        if (str_contains($command, "'sh' '-e' '-c'")) {
            foreach ($this->hookHandlers as $script => $handler) {
                if (str_contains($command, (new CommandBuilder)->quote($script))) {
                    return $handler($this);
                }
            }

            return new CommandResult('Hook completed.', 0);
        }
        if (str_contains($command, "'diff'")) {
            return new CommandResult($this->dirty ? $this->worktreeContent : '', 0);
        }
        if (str_contains($command, "'ls-files'")) {
            return new CommandResult($this->dirty ? ".env.local\0" : '', 0);
        }
        if (str_contains($command, "'hash-object'")) {
            return new CommandResult(sha1($this->worktreeContent), 0);
        }
        if (str_contains($command, "'rev-parse' '--show-toplevel'") || str_contains($command, "'pwd' '-P'")) {
            return new CommandResult($this->root, 0);
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
