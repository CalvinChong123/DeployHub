<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Deployment\ConnectionFactory;
use App\Services\Deployment\DeploymentException;
use App\Services\Deployment\HostKeyVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HubFixture;
use Tests\TestCase;

class HostTrustTest extends TestCase
{
    use HubFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
    }

    private function firstUse(): array
    {
        return ['fingerprint' => 'SHA256:'.str_repeat('x', 43), 'trust_method' => 'first_use', 'trust_first_use' => true];
    }

    public function test_first_use_requires_confirmation_and_discovery_and_records_its_trust_method(): void
    {
        $server = $this->environment->server;
        $server->update(['fingerprint' => null, 'error' => 'Previous authentication test failed.']);
        $url = '/servers/'.$server->id;
        $this->actingAs($this->owner)->post($url.'/verify', $this->firstUse())->assertSessionHas('error');
        $this->assertNull($server->fresh()->fingerprint);
        $this->post($url.'/discover')->assertSessionHas('fingerprint');
        $this->post($url.'/verify', [...$this->firstUse(), 'trust_first_use' => false])->assertSessionHasErrors('trust_first_use');
        $this->post($url.'/verify', $this->firstUse())->assertSessionHasNoErrors()->assertSessionHas('message')->assertSessionMissing('hostkey.'.$server->id);
        $this->assertSame($this->firstUse()['fingerprint'], $server->fresh()->fingerprint);
        $this->assertNull($server->fresh()->error);
        $this->assertSame('unknown', $this->environment->fresh()->status);
        $audit = AuditLog::where('action', 'server.hostkey_trusted_first_use')->firstOrFail();
        $this->assertSame('first_use', $audit->metadata['trust_method']);
        $this->assertSame($this->owner->id, $audit->user_id);
        $this->assertNull($audit->metadata['previous_fingerprint']);

        app(HostKeyVerifier::class)->verify($server->fresh()->fingerprint, $this->firstUse()['fingerprint']);
        $this->expectException(DeploymentException::class);
        app(HostKeyVerifier::class)->verify($server->fresh()->fingerprint, 'SHA256:changed');
    }

    public function test_first_use_cannot_replace_a_saved_fingerprint_but_independent_verification_can(): void
    {
        $server = $this->environment->server;
        $original = 'SHA256:'.str_repeat('y', 43);
        $server->update(['fingerprint' => $original]);
        $url = '/servers/'.$server->id;
        $this->actingAs($this->owner)->post($url.'/discover');
        $this->post($url.'/verify', $this->firstUse())->assertSessionHasErrors('trust_method');
        $this->assertSame($original, $server->fresh()->fingerprint);
        $this->post($url.'/verify', ['fingerprint' => $this->firstUse()['fingerprint'], 'trust_method' => 'independent'])->assertSessionHasErrors('verified_out_of_band');
        $this->post($url.'/verify', ['fingerprint' => $this->firstUse()['fingerprint'], 'trust_method' => 'independent', 'verified_out_of_band' => true])->assertSessionHasNoErrors();
        $audit = AuditLog::where('action', 'server.hostkey_verified')->firstOrFail();
        $this->assertSame('independent', $audit->metadata['trust_method']);
        $this->assertSame($original, $audit->metadata['previous_fingerprint']);
    }

    public function test_expired_discovery_or_changed_server_configuration_cannot_be_trusted(): void
    {
        $server = $this->environment->server;
        $server->update(['fingerprint' => null]);
        $url = '/servers/'.$server->id;
        $this->actingAs($this->owner)->post($url.'/discover');
        $this->travel(11)->minutes();
        $this->post($url.'/verify', $this->firstUse())->assertSessionHas('error');
        $this->travelBack();
        $this->post($url.'/discover');
        $server->update(['host' => 'changed.example.test']);
        $this->post($url.'/verify', $this->firstUse())->assertSessionHas('error');
        $this->assertNull($server->fresh()->fingerprint);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'server.hostkey_trusted_first_use']);
    }

    public function test_a_host_key_change_between_discovery_and_confirmation_is_rejected(): void
    {
        $server = $this->environment->server;
        $server->update(['fingerprint' => null]);
        $connections = $this->createMock(ConnectionFactory::class);
        $connections->expects($this->exactly(2))->method('discover')->willReturnOnConsecutiveCalls($this->firstUse()['fingerprint'], 'SHA256:changed');
        $this->app->instance(ConnectionFactory::class, $connections);
        $this->actingAs($this->owner)->post('/servers/'.$server->id.'/discover');
        $this->post('/servers/'.$server->id.'/verify', $this->firstUse())->assertSessionHas('error');
        $this->assertNull($server->fresh()->fingerprint);
    }

    public function test_a_concurrently_saved_fingerprint_cannot_be_overwritten_by_first_use(): void
    {
        $server = $this->environment->server;
        $server->update(['fingerprint' => null]);
        $calls = 0;
        $connections = $this->createMock(ConnectionFactory::class);
        $connections->method('discover')->willReturnCallback(function () use ($server, &$calls) {
            if (++$calls === 2) {
                $server->update(['fingerprint' => 'SHA256:concurrently-saved']);
            }

            return $this->firstUse()['fingerprint'];
        });
        $this->app->instance(ConnectionFactory::class, $connections);
        $this->actingAs($this->owner)->post('/servers/'.$server->id.'/discover');
        $this->post('/servers/'.$server->id.'/verify', $this->firstUse())->assertSessionHas('error');
        $this->assertSame('SHA256:concurrently-saved', $server->fresh()->fingerprint);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'server.hostkey_trusted_first_use']);
    }

    public function test_first_use_is_admin_workspace_and_mfa_scoped(): void
    {
        $server = $this->environment->server;
        $server->update(['fingerprint' => null]);
        $url = '/servers/'.$server->id;
        $this->actingAs($this->member())->post($url.'/verify', $this->firstUse())->assertForbidden();

        $outsider = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Other', 'slug' => 'other']);
        $workspace->users()->attach($outsider, ['role' => 'owner', 'active' => true]);
        $this->actingAs($outsider)->post($url.'/verify', $this->firstUse())->assertNotFound();

        config(['deployhub.require_mfa' => true]);
        $this->actingAs($this->owner)->post($url.'/verify', $this->firstUse())->assertRedirect('/security');
        $this->assertNull($server->fresh()->fingerprint);
    }
}
