<?php

namespace Tests\Feature;

use App\Jobs\ExecuteDeployment;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentExecutor;
use App\Services\Deployment\DeploymentScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\HubFixture;
use Tests\TestCase;

class ScheduledDeploymentTest extends TestCase
{
    use HubFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        Queue::fake();
    }

    private function scheduled(?string $time = null): Deployment
    {
        $key = (string) Str::uuid();
        $this->actingAs($this->owner)->post('/environments/'.$this->environment->id.'/deployment-requests',
            ['idempotency_key' => $key, 'scheduled_for' => $time ?? CarbonImmutable::now()->addDays(2)->toIso8601String(), 'schedule_timezone' => 'Asia/Kuala_Lumpur'])->assertSessionHasNoErrors()->assertSessionMissing('error');

        return Deployment::where('idempotency_key', $key)->firstOrFail();
    }

    private function confirm(Deployment $deployment): void
    {
        $this->actingAs($this->owner)->post('/deployments/'.$deployment->id.'/confirm', ['digest' => $deployment->digest, 'reviewed' => true])->assertSessionMissing('error');
    }

    private function due(Deployment $deployment): array
    {
        $this->travelTo($deployment->scheduled_for->copy()->addSecond());

        return app(DeploymentScheduler::class)->dispatchDue();
    }

    public function test_timezone_and_time_are_reviewed_and_confirmed_without_reserving_the_target(): void
    {
        $time = CarbonImmutable::now()->addDays(2)->setTimezone('Asia/Kuala_Lumpur')->startOfMinute();
        $d = $this->scheduled($time->toIso8601String());
        $this->assertSame($time->utc()->toIso8601String(), $d->scheduled_for->toIso8601String());
        $this->assertSame($d->scheduled_for->toIso8601String(), $d->candidate['scheduled_for']);
        $this->assertTrue($d->expires_at->equalTo($d->scheduled_for->copy()->addHour()));
        $this->confirm($d);
        $this->assertSame('scheduled', $d->fresh()->state);
        $this->assertNull($d->fresh()->active_environment_id);
        Queue::assertNothingPushed();
        $this->assertSame(0, app(DeploymentScheduler::class)->dispatchDue()['queued']);
        $other = $this->candidate();
        $this->confirm($other);
        $this->assertSame('queued', $other->fresh()->state);
        $this->assertSame('scheduled', $d->fresh()->state);
    }

    public function test_review_schedules_its_exact_commit_without_another_inspection_or_duplicate_plan(): void
    {
        $review = $this->candidate();
        $original = array_intersect_key($review->getRawOriginal(), array_flip(['candidate', 'digest', 'preflight', 'starting_sha', 'target_sha', 'expires_at']));
        $data = ['reviewed' => true, 'digest' => $review->digest, 'scheduled_for' => now()->addDay()->toIso8601String(), 'schedule_timezone' => 'Asia/Kuala_Lumpur'];
        $this->remote->commands = [];
        $this->remote->target = str_repeat('c', 40);
        $this->actingAs($this->owner)->post('/deployments/'.$review->id.'/confirm', $data)->assertSessionMissing('error');
        $scheduled = Deployment::where('idempotency_key', 'scheduled-preview:'.$review->id)->firstOrFail();
        $this->assertSame('scheduled', $scheduled->state);
        $saved = $review->fresh()->getRawOriginal();
        foreach ($original as $field => $value) {
            $this->assertSame($value, $saved[$field]);
        }
        $this->assertSame('cancelled', $review->fresh()->state);
        $this->assertSame($review->target_sha, $scheduled->target_sha);
        $this->assertSame($review->starting_sha, $scheduled->starting_sha);
        $this->assertSame($review->candidate['hooks'], $scheduled->candidate['hooks']);
        $this->assertNotSame($review->digest, $scheduled->digest);
        $this->assertNull($scheduled->active_environment_id);
        $this->assertCount(0, $this->remote->commands);
        Queue::assertNothingPushed();
        $this->post('/deployments/'.$review->id.'/confirm', $data)->assertRedirect('/deployments/'.$scheduled->id)->assertSessionMissing('error');
        $this->assertDatabaseCount('deployments', 2);
        $this->post('/deployments/'.$review->id.'/confirm', [...$data, 'scheduled_for' => now()->addDays(2)->toIso8601String()])->assertSessionHas('error');
        $this->post('/deployments/'.$review->id.'/confirm', ['reviewed' => true, 'digest' => $review->digest])->assertSessionHas('error');
        $this->assertSame(1, $this->due($scheduled)['queued']);
        app(DeploymentExecutor::class)->execute($scheduled->id);
        $this->assertSame('failed', $scheduled->fresh()->state);
        $this->assertFalse($scheduled->fresh()->code_updated);
        $this->assertNotSame($this->remote->target, $this->remote->head);
    }

    public function test_scheduling_at_confirmation_activates_production_without_approval(): void
    {
        $this->environment->update(['production' => true]);
        $review = $this->candidate();
        $this->actingAs($this->owner)->post('/deployments/'.$review->id.'/confirm', ['reviewed' => true, 'digest' => $review->digest,
            'scheduled_for' => now()->addDay()->toIso8601String(), 'schedule_timezone' => 'Asia/Kuala_Lumpur'])->assertSessionMissing('error');
        $scheduled = Deployment::where('idempotency_key', 'scheduled-preview:'.$review->id)->firstOrFail();
        $this->assertSame('scheduled', $scheduled->state);
        $this->assertNull($scheduled->active_environment_id);
        $this->assertNull($scheduled->approval);
        Queue::assertNothingPushed();
        $this->assertSame(1, $this->due($scheduled)['queued']);
        app(DeploymentExecutor::class)->execute($scheduled->id);
        $this->assertSame('succeeded', $scheduled->fresh()->state);
    }

    public function test_schedule_choice_rejects_invalid_time_digest_expiry_configuration_and_other_requesters(): void
    {
        $review = $this->candidate();
        $data = ['reviewed' => true, 'digest' => $review->digest, 'scheduled_for' => now()->addDay()->toIso8601String(), 'schedule_timezone' => 'Asia/Kuala_Lumpur'];
        $this->actingAs($this->member())->post('/deployments/'.$review->id.'/confirm', $data)->assertForbidden();
        $this->actingAs($this->owner);
        foreach ([now()->subMinute(), now()->addDays(31)] as $time) {
            $this->post('/deployments/'.$review->id.'/confirm', [...$data, 'scheduled_for' => $time->toIso8601String()])->assertSessionHasErrors('scheduled_for');
        }
        $this->post('/deployments/'.$review->id.'/confirm', [...$data, 'schedule_timezone' => 'Invalid/Zone'])->assertSessionHasErrors('schedule_timezone');
        $this->post('/deployments/'.$review->id.'/confirm', [...$data, 'reviewed' => false])->assertSessionHasErrors('reviewed');
        $this->post('/deployments/'.$review->id.'/confirm', [...$data, 'digest' => str_repeat('0', 64)])->assertSessionHas('error');
        $this->environment->increment('pipeline_version');
        $this->post('/deployments/'.$review->id.'/confirm', $data)->assertSessionHas('error');
        $this->environment->decrement('pipeline_version');
        $this->travel(61)->minutes();
        $this->post('/deployments/'.$review->id.'/confirm', $data)->assertSessionHas('error');
        $this->assertSame('awaiting_confirmation', $review->fresh()->state);
        $this->assertDatabaseCount('deployments', 1);
        Queue::assertNothingPushed();
    }

    public function test_schedule_copy_and_confirmation_roll_back_together(): void
    {
        $review = $this->candidate();
        DB::unprepared("CREATE TRIGGER reject_schedule BEFORE UPDATE ON deployments WHEN NEW.state = 'scheduled' BEGIN SELECT RAISE(ABORT, 'Confirmation unavailable'); END");
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->owner)->post('/deployments/'.$review->id.'/confirm', ['reviewed' => true, 'digest' => $review->digest,
                'scheduled_for' => now()->addDay()->toIso8601String(), 'schedule_timezone' => 'Asia/Kuala_Lumpur']);
            $this->fail('Expected confirmation failure.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Confirmation unavailable', $error->getMessage());
        }
        $this->assertSame('awaiting_confirmation', $review->fresh()->state);
        $this->assertNull($review->fresh()->finished_at);
        $this->assertDatabaseCount('deployments', 1);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'deployment.review_scheduled']);
        Queue::assertNothingPushed();
    }

    public function test_due_schedule_is_queued_once_and_executes_the_exact_reviewed_candidate(): void
    {
        $d = $this->scheduled();
        $this->confirm($d);
        $this->confirm($d);
        $this->remote->commands = [];
        $this->assertSame(1, $this->due($d)['queued']);
        $this->assertCount(0, $this->remote->commands);
        $this->assertSame(0, app(DeploymentScheduler::class)->dispatchDue()['queued']);
        Queue::assertPushed(ExecuteDeployment::class, 1);
        $this->assertSame($this->environment->id, $d->fresh()->active_environment_id);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('succeeded', $d->fresh()->state);
        $this->assertSame($d->target_sha, $this->remote->head);
    }

    public function test_production_schedule_with_a_legacy_approval_flag_dispatches_without_approval(): void
    {
        $this->environment->update(['production' => true, 'requires_approval' => true]);
        $d = $this->scheduled();
        $this->confirm($d);
        $this->assertSame('scheduled', $d->fresh()->state);
        $this->assertNull($d->fresh()->active_environment_id);
        $this->assertNull($d->approval);
        Queue::assertNothingPushed();
        $this->assertSame(1, $this->due($d)['queued']);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('succeeded', $d->fresh()->state);
    }

    public function test_legacy_pending_schedule_expires_without_requester_reconfirmation(): void
    {
        $this->environment->update(['production' => true]);
        $d = $this->scheduled();
        $d->update(['state' => 'awaiting_approval']);
        $this->travelTo($d->scheduled_for->copy()->addSecond());
        $this->actingAs($this->owner)->post('/deployments/'.$d->id.'/confirm', ['digest' => $d->digest, 'reviewed' => true])->assertSessionHas('error');
        $this->assertSame(1, app(DeploymentScheduler::class)->dispatchDue()['expired']);
        Queue::assertNothingPushed();
        $this->assertSame('expired', $d->fresh()->state);
    }

    public function test_due_dispatch_rechecks_requester_mfa_and_configuration(): void
    {
        foreach (['requester', 'mfa', 'configuration'] as $reason) {
            $this->travelBack();
            config(['deployhub.require_mfa' => false]);
            $this->owner->forceFill(['enabled' => true])->save();
            $this->environment->update(['production' => false]);
            $d = $this->scheduled();
            $this->confirm($d);
            if ($reason === 'requester') {
                $this->owner->forceFill(['enabled' => false])->save();
            }
            if ($reason === 'mfa') {
                config(['deployhub.require_mfa' => true]);
            }
            if ($reason === 'configuration') {
                $this->environment->increment('pipeline_version');
            }
            $this->remote->commands = [];
            $outcome = $this->due($d);
            $this->assertSame(1, $outcome['failed'], $reason.' '.json_encode($outcome).' '.$d->fresh()->state.' '.$d->fresh()->error);
            $this->assertSame('failed', $d->fresh()->state);
            $this->assertNull($d->fresh()->active_environment_id);
            $this->assertCount(0, $this->remote->commands);
        }
        Queue::assertNothingPushed();
    }

    public function test_busy_or_locked_targets_wait_and_unknown_reservations_remain_held(): void
    {
        $d = $this->scheduled();
        $this->confirm($d);
        $other = $this->candidate();
        $other->update(['state' => 'unknown', 'active_environment_id' => $this->environment->id]);
        $this->assertSame(1, $this->due($d)['waiting']);
        $this->assertSame('scheduled', $d->fresh()->state);
        $other->update(['state' => 'reconciled', 'active_environment_id' => null]);
        $lock = Cache::lock('environment:'.$this->environment->id, 60);
        $this->assertTrue($lock->get());
        try {
            $this->assertSame(1, app(DeploymentScheduler::class)->dispatchDue()['waiting']);
        } finally {
            $lock->release();
        }
        $this->assertSame(1, app(DeploymentScheduler::class)->dispatchDue()['queued']);
        Queue::assertPushed(ExecuteDeployment::class, 1);
    }

    public function test_cancelled_schedules_and_deleted_registrations_never_dispatch(): void
    {
        $d = $this->scheduled();
        $this->confirm($d);
        $this->post('/deployments/'.$d->id.'/cancel')->assertSessionMissing('error');
        $this->assertSame(0, $this->due($d)['queued']);
        $this->travelBack();
        $next = $this->scheduled();
        $this->confirm($next);
        $this->delete('/projects/'.$this->environment->project_id)->assertRedirect('/projects');
        $this->assertSame('expired', $next->fresh()->state);
        $this->assertSame(0, $this->due($next)['queued']);
        $this->get('/deployments/'.$next->id)->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_execution_window_expiry_and_unconfirmed_schedules_do_not_run(): void
    {
        $unconfirmed = $this->scheduled();
        $this->assertSame(1, $this->due($unconfirmed)['expired']);
        $this->travelBack();
        $d = $this->scheduled();
        $this->confirm($d);
        $this->travelTo($d->scheduled_for->copy()->addMinutes(61));
        $this->assertSame(1, app(DeploymentScheduler::class)->dispatchDue()['expired']);
        Queue::assertNothingPushed();
    }

    public function test_enqueue_failure_rolls_back_reservation_and_schedule_state(): void
    {
        $d = $this->scheduled();
        $this->confirm($d);
        Bus::partialMock()->shouldReceive('dispatch')->andThrow(new \RuntimeException('Queue unavailable'));
        try {
            $this->due($d);
            $this->fail('Expected enqueue failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Queue unavailable', $error->getMessage());
        }
        $this->assertSame('scheduled', $d->fresh()->state);
        $this->assertNull($d->fresh()->active_environment_id);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_time_cannot_be_changed_and_worker_cannot_execute_early(): void
    {
        $d = $this->scheduled();
        $this->confirm($d);
        $d->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        $this->remote->commands = [];
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertCount(0, $this->remote->commands);
        $this->expectException(\LogicException::class);
        $d->fresh()->update(['scheduled_for' => now()->addDays(3)]);
    }

    public function test_schedule_creation_requires_deploy_permission_mfa_and_valid_future_time(): void
    {
        $data = ['idempotency_key' => (string) Str::uuid(), 'scheduled_for' => now()->addDay()->toIso8601String(), 'schedule_timezone' => 'Asia/Kuala_Lumpur'];
        $this->actingAs($this->member('viewer'))->post('/environments/'.$this->environment->id.'/deployment-requests', $data)->assertForbidden();
        $this->actingAs($this->member('developer', false))->post('/environments/'.$this->environment->id.'/deployment-requests', $data)->assertForbidden();
        $this->actingAs($this->owner);
        foreach ([now()->subMinute(), now()->addDays(31)] as $time) {
            $this->post('/environments/'.$this->environment->id.'/deployment-requests', [...$data, 'scheduled_for' => $time->toIso8601String()])->assertSessionHasErrors('scheduled_for');
        }
        $this->post('/environments/'.$this->environment->id.'/deployment-requests', [...$data, 'schedule_timezone' => 'Invalid/Zone'])->assertSessionHasErrors('schedule_timezone');
        config(['deployhub.require_mfa' => true]);
        $this->post('/environments/'.$this->environment->id.'/deployment-requests', $data)->assertRedirect('/security');
        $this->assertDatabaseCount('deployments', 0);
    }
}
