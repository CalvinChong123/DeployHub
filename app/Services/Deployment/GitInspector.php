<?php

namespace App\Services\Deployment;

use App\Models\Environment;

class GitInspector
{
    public function __construct(private CommandBuilder $commands, private LogSanitizer $sanitizer) {}

    private function git(RemoteConnection $remote, Environment $env, array $args): string
    {
        $result = $remote->run($this->commands->git($env->path, $args));
        if ($result->exitCode !== 0) {
            throw new DeploymentException('Repository check failed: '.($args[0] === 'fetch' ? 'fetch failed; check origin access and configured branch.' : 'verify the repository and its configuration.'));
        }

        return trim($result->output);
    }

    public function inspect(RemoteConnection $remote, Environment $env): array
    {
        $this->commands->validatePath($env->path);
        $this->commands->validateBranch($env->branch);
        $root = $this->git($remote, $env, ['rev-parse', '--show-toplevel']);
        $pwd = $remote->run($this->commands->at($env->path, ['pwd', '-P']));
        if ($pwd->exitCode !== 0 || $root !== trim($pwd->output) || $root !== $env->path) {
            throw new DeploymentException('Configured directory must be the canonical repository root, without symlink aliases.');
        }
        $origin = $this->git($remote, $env, ['remote', 'get-url', 'origin']);
        if ($origin !== $env->project->repository) {
            throw new DeploymentException('Repository origin does not match the registered project.');
        }
        $branchResult = $remote->run($this->commands->git($env->path, ['symbolic-ref', '--quiet', '--short', 'HEAD']));
        if ($branchResult->exitCode !== 0) {
            throw new DeploymentException('Detached HEAD is blocked. Restore the configured branch manually.');
        }
        if (trim($branchResult->output) !== $env->branch) {
            throw new DeploymentException('Current branch does not match the configured branch.');
        }
        if ($this->git($remote, $env, ['status', '--porcelain=v1', '--untracked-files=all']) !== '') {
            throw new DeploymentException('Working tree is dirty. Resolve tracked and untracked changes manually.');
        }
        $head = $this->commands->sha($this->git($remote, $env, ['rev-parse', '--verify', 'HEAD^{commit}']));
        $ref = 'refs/remotes/origin/'.$env->branch;
        $this->git($remote, $env, ['fetch', '--no-tags', '--no-recurse-submodules', 'origin', '+refs/heads/'.$env->branch.':'.$ref]);
        $target = $this->commands->sha($this->git($remote, $env, ['rev-parse', '--verify', $ref.'^{commit}']));
        $ancestor = $remote->run($this->commands->git($env->path, ['merge-base', '--is-ancestor', $head, $target]));
        if ($ancestor->exitCode !== 0) {
            throw new DeploymentException('History is diverged or local HEAD is ahead. Fast-forward deployment is blocked.');
        }
        $count = (int) $this->git($remote, $env, ['rev-list', '--count', $head.'..'.$target]);
        if ($count > 200) {
            throw new DeploymentException('More than 200 incoming commits. Review this change outside DeployHub first.');
        }
        $logs = $this->git($remote, $env, ['log', '-z', '--format=%H%x1f%s%x1f%an%x1f%aI', $head.'..'.$target]);
        $commits = [];
        foreach (explode("\0", $logs) as $line) {
            if ($line === '') {
                continue;
            }
            $fields = explode("\x1f", $line);
            if (count($fields) !== 4) {
                throw new DeploymentException('Commit metadata could not be parsed safely.');
            }
            $commits[] = ['sha' => $this->commands->sha($fields[0]), 'subject' => $this->sanitizer->clean($fields[1]), 'author' => $this->sanitizer->clean($fields[2]), 'date' => $fields[3]];
        }
        if (count($commits) !== $count) {
            throw new DeploymentException('Incoming commit list is incomplete. Preview is blocked.');
        }

        return ['head' => $head, 'target' => $target, 'branch' => $env->branch, 'clean' => true, 'fast_forward' => true, 'count' => $count, 'commits' => $commits, 'fetched_at' => now()->toIso8601String()];
    }
}
