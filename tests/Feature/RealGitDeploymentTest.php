<?php

namespace Tests\Feature;

use App\Services\Deployment\CommandBuilder;
use App\Services\Deployment\CommandResult;
use App\Services\Deployment\ConnectionFactory;
use App\Services\Deployment\DeploymentException;
use App\Services\Deployment\DeploymentExecutor;
use App\Services\Deployment\DeploymentWorkflow;
use App\Services\Deployment\RemoteConnection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FakeConnections;
use Tests\Support\HubFixture;
use Tests\Support\LocalGitRemote;
use Tests\TestCase;

class RealGitDeploymentTest extends TestCase
{
    use HubFixture, RefreshDatabase;

    private ?string $fixtureRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        $fixtureParent = storage_path('framework/testing');
        if (! is_dir($fixtureParent)) {
            mkdir($fixtureParent, 0777, true);
        }
        $this->fixtureRoot = $fixtureParent.DIRECTORY_SEPARATOR.'deployhub-fixture-'.bin2hex(random_bytes(8));
        mkdir($this->fixtureRoot);
        $this->git(['init', '--bare', $this->fixtureRoot.'/origin.git']);
        $this->git(['init', '--initial-branch=staging', $this->fixtureRoot.'/source']);
        $this->git(['-C', $this->fixtureRoot.'/source', 'config', 'user.email', 'fixture@example.test']);
        $this->git(['-C', $this->fixtureRoot.'/source', 'config', 'user.name', 'Disposable fixture']);
        file_put_contents($this->fixtureRoot.'/source/app.txt', 'v1');
        mkdir($this->fixtureRoot.'/source/public');
        file_put_contents($this->fixtureRoot.'/source/public/.htaccess', 'Original handler');
        file_put_contents($this->fixtureRoot.'/source/.gitignore', "*.local\n");
        $this->commit('Initial fixture');
        $this->git(['-C', $this->fixtureRoot.'/source', 'remote', 'add', 'origin', $this->fixtureRoot.'/origin.git']);
        $this->git(['-C', $this->fixtureRoot.'/source', 'push', 'origin', 'staging']);
        $this->git(['clone', '--branch', 'staging', $this->fixtureRoot.'/origin.git', $this->fixtureRoot.'/target']);
        $origin = $this->git(['-C', $this->fixtureRoot.'/target', 'remote', 'get-url', 'origin']);
        $this->environment->project->update(['repository' => trim($origin)]);
        $path = LocalGitRemote::posix($this->fixtureRoot.'/target');
        $this->environment->update(['path' => $path, 'pipeline' => []]);
        $this->app->instance(ConnectionFactory::class, new FakeConnections(new LocalGitRemote($path)));
        file_put_contents($this->fixtureRoot.'/source/app.txt', 'v2');
        $this->commit('Incoming fixture change');
        $this->git(['-C', $this->fixtureRoot.'/source', 'push', 'origin', 'staging']);
    }

    private function git(array $arguments): string
    {
        $process = new Process(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'protocol.file.allow=always', ...$arguments], timeout: 20);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function commit(string $message): void
    {
        $this->git(['-C', $this->fixtureRoot.'/source', 'add', '.']);
        $this->git(['-C', $this->fixtureRoot.'/source', 'commit', '-m', $message]);
    }

    protected function tearDown(): void
    {
        if ($this->fixtureRoot && is_dir($this->fixtureRoot)) {
            $resolved = realpath($this->fixtureRoot);
            $temp = realpath(storage_path('framework/testing')).DIRECTORY_SEPARATOR;
            if (! str_starts_with($resolved, $temp) || ! str_starts_with(basename($resolved), 'deployhub-fixture-')) {
                throw new \RuntimeException('Refusing unsafe fixture cleanup.');
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS)) as $entry) {
                if ($entry->isFile() && ! $entry->isLink()) {
                    chmod($entry->getPathname(), 0666);
                }
            }
            if (! (new Filesystem)->deleteDirectory($resolved)) {
                throw new \RuntimeException('Disposable Git fixture could not be cleaned up.');
            }
        }
        parent::tearDown();
    }

    public function test_real_repository_deploys_the_reviewed_sha_end_to_end(): void
    {
        $d = $this->candidate();
        $this->assertSame(1, $d->preflight['count']);
        $this->assertSame('Incoming fixture change', $d->preflight['commits'][0]['subject']);
        $d->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('succeeded', $d->fresh()->state);
        $this->assertSame($d->target_sha, $this->git(['-C', $this->fixtureRoot.'/target', 'rev-parse', 'HEAD']));
        $this->assertSame('v2', file_get_contents($this->fixtureRoot.'/target/app.txt'));
    }

    public function test_commit_list_uses_the_captured_tip_when_tracking_ref_changes_during_inspection(): void
    {
        $earlier = $this->git(['-C', $this->fixtureRoot.'/source', 'rev-parse', 'HEAD']);
        file_put_contents($this->fixtureRoot.'/source/app.txt', 'v3');
        $this->commit('Additional fixture change');
        $this->git(['-C', $this->fixtureRoot.'/source', 'push', 'origin', 'staging']);
        $latest = $this->git(['-C', $this->fixtureRoot.'/source', 'rev-parse', 'HEAD']);
        $path = LocalGitRemote::posix($this->fixtureRoot.'/target');
        $builder = new CommandBuilder;
        $changeRef = $builder->git($path, ['update-ref', 'refs/remotes/origin/staging', $earlier]);
        $replacement = substr($builder->quote('_deployhub_target_exit=$?; '.$changeRef.'; printf'), 1, -1);
        $remote = new class(new LocalGitRemote($path), $replacement) implements RemoteConnection
        {
            public function __construct(private LocalGitRemote $remote, private string $replacement) {}

            public function run(string $command): CommandResult
            {
                // Simulate another process moving the tracking ref after this batch captures its target.
                return $this->remote->run(str_replace('_deployhub_target_exit=$?; printf', $this->replacement, $command));
            }
        };
        $this->app->instance(ConnectionFactory::class, new FakeConnections($remote));
        $d = $this->candidate();
        $this->assertSame($latest, $d->target_sha);
        $this->assertSame(2, $d->preflight['count']);
        $this->assertSame($latest, $d->preflight['commits'][0]['sha']);
        $this->assertSame($earlier, $this->git(['-C', $this->fixtureRoot.'/target', 'rev-parse', 'refs/remotes/origin/staging']));
    }

    public function test_real_overlapping_changes_are_rejected_by_git_and_preserved(): void
    {
        file_put_contents($this->fixtureRoot.'/target/app.txt', 'local work');
        $d = $this->candidate();
        $this->assertFalse($d->preflight['clean']);
        $d->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertFalse($d->fresh()->code_updated);
        $this->assertSame($d->starting_sha, $this->git(['-C', $this->fixtureRoot.'/target', 'rev-parse', 'HEAD']));
        $this->assertSame('local work', file_get_contents($this->fixtureRoot.'/target/app.txt'));
    }

    public function test_real_nonoverlapping_cpanel_changes_and_untracked_logs_are_preserved(): void
    {
        file_put_contents($this->fixtureRoot.'/target/public/.htaccess', 'cPanel handler');
        file_put_contents($this->fixtureRoot.'/target/public/error_log', 'untracked log');
        $d = $this->candidate();
        $d->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('succeeded', $d->fresh()->state);
        $this->assertSame('v2', file_get_contents($this->fixtureRoot.'/target/app.txt'));
        $this->assertSame('cPanel handler', file_get_contents($this->fixtureRoot.'/target/public/.htaccess'));
        $this->assertSame('untracked log', file_get_contents($this->fixtureRoot.'/target/public/error_log'));
    }

    public function test_builtin_hard_reset_discards_tracked_changes_without_a_blanket_clean(): void
    {
        file_put_contents($this->fixtureRoot.'/target/app.txt', 'local work');
        file_put_contents($this->fixtureRoot.'/target/public/error_log', 'retained log');
        $hooks = DeploymentWorkflow::defaults();
        $hooks[0]['enabled'] = true;
        $this->environment->update(['hooks' => $hooks]);
        $d = $this->candidate();
        $d->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('succeeded', $d->fresh()->state);
        $this->assertSame('v2', file_get_contents($this->fixtureRoot.'/target/app.txt'));
        $this->assertSame('retained log', file_get_contents($this->fixtureRoot.'/target/public/error_log'));
    }

    public function test_reviewed_pre_hook_resets_tracked_changes_and_removes_only_named_untracked_log(): void
    {
        file_put_contents($this->fixtureRoot.'/target/app.txt', 'cPanel-style local change');
        file_put_contents($this->fixtureRoot.'/target/error_log', 'untracked log');
        file_put_contents($this->fixtureRoot.'/target/settings.local', 'retained configuration');
        $this->environment->update(['hooks' => [['id' => (string) Str::uuid(), 'name' => 'Prepare checkout', 'phase' => 'pre',
            'script' => "git reset --hard HEAD\nrm -f -- error_log\nprintf '%s' \"a'b; literal\" > hook.local", 'enabled' => true]]]);
        $d = $this->candidate();
        $this->assertFalse($d->preflight['clean']);
        $this->assertStringContainsString('error_log', $d->preflight['changes']);
        $this->assertSame('cPanel-style local change', file_get_contents($this->fixtureRoot.'/target/app.txt'));
        $d->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('succeeded', $d->fresh()->state);
        $this->assertSame('v2', file_get_contents($this->fixtureRoot.'/target/app.txt'));
        $this->assertFileDoesNotExist($this->fixtureRoot.'/target/error_log');
        $this->assertSame('retained configuration', file_get_contents($this->fixtureRoot.'/target/settings.local'));
        $this->assertSame("a'b; literal", file_get_contents($this->fixtureRoot.'/target/hook.local'));
    }

    public function test_real_hook_stops_on_failed_command_before_later_lines_or_git_merge(): void
    {
        $this->environment->update(['hooks' => [['id' => (string) Str::uuid(), 'name' => 'Fail early', 'phase' => 'pre',
            'script' => "false\nprintf executed > should-not-exist.local", 'enabled' => true]]]);
        $d = $this->candidate();
        $d->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertFileDoesNotExist($this->fixtureRoot.'/target/should-not-exist.local');
        $this->assertSame($d->starting_sha, $this->git(['-C', $this->fixtureRoot.'/target', 'rev-parse', 'HEAD']));
    }

    public function test_real_changed_untracked_contents_block_pre_hook_cleanup(): void
    {
        file_put_contents($this->fixtureRoot.'/target/error_log', 'first content');
        $this->environment->update(['hooks' => [['id' => (string) Str::uuid(), 'name' => 'Remove log', 'phase' => 'pre',
            'script' => 'rm -f -- error_log', 'enabled' => true]]]);
        $d = $this->candidate();
        file_put_contents($this->fixtureRoot.'/target/error_log', 'changed after review');
        $d->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertStringContainsString('Working tree changed', $d->fresh()->error);
        $this->assertSame('changed after review', file_get_contents($this->fixtureRoot.'/target/error_log'));
    }

    public function test_incoming_commit_cannot_overwrite_an_ignored_local_config_file(): void
    {
        file_put_contents($this->fixtureRoot.'/target/app.local', 'local configuration');
        file_put_contents($this->fixtureRoot.'/source/app.local', 'incoming configuration');
        $this->git(['-C', $this->fixtureRoot.'/source', 'add', '-f', 'app.local']);
        $this->git(['-C', $this->fixtureRoot.'/source', 'commit', '-m', 'Track formerly ignored configuration']);
        $this->git(['-C', $this->fixtureRoot.'/source', 'push', 'origin', 'staging']);
        $d = $this->candidate();
        $d->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertSame('local configuration', file_get_contents($this->fixtureRoot.'/target/app.local'));
        $this->assertSame($d->starting_sha, $this->git(['-C', $this->fixtureRoot.'/target', 'rev-parse', 'HEAD']));
    }

    public function test_real_divergence_is_blocked_without_rewriting_history(): void
    {
        $this->git(['-C', $this->fixtureRoot.'/target', 'config', 'user.email', 'fixture@example.test']);
        $this->git(['-C', $this->fixtureRoot.'/target', 'config', 'user.name', 'Fixture']);
        file_put_contents($this->fixtureRoot.'/target/other.txt', 'local divergence');
        $this->git(['-C', $this->fixtureRoot.'/target', 'add', '.']);
        $this->git(['-C', $this->fixtureRoot.'/target', 'commit', '-m', 'Local-only commit']);
        $head = $this->git(['-C', $this->fixtureRoot.'/target', 'rev-parse', 'HEAD']);
        try {
            $this->candidate();
            $this->fail('Divergence accepted.');
        } catch (DeploymentException $e) {
            $this->assertStringContainsString('diverged', $e->getMessage());
        }
        $this->assertSame($head, $this->git(['-C', $this->fixtureRoot.'/target', 'rev-parse', 'HEAD']));
    }

    public function test_real_remote_advance_after_review_does_not_deploy_newer_code(): void
    {
        $d = $this->candidate();
        file_put_contents($this->fixtureRoot.'/source/app.txt', 'v3');
        $this->commit('Newer unreviewed commit');
        $this->git(['-C', $this->fixtureRoot.'/source', 'push', 'origin', 'staging']);
        $d->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertSame($d->starting_sha, $this->git(['-C', $this->fixtureRoot.'/target', 'rev-parse', 'HEAD']));
    }

    public function test_real_detached_head_is_blocked(): void
    {
        $this->git(['-C', $this->fixtureRoot.'/target', 'checkout', '--detach']);
        $this->expectException(DeploymentException::class);
        $this->expectExceptionMessage('Detached HEAD');
        $this->candidate();
    }
}
