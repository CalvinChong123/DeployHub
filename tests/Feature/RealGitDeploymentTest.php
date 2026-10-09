<?php

namespace Tests\Feature;

use App\Services\Deployment\ConnectionFactory;
use App\Services\Deployment\DeploymentException;
use App\Services\Deployment\DeploymentExecutor;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_real_dirty_tree_is_blocked_and_preserved(): void
    {
        file_put_contents($this->fixtureRoot.'/target/app.txt', 'local work');
        try {
            $this->candidate();
            $this->fail('Dirty tree accepted.');
        } catch (DeploymentException $e) {
            $this->assertStringContainsString('dirty', $e->getMessage());
        }
        $this->assertSame('local work', file_get_contents($this->fixtureRoot.'/target/app.txt'));
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
