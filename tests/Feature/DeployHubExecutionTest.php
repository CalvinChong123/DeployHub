<?php

namespace Tests\Feature;

use App\Jobs\ExecuteDeployment;
use App\Models\Deployment;
use App\Models\EnvironmentPermission;
use App\Services\Deployment\CandidateService;
use App\Services\Deployment\DeploymentException;
use App\Services\Deployment\DeploymentExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\HubFixture;
use Tests\TestCase;

class DeployHubExecutionTest extends TestCase
{
    use HubFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        Queue::fake();
    }

    private function queue(Deployment $d): void
    {
        $this->actingAs($d->user)->post('/deployments/'.$d->id.'/confirm', ['reviewed' => true, 'digest' => $d->digest])->assertSessionMissing('error');
    }

    public function test_failed_duplicate_job_cannot_quarantine_another_workers_execution(): void
    {
        $d = $this->candidate();
        $first = new ExecuteDeployment($d->id);
        $duplicate = new ExecuteDeployment($d->id);
        $d->update(['state' => 'running', 'active_environment_id' => $this->environment->id, 'execution_token' => $first->executionToken]);
        $duplicate->failed(new \RuntimeException('Lock busy'));
        $this->assertSame('running', $d->fresh()->state);
        $first->failed(new \RuntimeException('Worker crashed'));
        $this->assertSame('unknown', $d->fresh()->state);
        $this->assertNotNull($d->fresh()->active_environment_id);
    }

    public function test_candidate_fields_are_immutable_and_bind_the_requester(): void
    {
        $d = $this->candidate();
        $other = $this->member();
        $this->queue($d);
        DB::table('deployments')->where('id', $d->id)->update(['user_id' => $other->id]);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertSame($d->starting_sha, $this->remote->head);
        $this->expectException(\LogicException::class);
        $d->fresh()->update(['target_sha' => str_repeat('c', 40)]);
    }

    public function test_enqueue_failure_rolls_back_candidate_admission(): void
    {
        $d = $this->candidate();
        Bus::partialMock()->shouldReceive('dispatch')->andThrow(new \RuntimeException('Queue unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->queue($d);
            $this->fail('Enqueue failure was not reported.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Queue unavailable', $e->getMessage());
        }
        $this->assertSame('awaiting_confirmation', $d->fresh()->state);
        $this->assertNull($d->fresh()->active_environment_id);
    }

    public function test_credential_rotation_and_expired_approval_block_execution(): void
    {
        $d = $this->candidate();
        $this->queue($d);
        $this->environment->server->increment('credential_version');
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->environment->update(['production' => true]);
        $next = $this->candidate();
        $this->queue($next);
        $approver = $this->member();
        $this->actingAs($approver)->post('/deployments/'.$next->id.'/approve', ['digest' => $next->digest, 'reviewed' => true]);
        $next->approval()->update(['expires_at' => now()->subSecond()]);
        app(DeploymentExecutor::class)->execute($next->id);
        $this->assertSame('failed', $next->fresh()->state);
        $this->assertSame($next->starting_sha, $this->remote->head);
    }

    public function test_confirm_requires_explicit_review_and_digest(): void
    {
        $d = $this->candidate();
        $this->actingAs($this->owner)->post('/deployments/'.$d->id.'/confirm')->assertSessionHasErrors(['reviewed', 'digest']);
        $this->assertSame('awaiting_confirmation', $d->fresh()->state);
        Queue::assertNothingPushed();
    }

    public function test_staging_executes_exact_sha_and_records_mandatory_steps(): void
    {
        $d = $this->candidate();
        $this->queue($d);
        Queue::assertPushed(ExecuteDeployment::class, 1);
        app(DeploymentExecutor::class)->execute($d->id);
        $d->refresh();
        $this->assertSame('succeeded', $d->state);
        $this->assertSame($d->target_sha, $this->remote->head);
        $this->assertSame(['preflight', 'fast_forward', 'health', 'verify_head'], $d->steps()->pluck('type')->all());
        $this->assertNull($d->active_environment_id);
        $this->assertTrue($d->code_updated);
    }

    public function test_duplicate_clicks_and_worker_replays_execute_once(): void
    {
        $key = (string) Str::uuid();
        $svc = app(CandidateService::class);
        $first = $svc->create($this->environment, $this->owner, $key);
        $second = $svc->create($this->environment, $this->owner, $key);
        $this->assertSame($first->id, $second->id);
        $this->queue($first);
        $this->queue($first);
        Queue::assertPushed(ExecuteDeployment::class, 1);
        app(DeploymentExecutor::class)->execute($first->id);
        app(DeploymentExecutor::class)->execute($first->id);
        $this->assertSame(1, count(array_filter($this->remote->commands, fn ($c) => str_contains($c, "'merge'"))));
    }

    public function test_competing_candidates_cannot_reserve_same_environment(): void
    {
        $first = $this->candidate();
        $second = $this->candidate($this->member());
        $this->queue($first);
        $this->actingAs($second->user)->post('/deployments/'.$second->id.'/confirm', ['reviewed' => true, 'digest' => $second->digest])->assertSessionHas('error');
        $this->assertSame('awaiting_confirmation', $second->fresh()->state);
        Queue::assertPushed(ExecuteDeployment::class, 1);
    }

    public function test_live_environment_lock_prevents_repository_inspection(): void
    {
        $lock = Cache::lock('environment:'.$this->environment->id, 1200);
        $lock->get();
        try {
            $this->actingAs($this->owner)->post('/environments/'.$this->environment->id.'/preflight')->assertSessionHas('error');
            $this->assertCount(0, $this->remote->commands);
        } finally {
            $lock->release();
        }
    }

    public function test_remote_advance_after_preview_blocks_without_merging(): void
    {
        $d = $this->candidate();
        $this->queue($d);
        $this->remote->target = str_repeat('c', 40);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertFalse($d->fresh()->code_updated);
        $this->assertSame($d->starting_sha, $this->remote->head);
    }

    public function test_base_head_changed_after_preview_blocks_execution(): void
    {
        $d = $this->candidate();
        $this->queue($d);
        $this->remote->head = str_repeat('d', 40);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertFalse($d->fresh()->code_updated);
    }

    public function test_unrelated_dirty_worktree_is_preserved_without_reset_or_cleanup(): void
    {
        $d = $this->candidate();
        $this->queue($d);
        $this->remote->dirty = true;
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('succeeded', $d->fresh()->state);
        $this->assertTrue($this->remote->dirty);
        $commands = implode('\n', $this->remote->commands);
        $this->assertStringNotContainsString("'reset'", $commands);
        $this->assertStringNotContainsString("'clean'", $commands);
        $this->assertStringContainsString("'merge'", $commands);
    }

    public function test_detached_branch_mismatch_divergence_and_wrong_origin_block_preview(): void
    {
        foreach (['detached' => true, 'branch' => 'main', 'diverged' => true, 'origin' => 'git@example.test:other/repo.git'] as $field => $value) {
            $original = $this->remote->$field;
            $this->remote->$field = $value;
            try {
                $this->candidate();
                $this->fail('Unsafe preview was accepted.');
            } catch (DeploymentException) {
                $this->assertSame('blocked', $this->environment->fresh()->status);
            } finally {
                $this->remote->$field = $original;
            }
        }
    }

    public function test_revocation_during_queue_blocks_execution(): void
    {
        $user = $this->member();
        $d = $this->candidate($user);
        $this->queue($d);
        EnvironmentPermission::where('user_id', $user->id)->update(['can_deploy' => false]);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertSame($d->starting_sha, $this->remote->head);
    }

    public function test_pipeline_or_credential_rotation_invalidates_candidate(): void
    {
        $d = $this->candidate();
        $this->queue($d);
        $this->environment->increment('pipeline_version');
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertStringContainsString('changed', $d->fresh()->error);
    }

    public function test_failed_health_check_is_partial_failure_and_output_is_redacted(): void
    {
        $d = $this->candidate();
        $this->queue($d);
        $this->remote->failHealth = true;
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertTrue($d->fresh()->code_updated);
        $this->assertSame($d->target_sha, $this->remote->head);
        $this->assertStringNotContainsString('base64:sensitive', $d->steps()->where('type', 'health')->first()->output);
        $this->assertNull($d->fresh()->active_environment_id);
    }

    public function test_http_redirect_cannot_be_reported_as_healthy(): void
    {
        $d = $this->candidate();
        $this->queue($d);
        $this->remote->healthStatus = '301';
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertSame('failed', $d->steps()->where('type', 'health')->first()->state);
        $this->assertTrue($d->fresh()->code_updated);
    }

    public function test_lost_ssh_connection_quarantines_environment(): void
    {
        $d = $this->candidate();
        $this->queue($d);
        $this->remote->loseConnection = true;
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('unknown', $d->fresh()->state);
        $this->assertSame($this->environment->id, $d->fresh()->active_environment_id);
        $this->assertSame('unknown', $d->steps()->where('type', 'fast_forward')->first()->state);
    }

    public function test_production_requires_independent_exact_candidate_approval(): void
    {
        $this->environment->update(['production' => true, 'requires_approval' => true]);
        $d = $this->candidate();
        $this->queue($d);
        $this->assertSame('awaiting_approval', $d->fresh()->state);
        Queue::assertNothingPushed();
        $this->actingAs($this->owner)->post('/deployments/'.$d->id.'/approve', ['digest' => $d->digest, 'reviewed' => true])->assertForbidden();
        $approver = $this->member();
        $this->actingAs($approver)->post('/deployments/'.$d->id.'/approve', ['digest' => str_repeat('0', 64), 'reviewed' => true])->assertSessionHas('error');
        $this->post('/deployments/'.$d->id.'/approve', ['digest' => $d->digest, 'reviewed' => true])->assertSessionMissing('error');
        Queue::assertPushed(ExecuteDeployment::class, 1);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('succeeded', $d->fresh()->state);
    }

    public function test_directly_queued_production_job_without_approval_cannot_execute(): void
    {
        $this->environment->update(['production' => true]);
        $d = $this->candidate();
        $d->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertSame($d->starting_sha, $this->remote->head);
    }

    public function test_approver_revocation_after_approval_stops_job(): void
    {
        $this->environment->update(['production' => true]);
        $d = $this->candidate();
        $this->queue($d);
        $approver = $this->member();
        $this->actingAs($approver)->post('/deployments/'.$d->id.'/approve', ['digest' => $d->digest, 'reviewed' => true]);
        $this->workspace->users()->updateExistingPivot($approver->id, ['active' => false]);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertSame($d->starting_sha, $this->remote->head);
    }

    public function test_rejected_or_expired_candidate_never_runs(): void
    {
        $this->environment->update(['production' => true]);
        $d = $this->candidate();
        $this->queue($d);
        $this->actingAs($this->member())->post('/deployments/'.$d->id.'/reject', ['reason' => 'Needs review']);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('rejected', $d->fresh()->state);
        $this->assertNull($d->fresh()->active_environment_id);
        $next = $this->candidate();
        $this->queue($next);
        $this->travel(61)->minutes();
        $this->artisan('deployhub:reconcile')->assertSuccessful();
        app(DeploymentExecutor::class)->execute($next->id);
        $this->assertSame('expired', $next->fresh()->state);
        Queue::assertNothingPushed();
    }

    public function test_worker_crash_becomes_unknown_and_keeps_reservation(): void
    {
        $d = $this->candidate();
        $d->update(['state' => 'running', 'active_environment_id' => $this->environment->id, 'heartbeat_at' => now()->subMinutes(25)]);
        $this->artisan('deployhub:reconcile')->assertSuccessful();
        $this->assertSame('unknown', $d->fresh()->state);
        $this->assertNotNull($d->fresh()->active_environment_id);
    }

    public function test_queued_cancellation_prevents_worker_execution(): void
    {
        $d = $this->candidate();
        $this->queue($d);
        $this->actingAs($this->owner)->post('/deployments/'.$d->id.'/cancel');
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('cancelled', $d->fresh()->state);
        $this->assertSame($d->starting_sha, $this->remote->head);
    }

    public function test_unknown_reconciliation_is_admin_only_and_requires_attestation(): void
    {
        $d = $this->candidate();
        $d->update(['state' => 'unknown', 'active_environment_id' => $this->environment->id]);
        $this->actingAs($this->member())->post('/deployments/'.$d->id.'/reconcile', ['reason' => 'Checked', 'remote_processes_stopped' => true])->assertForbidden();
        $this->actingAs($this->owner)->post('/deployments/'.$d->id.'/reconcile', ['reason' => 'Checked'])->assertSessionHasErrors('remote_processes_stopped');
        $this->post('/deployments/'.$d->id.'/reconcile', ['reason' => 'Verified remote stopped', 'remote_processes_stopped' => true])->assertSessionMissing('error');
        $this->assertSame('reconciled', $d->fresh()->state);
        $this->assertNull($d->fresh()->active_environment_id);
    }
}
