<?php

namespace Tests\Support;

use App\Services\Deployment\CommandBuilder;
use App\Services\Deployment\CommandResult;
use App\Services\Deployment\RemoteConnection;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// TEST ONLY: a POSIX shell adapter restricted to a disposable fixture directory.
class LocalGitRemote implements RemoteConnection
{
    private string $shell;

    public function __construct(private string $path)
    {
        $git = (new ExecutableFinder)->find('git');
        $this->shell = PHP_OS_FAMILY === 'Windows' ? dirname(dirname($git)).'/bin/bash.exe' : '/bin/sh';
        if (! is_file($this->shell)) {
            throw new \RuntimeException('Git Bash or /bin/sh required for fixture integration.');
        }
    }

    public static function posix(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows' ? '/'.strtolower($path[0]).substr($path, 2) : $path;
    }

    public function run(string $command): CommandResult
    {
        if (! str_starts_with($command, 'cd -- '.(new CommandBuilder)->quote($this->path).' && ')) {
            throw new \RuntimeException('Fixture command must stay in the test repository.');
        }
        $command = str_replace('protocol.file.allow=never', 'protocol.file.allow=always', $command);
        $process = new Process([$this->shell, '-c', $command], timeout: 20);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();
        if (str_contains($command, "'rev-parse' '--show-toplevel'")) {
            $output = self::posix(trim($output));
        }

        return new CommandResult($output, $process->getExitCode());
    }
}
