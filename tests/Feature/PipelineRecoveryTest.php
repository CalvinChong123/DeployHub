<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\EnvironmentPermission;
use App\Services\Deployment\CandidateService;
use App\Services\Deployment\DeploymentException;
use App\Services\Deployment\DeploymentExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\HubFixture;
use Tests\TestCase;

class PipelineRecoveryTest extends TestCase
{
    use HubFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        Queue::fake();
    }

    private function failedDeployment(): Deployment
    {
        $deployment = $this->candidate();
        $deployment->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        $this->remote->failHealth = true;
        app(DeploymentExecutor::class)->execute($deployment->id);
        $this->assertSame('failed', $deployment->fresh()->state);
        $this->assertTrue($deployment->fresh()->code_updated);
        $this->remote->failHealth = false;

        return $deployment->fresh();
    }

    public function test_failed_pipeline_recovers_same_sha_with_fresh_review_and_no_git_merge(): void
    {
        $source = $this->failedDeployment();
        $key = (string) Str::uuid();
        $response = $this->actingAs($this->owner)->post('/deployments/'.$source->id.'/recover', ['idempotency_key' => $key]);
        $recovery = Deployment::where('idempotency_key', $key)->firstOrFail();
        $response->assertRedirect('/deployments/'.$recovery->id);
        $this->assertSame('awaiting_confirmation', $recovery->state);
        $this->assertSame($source->target_sha, $recovery->starting_sha);
        $this->assertSame($source->target_sha, $recovery->target_sha);
        $this->assertSame($source->id, $recovery->candidate['recovery_of']);
        $this->assertSame(0, $recovery->preflight['count']);
        $this->post('/deployments/'.$source->id.'/recover', ['idempotency_key' => $key])->assertRedirect('/deployments/'.$recovery->id);
        $this->assertDatabaseCount('deployments', 2);
        Queue::assertNothingPushed();
        $this->post('/deployments/'.$recovery->id.'/confirm', ['digest' => $recovery->digest, 'reviewed' => true])->assertSessionMissing('error');
        $this->remote->commands = [];
        app(DeploymentExecutor::class)->execute($recovery->id);
        $this->assertSame('succeeded', $recovery->fresh()->state);
        $this->assertSame(['preflight', 'health', 'verify_head'], $recovery->steps()->pluck('type')->all());
        $this->assertStringNotContainsString("'merge'", implode('\n', $this->remote->commands));
        $this->assertSame('failed', $source->fresh()->state);
        $this->assertNull($recovery->fresh()->active_environment_id);
    }

    public function test_production_recovery_requires_new_independent_approval(): void
    {
        $this->environment->update(['production' => true, 'requires_approval' => true]);
        $source = $this->candidate();
        $approver = $this->member();
        $source->approval()->create(['user_id' => $approver->id, 'digest' => $source->digest, 'outcome' => 'approved', 'expires_at' => $source->expires_at]);
        $source->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        $this->remote->failHealth = true;
        app(DeploymentExecutor::class)->execute($source->id);
        $this->assertSame('failed', $source->fresh()->state);
        $this->remote->failHealth = false;
        $source->refresh();
        $recovery = app(CandidateService::class)->recover($source, $this->owner, (string) Str::uuid());
        $this->actingAs($this->owner)->post('/deployments/'.$recovery->id.'/confirm', ['digest' => $recovery->digest, 'reviewed' => true])->assertSessionMissing('error');
        $this->assertSame('awaiting_approval', $recovery->fresh()->state);
        Queue::assertNothingPushed();
        $this->post('/deployments/'.$recovery->id.'/approve', ['digest' => $recovery->digest, 'reviewed' => true])->assertForbidden();
        $this->assertNull($recovery->approval);
        $this->actingAs($approver)->post('/deployments/'.$recovery->id.'/approve', ['digest' => $recovery->digest, 'reviewed' => true])->assertSessionMissing('error');
        app(DeploymentExecutor::class)->execute($recovery->id);
        $this->assertSame('succeeded', $recovery->fresh()->state);
        $this->assertDatabaseCount('deployment_approvals', 2);
    }

    public function test_recovery_rechecks_permission_and_mfa(): void
    {
        $source = $this->failedDeployment();
        $viewer = $this->member('viewer');
        $this->actingAs($viewer)->post('/deployments/'.$source->id.'/recover', ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
        config(['deployhub.require_mfa' => true]);
        $this->actingAs($this->owner)->post('/deployments/'.$source->id.'/recover', ['idempotency_key' => (string) Str::uuid()])->assertRedirect('/security');
        $this->assertDatabaseCount('deployments', 1);
    }

    public function test_unknown_or_unmodified_failed_deployment_cannot_recover(): void
    {
        $source = $this->candidate();
        foreach ([['state' => 'unknown', 'code_updated' => true], ['state' => 'failed', 'code_updated' => false], ['state' => 'succeeded', 'code_updated' => true]] as $state) {
            $source->update($state);
            $this->actingAs($this->owner)->post('/deployments/'.$source->id.'/recover', ['idempotency_key' => (string) Str::uuid()])->assertSessionHas('error');
        }
        $this->assertDatabaseCount('deployments', 1);
    }

    public function test_recovery_blocks_changed_origin_or_head(): void
    {
        $source = $this->failedDeployment();
        foreach (['target' => str_repeat('c', 40), 'head' => str_repeat('d', 40)] as $property => $value) {
            $original = $this->remote->$property;
            $this->remote->$property = $value;
            $this->actingAs($this->owner)->post('/deployments/'.$source->id.'/recover', ['idempotency_key' => (string) Str::uuid()])->assertSessionHas('error');
            $this->remote->$property = $original;
        }
        $this->assertDatabaseCount('deployments', 1);
    }

    public function test_permission_or_configuration_changes_after_review_block_recovery_worker(): void
    {
        $source = $this->failedDeployment();
        $member = $this->member();
        $recovery = app(CandidateService::class)->recover($source, $member, (string) Str::uuid());
        $recovery->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        EnvironmentPermission::where('user_id', $member->id)->update(['can_deploy' => false]);
        $this->remote->commands = [];
        app(DeploymentExecutor::class)->execute($recovery->id);
        $this->assertSame('failed', $recovery->fresh()->state);
        $this->assertCount(0, $this->remote->commands);
        $next = app(CandidateService::class)->recover($source, $this->owner, (string) Str::uuid());
        $next->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        $this->environment->increment('pipeline_version');
        $this->remote->commands = [];
        app(DeploymentExecutor::class)->execute($next->id);
        $this->assertSame('failed', $next->fresh()->state);
        $this->assertCount(0, $this->remote->commands);
    }

    public function test_failed_recovery_can_prepare_another_fresh_recovery(): void
    {
        $source = $this->failedDeployment();
        $recovery = app(CandidateService::class)->recover($source, $this->owner, (string) Str::uuid());
        $recovery->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        $this->remote->failHealth = true;
        app(DeploymentExecutor::class)->execute($recovery->id);
        $this->assertTrue($recovery->fresh()->code_updated);
        $this->remote->failHealth = false;
        $next = app(CandidateService::class)->recover($recovery->fresh(), $this->owner, (string) Str::uuid());
        $this->assertSame($recovery->id, $next->candidate['recovery_of']);
    }

    public function test_normal_up_to_date_preview_still_cannot_run_pipeline(): void
    {
        $this->remote->head = $this->remote->target;
        $this->expectException(DeploymentException::class);
        $this->expectExceptionMessage('Already up to date');
        $this->candidate();
    }
}
