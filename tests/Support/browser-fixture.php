<?php

use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Local UI review only. Uses its own workspace and leaves the real owner untouched.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local')) {
    throw new RuntimeException('Browser fixture is local-only.');
}

if (($argv[1] ?? '') === 'cleanup') {
    $workspace = Workspace::where('slug', 'browser-ui-fixture')->first();
    if ($workspace) {
        DB::transaction(function () use ($workspace) {
            $userIds = $workspace->users()->pluck('users.id');
            $projectIds = Project::where('workspace_id', $workspace->id)->pluck('id');
            $environmentIds = DB::table('environments')->whereIn('project_id', $projectIds)->pluck('id');
            if (DB::table('deployments')->whereIn('environment_id', $environmentIds)->exists()) {
                throw new RuntimeException('Fixture has deployments; refusing automatic cleanup.');
            }
            DB::table('environment_permissions')->whereIn('environment_id', $environmentIds)->delete();
            DB::table('environments')->whereIn('id', $environmentIds)->delete();
            DB::table('project_user')->whereIn('project_id', $projectIds)->delete();
            DB::table('projects')->whereIn('id', $projectIds)->delete();
            $serverIds = Server::where('workspace_id', $workspace->id)->pluck('id');
            DB::table('server_user')->whereIn('server_id', $serverIds)->delete();
            DB::table('servers')->whereIn('id', $serverIds)->delete();
            DB::table('workspace_user')->where('workspace_id', $workspace->id)->delete();
            DB::table('sessions')->whereIn('user_id', $userIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
            $workspace->delete();
        });
    } echo "Disposable browser fixture removed.\n";
    exit;
}
$workspace = Workspace::create(['name' => 'UI test workspace', 'slug' => 'browser-ui-fixture']);
$password = Str::random(30);
$user = User::create(['name' => 'Test reviewer', 'email' => 'ui-review@example.test', 'password' => $password]);
$workspace->users()->attach($user, ['role' => 'admin', 'active' => true]);
$server = Server::create(['workspace_id' => $workspace->id, 'name' => 'Example staging host', 'host' => 'ssh.example.invalid', 'username' => 'deploy', 'port' => 22]);
$project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Example Laravel app', 'repository' => 'git@example.invalid:team/app.git', 'description' => 'Disposable UI fixture — connection unverified']);
$env = $project->environments()->create(['server_id' => $server->id, 'name' => 'Staging', 'path' => '/home/deploy/example', 'branch' => 'staging', 'pipeline' => ['cache_clear'], 'status' => 'unknown']);
echo json_encode(['email' => $user->email, 'password' => $password, 'environment_id' => $env->id]);
