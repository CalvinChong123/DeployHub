<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Models\Server;
use App\Services\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HubFixture;
use Tests\TestCase;

class RegistryTest extends TestCase
{
    use HubFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
    }

    private function data(): array
    {
        return ['name' => 'New app', 'repository' => 'git@example.test:team/new.git', 'description' => 'Fixture', 'environment_name' => 'Production', 'server_id' => $this->environment->server_id, 'path' => '/home/deploy/production', 'branch' => 'main', 'production' => true, 'requires_approval' => false, 'pipeline' => ['health', 'cache_clear'], 'health_url' => 'https://example.test/up'];
    }

    public function test_registry_forces_production_approval_and_normalizes_pipeline_order(): void
    {
        $this->actingAs($this->owner)->post('/projects', $this->data())->assertSessionHasNoErrors();
        $env = Environment::where('name', 'Production')->firstOrFail();
        $this->assertTrue($env->requires_approval);
        $this->assertSame(['cache_clear', 'health'], $env->pipeline);
        $this->assertTrue(app(Access::class)->environment($this->owner, $env, 'deploy'));
    }

    public function test_duplicate_remote_directory_and_shell_injection_are_rejected(): void
    {
        $data = $this->data();
        $data['path'] = $this->environment->path;
        $this->actingAs($this->owner)->post('/projects', $data)->assertSessionHasErrors('path');
        $data['path'] = '/home/deploy/$(whoami)';
        $this->post('/projects', $data)->assertSessionHas('error');
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_changing_server_peer_revokes_fingerprint_and_candidate_validity(): void
    {
        $d = $this->candidate();
        $server = $this->environment->server;
        $this->actingAs($this->owner)->put('/servers/'.$server->id, ['name' => $server->name, 'host' => 'other.example.test', 'port' => 22, 'username' => 'deploy'])->assertSessionHasNoErrors();
        $this->assertNull($server->fresh()->fingerprint);
        $this->assertSame('unknown', $this->environment->fresh()->status);
        $this->assertNull($this->environment->fresh()->last_preflight);
        $this->assertSame(2, $server->fresh()->credential_version);
        $this->post('/deployments/'.$d->id.'/confirm', ['digest' => $d->digest, 'reviewed' => true])->assertSessionHas('error');
        $this->assertSame('awaiting_confirmation', $d->fresh()->state);
    }

    public function test_deploy_history_prevents_project_deletion_and_registry_is_admin_only(): void
    {
        $d = $this->candidate();
        $this->actingAs($this->owner)->delete('/projects/'.$this->environment->project_id)->assertStatus(422);
        $this->actingAs($this->member())->put('/projects/'.$this->environment->project_id, ['name' => 'Changed'])->assertForbidden();
        $this->assertDatabaseHas('deployments', ['id' => $d->id]);
    }

    public function test_duplicate_names_are_validation_errors_for_create_and_rename(): void
    {
        $data = [...$this->data(), 'environment_name' => $this->environment->name];
        $this->actingAs($this->owner)->post('/projects/'.$this->environment->project_id.'/environments', $data)->assertSessionHasErrors('environment_name');
        $data['environment_name'] = 'Production';
        $this->post('/projects/'.$this->environment->project_id.'/environments', $data)->assertSessionHasNoErrors();
        $production = Environment::where('name', 'Production')->firstOrFail();
        $this->put('/environments/'.$production->id, [...$data, 'environment_name' => 'Staging'])->assertSessionHasErrors('environment_name');
        $this->assertSame('Production', $production->fresh()->name);
        $this->put('/environments/'.$production->id, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('environments', 2);
        $this->post('/projects', [...$data, 'path' => '/home/deploy/another-project'])->assertSessionHasNoErrors();
    }

    public function test_server_change_requires_explicit_access_transfer_and_preserves_member_rights(): void
    {
        $member = $this->member();
        $viewer = $this->member('viewer');
        $server = Server::create(['workspace_id' => $this->workspace->id, 'name' => 'Replacement', 'host' => 'other.test', 'port' => 22, 'username' => 'deploy']);
        $original = $this->environment->server_id;
        $data = [...$this->data(), 'environment_name' => 'Staging', 'server_id' => $server->id, 'path' => $this->environment->path];
        $this->actingAs($this->owner)->put('/environments/'.$this->environment->id, $data)->assertSessionHasErrors('transfer_server_access');
        $this->assertSame($original, $this->environment->fresh()->server_id);
        $this->assertSame(0, $server->users()->count());
        $this->put('/environments/'.$this->environment->id, [...$data, 'transfer_server_access' => true])->assertSessionHasNoErrors();
        $environment = $this->environment->fresh();
        $this->assertTrue(app(Access::class)->environment($member, $environment, 'deploy'));
        $this->assertTrue(app(Access::class)->environment($viewer, $environment));
        $this->assertFalse(app(Access::class)->environment($viewer, $environment, 'deploy'));
        $this->actingAs($member)->get('/environments/'.$environment->id)->assertOk();
        $this->assertDatabaseHas('server_user', ['server_id' => $original, 'user_id' => $member->id]);
    }
}
