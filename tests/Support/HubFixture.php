<?php

namespace Tests\Support;

use App\Models\Environment;
use App\Models\EnvironmentPermission;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Deployment\CandidateService;
use App\Services\Deployment\ConnectionFactory;
use Illuminate\Support\Str;

trait HubFixture
{
    protected User $owner;

    protected Workspace $workspace;

    protected Environment $environment;

    protected FakeRemote $remote;

    protected function fixture(): void
    {
        config(['deployhub.require_mfa' => false]);
        $this->withoutVite();
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::create(['name' => 'Fixture', 'slug' => 'fixture']);
        $this->workspace->users()->attach($this->owner, ['role' => 'owner', 'active' => true]);
        $server = Server::create(['workspace_id' => $this->workspace->id, 'name' => 'Fixture SSH', 'host' => 'fixture.test', 'port' => 22, 'username' => 'deploy', 'fingerprint' => 'SHA256:'.str_repeat('x', 43)]);
        $project = Project::create(['workspace_id' => $this->workspace->id, 'name' => 'Fixture app', 'repository' => 'git@example.test:team/app.git']);
        $this->environment = $project->environments()->create(['server_id' => $server->id, 'name' => 'Staging', 'path' => '/home/deploy/app', 'branch' => 'staging', 'pipeline' => ['health'], 'health_url' => 'https://fixture.test/up']);
        $this->grant($this->owner);
        $this->remote = new FakeRemote;
        $this->app->instance(ConnectionFactory::class, new FakeConnections($this->remote));
    }

    protected function member(string $role = 'developer', bool $grant = true): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user, ['role' => $role, 'active' => true]);
        if ($grant) {
            $this->grant($user, $role !== 'viewer');
        }

        return $user;
    }

    protected function grant(User $user, bool $deploy = true, bool $approve = true): void
    {
        $this->environment->project->users()->syncWithoutDetaching([$user->id]);
        $this->environment->server->users()->syncWithoutDetaching([$user->id]);
        EnvironmentPermission::updateOrCreate(['environment_id' => $this->environment->id, 'user_id' => $user->id], ['can_view' => true, 'can_deploy' => $deploy, 'can_approve' => $approve]);
    }

    protected function candidate(?User $user = null)
    {
        return app(CandidateService::class)->create($this->environment, $user ?? $this->owner, (string) Str::uuid());
    }
}
