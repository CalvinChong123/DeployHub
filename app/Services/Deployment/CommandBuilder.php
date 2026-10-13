<?php

namespace App\Services\Deployment;

class CommandBuilder
{
    public const STEPS = ['composer', 'cache_clear', 'optimize', 'queue_restart', 'health'];

    public function quote(string $argument): string
    {
        return "'".str_replace("'", "'\"'\"'", $argument)."'";
    }

    public function validatePath(string $path): void
    {
        if (! preg_match('~^/[A-Za-z0-9_ ./-]+$~D', $path) || $path === '/' || str_contains($path, '//') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) || rtrim($path, '/') !== $path) {
            throw new DeploymentException('Use a vetted absolute POSIX directory without traversal or shell characters.');
        }
    }

    public function validateBranch(string $branch): void
    {
        if (! preg_match('~^[A-Za-z0-9][A-Za-z0-9_./-]{0,199}$~D', $branch) || str_contains($branch, '..') || str_contains($branch, '//') || str_ends_with($branch, '/') || str_ends_with($branch, '.') || str_ends_with($branch, '.lock') || preg_match('~(?:^|/)\.~', $branch)) {
            throw new DeploymentException('Invalid Git branch name.');
        }
    }

    public function sha(string $sha): string
    {
        if (! preg_match('/^[a-f0-9]{40}$/D', $sha)) {
            throw new DeploymentException('Invalid full Git commit SHA.');
        }

        return $sha;
    }

    public function at(string $path, array $arguments): string
    {
        $this->validatePath($path);

        return 'cd -- '.$this->quote($path).' && '.implode(' ', array_map($this->quote(...), $arguments));
    }

    public function git(string $path, array $arguments): string
    {
        return $this->at($path, ['env', 'GIT_TERMINAL_PROMPT=0', 'GIT_OPTIONAL_LOCKS=0', 'git', '-c', 'core.hooksPath=/dev/null', '-c', 'protocol.ext.allow=never', '-c', 'protocol.file.allow=never', ...$arguments]);
    }

    public function step(string $path, string $type, ?string $healthUrl): string
    {
        return match ($type) {
            'hard_reset' => $this->git($path, ['reset', '--hard', 'HEAD']),
            'composer' => $this->at($path, ['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader', '--no-scripts', '--no-plugins']),
            'cache_clear' => $this->at($path, ['php', 'artisan', 'optimize:clear', '--no-interaction']),
            'optimize' => $this->at($path, ['php', 'artisan', 'optimize', '--no-interaction']),
            'queue_restart' => $this->at($path, ['php', 'artisan', 'queue:restart', '--no-interaction']),
            'health' => $this->at($path, ['curl', '--fail', '--silent', '--show-error', '--max-time', '15', '--proto', '=https', '--max-redirs', '0', '--output', '/dev/null', '--write-out', '%{http_code}', $this->healthUrl($healthUrl)]),
            default => throw new DeploymentException('This pipeline step is not allowlisted.'),
        };
    }

    public function hook(string $path, string $script): string
    {
        if (trim($script) === '' || strlen($script) > 40000 || str_contains($script, "\0")) {
            throw new DeploymentException('Invalid deployment hook script.');
        }

        return $this->at($path, ['env', 'GIT_TERMINAL_PROMPT=0', 'sh', '-e', '-c', str_replace(["\r\n", "\r"], "\n", $script)]);
    }

    public function healthUrl(?string $url): string
    {
        $parts = parse_url($url ?? '');
        if (! filter_var($url, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new DeploymentException('Health check requires an HTTPS URL without credentials, query or fragment.');
        }

        return $url;
    }
}
