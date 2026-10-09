<?php

namespace Tests\Feature;

use App\Models\EnvironmentPermission;
use App\Models\ServerCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use phpseclib3\Crypt\EC;
use Tests\Support\HubFixture;
use Tests\TestCase;

class DeployHubAccessTest extends TestCase
{
    use HubFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
    }

    public function test_dashboard_and_environment_include_safe_server_metadata_for_rendering(): void
    {
        $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn (AssertableInertia $p) => $p->where('projects.0.environments.0.server.name', 'Fixture SSH')->missing('projects.0.environments.0.server.host'));
        $this->get('/environments/'.$this->environment->id)->assertInertia(fn (AssertableInertia $p) => $p->where('environment.server.name', 'Fixture SSH')->missing('environment.server.host'));
    }

    public function test_unassigned_member_cannot_read_environment_or_logs(): void
    {
        $d = $this->candidate();
        $user = $this->member('developer', false);
        $this->actingAs($user)->get('/environments/'.$this->environment->id)->assertForbidden();
        $this->get('/deployments/'.$d->id.'/events')->assertForbidden();
        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $p) => $p->has('projects', 0));
    }

    public function test_viewer_can_observe_but_not_deploy_or_manage_servers(): void
    {
        $user = $this->member('viewer');
        $d = $this->candidate();
        $this->actingAs($user)->get('/environments/'.$this->environment->id)->assertOk();
        $this->get('/deployments/'.$d->id.'/events')->assertOk();
        $this->get('/deployments/'.$d->id.'/events')->assertJsonMissingPath('candidate.host')->assertJsonMissingPath('candidate.username');
        $this->post('/environments/'.$this->environment->id.'/deployment-requests', ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->get('/servers')->assertForbidden();
        $this->get('/team')->assertForbidden();
    }

    public function test_workspace_role_alone_does_not_authorize_execution(): void
    {
        EnvironmentPermission::where('user_id', $this->owner->id)->delete();
        $this->actingAs($this->owner)->post('/environments/'.$this->environment->id.'/deployment-requests')->assertForbidden();
    }

    public function test_server_access_is_independently_required(): void
    {
        $user = $this->member();
        $this->environment->server->users()->detach($user);
        $this->actingAs($user)->get('/environments/'.$this->environment->id)->assertForbidden();
    }

    public function test_foreign_workspace_ids_do_not_bypass_authorization(): void
    {
        $outsider = User::factory()->withWorkspace()->create();
        $this->actingAs($outsider)->get('/environments/'.$this->environment->id)->assertForbidden();
        $this->post('/servers/'.$this->environment->server_id.'/discover')->assertNotFound();
    }

    public function test_revocation_blocks_existing_sessions(): void
    {
        $user = $this->member();
        $this->workspace->users()->updateExistingPivot($user->id, ['active' => false]);
        $this->actingAs($user)->get('/dashboard')->assertForbidden();
    }

    public function test_mfa_enrollment_is_required_for_privileged_actions(): void
    {
        config(['deployhub.require_mfa' => true]);
        $this->actingAs($this->owner)->get('/servers')->assertRedirect('/security');
        $this->get('/dashboard')->assertOk();
    }

    public function test_password_only_session_cannot_bypass_mfa_for_profile_or_logs(): void
    {
        $d = $this->candidate();
        $this->owner->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($this->owner)->get('/deployments/'.$d->id.'/events')->assertRedirect('/two-factor-challenge');
        $this->put('/password', ['current_password' => 'password', 'password' => 'attacker-password', 'password_confirmation' => 'attacker-password'])->assertRedirect('/two-factor-challenge');
    }

    public function test_credential_values_are_encrypted_and_never_serialized_or_flashed(): void
    {
        $key = EC::createKey('Ed25519')->toString('OpenSSH');
        $this->actingAs($this->owner)->post('/servers', ['name' => 'Vault fixture', 'host' => 'fixture.test', 'port' => 22, 'username' => 'deploy', 'private_key' => $key, 'passphrase' => null])->assertSessionHasNoErrors();
        $credential = ServerCredential::firstOrFail();
        $this->assertNotSame($key, $credential->getRawOriginal('private_key'));
        $this->assertSame($key, $credential->private_key);
        $this->get('/servers')->assertDontSee($key, false)->assertDontSee('BEGIN OPENSSH PRIVATE KEY', false);
        $this->post('/servers', ['private_key' => 'sensitive-invalid-key', 'passphrase' => 'sensitive-passphrase'])->assertSessionMissing('_old_input.private_key')->assertSessionMissing('_old_input.passphrase');
    }

    public function test_hostkey_verification_requires_discovery_and_out_of_band_attestation(): void
    {
        $id = $this->environment->server_id;
        $this->actingAs($this->owner)->post('/servers/'.$id.'/verify', ['fingerprint' => 'SHA256:'.str_repeat('x', 43), 'verified_out_of_band' => true])->assertSessionHas('error');
        $this->post('/servers/'.$id.'/discover')->assertSessionHas('fingerprint');
        $this->post('/servers/'.$id.'/verify', ['fingerprint' => 'SHA256:'.str_repeat('x', 43), 'verified_out_of_band' => true])->assertSessionHas('message');
        $this->assertDatabaseHas('audit_logs', ['action' => 'server.hostkey_verified']);
    }
}
