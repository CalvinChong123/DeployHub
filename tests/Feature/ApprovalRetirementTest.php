<?php

namespace Tests\Feature;

use App\Jobs\ExecuteDeployment;
use App\Models\Deployment;
use App\Models\EnvironmentPermission;
use App\Services\Access;
use App\Services\Deployment\CandidateService;
use App\Services\Deployment\DeploymentExecutor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\HubFixture;
use Tests\TestCase;

class ApprovalRetirementTest extends TestCase
{
    use HubFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        Queue::fake();
        $this->environment->update(['production' => true, 'requires_approval' => true]);
    }

    private function pending(): Deployment
    {
        $deployment = $this->candidate();
        $deployment->update(['state' => 'awaiting_approval', 'active_environment_id' => $this->environment->id]);

        return $deployment;
    }

    private function confirm(Deployment $deployment)
    {
        return $this->actingAs($this->owner)->post('/deployments/'.$deployment->id.'/confirm', ['reviewed' => true, 'digest' => $deployment->digest]);
    }

    public function test_legacy_pending_request_requires_explicit_requester_confirmation_and_queues_once(): void
    {
        $deployment = $this->pending();
        $original = $deployment->only(['candidate', 'digest', 'expires_at', 'starting_sha', 'target_sha']);
        $this->remote->commands = [];
        app(DeploymentExecutor::class)->execute($deployment->id);
        $this->actingAs($this->owner)->get('/deployments/'.$deployment->id)->assertInertia(fn (Assert $page) => $page
            ->where('canConfirm', true)->missing('canApprove')->where('deployment.state', 'awaiting_approval'));
        $this->assertCount(0, $this->remote->commands);
        Queue::assertNothingPushed();

        $this->confirm($deployment)->assertSessionMissing('error');
        $this->confirm($deployment)->assertSessionMissing('error');
        $this->assertSame('queued', $deployment->fresh()->state);
        $this->assertEquals($original, $deployment->fresh()->only(array_keys($original)));
        Queue::assertPushed(ExecuteDeployment::class, 1);
        app(DeploymentExecutor::class)->execute($deployment->id);
        $this->assertSame('succeeded', $deployment->fresh()->state);
        $this->assertDatabaseCount('deployment_approvals', 0);
    }

    public function test_legacy_reconfirmation_enforces_requester_review_digest_expiry_and_deploy_grants(): void
    {
        $deployment = $this->pending();
        $data = ['reviewed' => true, 'digest' => $deployment->digest];
        $this->actingAs($this->member())->post('/deployments/'.$deployment->id.'/confirm', $data)->assertForbidden();
        $this->actingAs($this->owner)->post('/deployments/'.$deployment->id.'/confirm', [...$data, 'reviewed' => false])->assertSessionHasErrors('reviewed');
        $this->post('/deployments/'.$deployment->id.'/confirm', [...$data, 'digest' => str_repeat('0', 64)])->assertSessionHas('error');
        EnvironmentPermission::where('user_id', $this->owner->id)->update(['can_deploy' => false]);
        $this->confirm($deployment)->assertForbidden();
        EnvironmentPermission::where('user_id', $this->owner->id)->update(['can_deploy' => true]);
        $this->travel(61)->minutes();
        $this->confirm($deployment)->assertSessionHas('error');
        $this->assertSame('awaiting_approval', $deployment->fresh()->state);
        Queue::assertNothingPushed();
    }

    public function test_legacy_reconfirmation_rechecks_configuration_and_mfa(): void
    {
        $deployment = $this->pending();
        $this->environment->increment('pipeline_version');
        $this->confirm($deployment)->assertSessionHas('error');
        $this->environment->decrement('pipeline_version');
        config(['deployhub.require_mfa' => true]);
        $this->confirm($deployment)->assertRedirect('/security');
        Queue::assertNothingPushed();
    }

    public function test_legacy_schedule_requires_reconfirmation_and_never_reserves_early(): void
    {
        $deployment = app(CandidateService::class)->create($this->environment, $this->owner, (string) Str::uuid(), CarbonImmutable::now()->addDay(), 'Asia/Kuala_Lumpur');
        $deployment->update(['state' => 'awaiting_approval']);
        $this->confirm($deployment)->assertSessionMissing('error');
        $this->assertSame('scheduled', $deployment->fresh()->state);
        $this->assertNull($deployment->fresh()->active_environment_id);
        Queue::assertNothingPushed();
    }

    public function test_legacy_reconfirmation_rolls_back_on_queue_failure(): void
    {
        $deployment = $this->pending();
        Bus::partialMock()->shouldReceive('dispatch')->andThrow(new \RuntimeException('Queue unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->confirm($deployment);
            $this->fail('Expected queue failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Queue unavailable', $error->getMessage());
        }
        $this->assertSame('awaiting_approval', $deployment->fresh()->state);
        $this->assertSame($this->environment->id, $deployment->fresh()->active_environment_id);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'deployment.confirmed']);
    }

    public function test_retired_endpoints_and_permission_fields_cannot_approve_or_enqueue(): void
    {
        $deployment = $this->pending();
        $this->actingAs($this->owner)->get('/approvals')->assertRedirect('/activity?state=awaiting_approval');
        foreach (['approve', 'reject'] as $action) {
            $this->post('/deployments/'.$deployment->id.'/'.$action, ['reviewed' => true, 'digest' => $deployment->digest, 'reason' => 'Legacy action'])->assertNotFound();
        }
        $member = $this->member('developer', false);
        $this->post('/environments/'.$this->environment->id.'/permissions', ['user_id' => $member->id, 'can_view' => true, 'can_deploy' => true, 'can_approve' => true])->assertSessionHasNoErrors();
        $permission = EnvironmentPermission::where('user_id', $member->id)->firstOrFail();
        $this->assertTrue($permission->can_deploy);
        $this->assertFalse($permission->can_approve);
        $this->assertFalse(app(Access::class)->environment($member, $this->environment, 'approve'));
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('deployment_approvals', 0);
    }
}
