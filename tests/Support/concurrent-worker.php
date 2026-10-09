<?php

use App\Services\Deployment\CommandResult;
use App\Services\Deployment\ConnectionFactory;
use App\Services\Deployment\DeploymentException;
use App\Services\Deployment\DeploymentExecutor;
use App\Services\Deployment\RemoteConnection;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeConnections;
use Tests\Support\FakeRemote;

// Invoked only by ConcurrentWorkerTest with a freshly migrated SQLite fixture.
require __DIR__.'/../../vendor/autoload.php';
$path = $argv[1];
$id = (int) $argv[2];
$resolved = realpath($path);
$parent = realpath(__DIR__.'/../../storage/framework/testing').DIRECTORY_SEPARATOR;
if (! $resolved || ! str_starts_with($resolved, $parent) || ! str_starts_with(basename($resolved), 'deployhub-concurrency-')) {
    throw new RuntimeException('Invalid concurrency fixture path.');
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $resolved, 'cache.default' => 'database', 'deployhub.require_mfa' => false]);
DB::purge();
$remote = new class($resolved) implements RemoteConnection
{
    private FakeRemote $fake;

    public function __construct(private string $path)
    {
        $this->fake = new FakeRemote;
    }

    public function run(string $command): CommandResult
    {
        if (str_contains($command, "'merge'")) {
            file_put_contents($this->path.'.merges', "merge\n", FILE_APPEND | LOCK_EX);
            usleep(200000);
        }

        return $this->fake->run($command);
    }
};
$app->instance(ConnectionFactory::class, new FakeConnections($remote));
try {
    $app->make(DeploymentExecutor::class)->execute($id);
    echo "completed or already claimed\n";
} catch (DeploymentException) {
    echo "blocked by execution lock\n";
}
