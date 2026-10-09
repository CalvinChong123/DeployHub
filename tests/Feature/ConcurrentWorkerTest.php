<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\HubFixture;
use Tests\TestCase;

class ConcurrentWorkerTest extends TestCase
{
    use HubFixture;

    private ?string $database = null;

    protected function setUp(): void
    {
        parent::setUp();
        $parent = storage_path('framework/testing');
        if (! is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        $this->database = $parent.'/deployhub-concurrency-'.bin2hex(random_bytes(8)).'.sqlite';
        touch($this->database);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->database]);
        DB::purge();
        Artisan::call('migrate', ['--force' => true]);
        $this->fixture();
        config(['cache.default' => 'database']);
    }

    public function test_two_independent_worker_processes_execute_candidate_once(): void
    {
        $d = $this->candidate();
        $d->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        $command = [PHP_BINARY, '-d', 'extension=pdo_sqlite', '-d', 'extension=sqlite3', base_path('tests/Support/concurrent-worker.php'), $this->database, (string) $d->id];
        $first = new Process($command, base_path(), timeout: 20);
        $second = new Process($command, base_path(), timeout: 20);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();
        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        $this->assertSame("merge\n", file_get_contents($this->database.'.merges'));
        $this->assertSame('succeeded', $d->fresh()->state);
        $this->assertSame(4, $d->steps()->count());
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        if ($this->database) {
            $parent = realpath(storage_path('framework/testing')).DIRECTORY_SEPARATOR;
            $resolved = realpath($this->database);
            if (! $resolved || ! str_starts_with($resolved, $parent) || ! str_starts_with(basename($resolved), 'deployhub-concurrency-')) {
                throw new \RuntimeException('Invalid cleanup path.');
            }
            foreach ([$this->database, $this->database.'.merges', $this->database.'-wal', $this->database.'-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        parent::tearDown();
    }
}
