<?php

namespace App\Services\Deployment;

use App\Models\Environment;

class GitInspector
{
    public const VERSION = 2;

    public function __construct(private CommandBuilder $commands, private LogSanitizer $sanitizer, private InspectionBatch $batch) {}

    private function output(CommandResult $result, string $check, bool $raw = false): string
    {
        if ($result->exitCode !== 0) {
            throw new DeploymentException('Repository check failed: '.($check === 'fetch' ? 'fetch failed; check origin access and configured branch.' : 'verify the repository and its configuration.'));
        }

        return $raw ? $result->output : trim($result->output);
    }

    private function identity(RemoteConnection $remote, Environment $env, bool $includeTarget = false): array
    {
        $this->commands->validatePath($env->path);
        $this->commands->validateBranch($env->branch);
        $commands = ['root' => $this->commands->git($env->path, ['rev-parse', '--show-toplevel']),
            'pwd' => $this->commands->at($env->path, ['pwd', '-P']),
            'origin' => $this->commands->git($env->path, ['remote', 'get-url', 'origin']),
            'branch' => $this->commands->git($env->path, ['symbolic-ref', '--quiet', '--short', 'HEAD']),
            'status' => $this->commands->git($env->path, ['status', '--porcelain=v1', '--untracked-files=all']),
            'head' => $this->commands->git($env->path, ['rev-parse', '--verify', 'HEAD^{commit}'])];
        if ($includeTarget) {
            $commands['target'] = $this->commands->git($env->path, ['rev-parse', '--verify', 'refs/remotes/origin/'.$env->branch.'^{commit}']);
        }
        $results = $this->batch->run($remote, $env->path, $commands);
        $root = $this->output($results['root'], 'root');
        $pwd = $results['pwd'];
        if ($pwd->exitCode !== 0 || $root !== trim($pwd->output) || $root !== $env->path) {
            throw new DeploymentException('Configured directory must be the canonical repository root, without symlink aliases.');
        }
        $origin = $this->output($results['origin'], 'origin');
        if ($origin !== $env->project->repository) {
            throw new DeploymentException('Repository origin does not match the registered project.');
        }
        $branchResult = $results['branch'];
        if ($branchResult->exitCode !== 0) {
            throw new DeploymentException('Detached HEAD is blocked. Restore the configured branch manually.');
        }
        if (trim($branchResult->output) !== $env->branch) {
            throw new DeploymentException('Current branch does not match the configured branch.');
        }
        $status = $this->output($results['status'], 'status', true);
        $head = $this->commands->sha($this->output($results['head'], 'head'));

        return ['head' => $head, 'status' => $status, ...($includeTarget ? ['target' => $this->commands->sha($this->output($results['target'], 'target'))] : [])];
    }

    public function verifyAfterHooks(RemoteConnection $remote, Environment $env): array
    {
        return $this->identity($remote, $env, includeTarget: true);
    }

    public function inspect(RemoteConnection $remote, Environment $env, bool $freezeChanges = false, bool $includeCommits = true): array
    {
        $identity = $this->identity($remote, $env);
        $status = $identity['status'];
        $worktree = $freezeChanges ? $this->worktree($remote, $env, $status) : ['changes' => $this->sanitizer->clean(rtrim($status))];
        $head = $identity['head'];
        $ref = 'refs/remotes/origin/'.$env->branch;
        $targetCommand = $this->commands->git($env->path, ['rev-parse', '--verify', $ref.'^{commit}']);
        // Freeze the fetched tip once, even if another process updates the remote ref during this batch.
        $target = '_deployhub_target=$('.$targetCommand.'); _deployhub_target_exit=$?; printf \'%s\' "$_deployhub_target"; (exit "$_deployhub_target_exit")';
        $commands = ['fetch' => $this->commands->git($env->path, ['fetch', '--no-tags', '--no-recurse-submodules', 'origin', '+refs/heads/'.$env->branch.':'.$ref]),
            'target' => $target,
            'ancestor' => str_replace($this->commands->quote($ref.'^{commit}'), '"$_deployhub_target"',
                $this->commands->git($env->path, ['merge-base', '--is-ancestor', $head, $ref.'^{commit}']))];
        if ($includeCommits) {
            $range = '"'.$head.'..$_deployhub_target"';
            $commands['count'] = str_replace($this->commands->quote($head.'..'.$ref), $range,
                $this->commands->git($env->path, ['rev-list', '--count', $head.'..'.$ref]));
            $commands['logs'] = str_replace($this->commands->quote($head.'..'.$ref), $range,
                $this->commands->git($env->path, ['log', '--max-count=201', '-z', '--format=%H%x1f%s%x1f%an%x1f%aI', $head.'..'.$ref]));
        }
        $results = $this->batch->run($remote, $env->path, $commands);
        $this->output($results['fetch'], 'fetch');
        $target = $this->commands->sha($this->output($results['target'], 'target'));
        $ancestor = $results['ancestor'];
        if ($ancestor->exitCode !== 0) {
            throw new DeploymentException('History is diverged or local HEAD is ahead. Fast-forward deployment is blocked.');
        }
        $countOutput = $includeCommits ? $this->output($results['count'], 'count') : '0';
        if (! preg_match('/^[0-9]+$/D', $countOutput)) {
            throw new DeploymentException('Incoming commit count could not be parsed safely.');
        }
        $count = (int) $countOutput;
        if ($count > 200) {
            throw new DeploymentException('More than 200 incoming commits. Review this change outside DeployHub first.');
        }
        $logs = $includeCommits ? $this->output($results['logs'], 'logs') : '';
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

        return [...$worktree, 'inspection_version' => self::VERSION, 'head' => $head, 'target' => $target, 'branch' => $env->branch, 'clean' => trim($status) === '', 'fast_forward' => true, 'count' => $count, 'commits' => $commits, 'fetched_at' => now()->toIso8601String()];
    }

    private function worktree(RemoteConnection $remote, Environment $env, string $status): array
    {
        // Hash content without persisting diffs or untracked file contents in the preview.
        $results = $this->batch->run($remote, $env->path, [
            'diff' => $this->commands->git($env->path, ['diff', '--no-ext-diff', '--no-textconv', '--binary', 'HEAD', '--']),
            'index' => $this->commands->git($env->path, ['diff', '--cached', '--no-ext-diff', '--no-textconv', '--binary', 'HEAD', '--']),
            'files' => $this->commands->git($env->path, ['ls-files', '--others', '--exclude-standard', '-z'])]);
        $diff = $this->output($results['diff'], 'diff', true);
        $indexDiff = $this->output($results['index'], 'index', true);
        $files = $this->output($results['files'], 'files', true);
        $untracked = array_values(array_filter(explode("\0", $files), fn ($file) => $file !== ''));
        if (count($untracked) > 200 || substr_count($status, "\n") > 200) {
            throw new DeploymentException('More than 200 changed files. Prepare the working tree before reviewing hooks.');
        }
        $hashes = [];
        $commands = [];
        foreach ($untracked as $index => $file) {
            $commands['file_'.$index] = $this->commands->git($env->path, ['hash-object', '--no-filters', '--', $file]);
        }
        $results = $commands ? $this->batch->run($remote, $env->path, $commands) : [];
        foreach ($untracked as $index => $file) {
            $hash = $this->output($results['file_'.$index], 'hash');
            if (! preg_match('/^[a-f0-9]{40}$/D', $hash)) {
                throw new DeploymentException('Untracked file content could not be verified.');
            }
            $hashes[$file] = $hash;
        }

        return ['worktree_digest' => hash('sha256', json_encode([$status, hash('sha256', $diff), hash('sha256', $indexDiff), $hashes], JSON_THROW_ON_ERROR)),
            'changes' => $this->sanitizer->clean(rtrim($status))];
    }
}
