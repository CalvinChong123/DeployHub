<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Services\Deployment\CandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\HubFixture;
use Tests\TestCase;

class DeploymentListingTest extends TestCase
{
    use HubFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
    }

    private function newerCopies(Deployment $source, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $copy = $source->replicate();
            $copy->forceFill(['idempotency_key' => (string) Str::uuid(), 'state' => 'awaiting_confirmation', 'active_environment_id' => null, 'created_at' => now()->addSeconds($i)])->save();
        }
    }

    public function test_pending_approval_remains_visible_beyond_fifty_newer_deployments(): void
    {
        $pending = $this->candidate();
        $pending->update(['state' => 'awaiting_approval']);
        $this->newerCopies($pending, 50);
        $this->actingAs($this->owner)->get('/approvals')->assertInertia(fn (Assert $page) => $page
            ->component('Hub/Index')->where('stats.approvals', 1)->where('deployments.total', 1)
            ->has('deployments.data', 1)->where('deployments.data.0.id', $pending->id));
    }

    public function test_project_summaries_keep_the_latest_result_beyond_newer_drafts_without_exposing_candidate_material(): void
    {
        $success = $this->candidate();
        $success->update(['state' => 'succeeded', 'finished_at' => now()]);
        $failed = $this->candidate();
        $failed->update(['state' => 'failed', 'code_updated' => true, 'finished_at' => now()]);
        $this->newerCopies($failed, 51);
        $cancelledPreview = $this->candidate();
        $cancelledPreview->update(['state' => 'cancelled']);
        $this->actingAs($this->owner)->get('/projects')->assertInertia(fn (Assert $page) => $page
            ->where('projects.0.environments.0.latest_deployment.id', $failed->id)
            ->where('projects.0.environments.0.latest_deployment.code_updated', true)
            ->where('projects.0.environments.0.latest_deployment.user.name', $this->owner->name)
            ->missing('projects.0.environments.0.latest_deployment.candidate')
            ->missing('projects.0.environments.0.latest_deployment.preflight')
            ->missing('projects.0.environments.0.latest_deployment.user.email'));
        $this->get('/environments/'.$this->environment->id.'?page=3')->assertInertia(fn (Assert $page) => $page
            ->where('environment.latest_deployment.id', $failed->id)
            ->where('environment.last_successful_deployment.id', $success->id)
            ->where('environment.last_successful_deployment.target_sha', $success->target_sha)
            ->missing('environment.last_successful_deployment.candidate'));
    }

    public function test_dashboard_counts_are_scoped_and_include_all_matching_records(): void
    {
        $failed = $this->candidate();
        $failed->update(['state' => 'failed']);
        $this->newerCopies($failed, 51);
        Deployment::where('id', '!=', $failed->id)->update(['state' => 'failed']);
        $scheduled = $this->candidate();
        $scheduled->update(['state' => 'scheduled']);
        $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('stats.failed', 52)->where('stats.scheduled', 1)
            ->where('projects.0.environments.0.latest_deployment.id', $scheduled->id));
        $this->actingAs($this->member('developer', false))->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('stats.failed', 0)->where('stats.scheduled', 0)->has('projects', 0));
    }

    public function test_summary_can_be_pending_without_claiming_a_successful_deployment(): void
    {
        $pending = $this->candidate();
        $pending->update(['state' => 'queued']);
        $this->actingAs($this->owner)->get('/environments/'.$this->environment->id)->assertInertia(fn (Assert $page) => $page
            ->where('environment.latest_deployment.state', 'queued')
            ->where('environment.last_successful_deployment', null));
    }

    public function test_expired_pending_approvals_are_excluded_without_scheduler(): void
    {
        $pending = $this->candidate();
        $pending->update(['state' => 'awaiting_approval']);
        $this->travel(61)->minutes();
        $this->actingAs($this->owner)->get('/approvals')->assertInertia(fn (Assert $page) => $page
            ->where('stats.approvals', 0)->where('deployments.total', 0)->has('deployments.data', 0));
    }

    public function test_activity_and_environment_history_offer_older_pages(): void
    {
        $oldest = $this->candidate();
        $this->newerCopies($oldest, 50);
        $this->actingAs($this->owner)->get('/activity?page=2')->assertInertia(fn (Assert $page) => $page
            ->where('deployments.total', 51)->has('deployments.data', 1)->where('deployments.data.0.id', $oldest->id));
        $this->get('/environments/'.$this->environment->id.'?page=3')->assertInertia(fn (Assert $page) => $page
            ->where('deployments.total', 51)->has('deployments.data', 11)->where('deployments.data.10.id', $oldest->id));
    }

    public function test_project_history_hides_expired_candidates_before_pagination_and_retains_activity(): void
    {
        $this->freezeTime();
        $success = $this->candidate();
        $success->update(['state' => 'succeeded', 'finished_at' => now()]);
        $pending = $this->candidate();
        $this->newerCopies($pending, 50);
        $approval = $this->candidate();
        $approval->update(['state' => 'awaiting_approval']);
        $expired = $this->candidate();
        $expired->update(['state' => 'expired']);
        $this->travel(60)->minutes();

        $fresh = $this->candidate();
        $fresh->update(['state' => 'awaiting_approval']);
        $this->newerCopies($fresh, 20);
        $this->actingAs($this->owner)->get('/environments/'.$this->environment->id.'?page=2')->assertInertia(fn (Assert $page) => $page
            ->where('deployments.total', 22)->has('deployments.data', 2)
            ->where('deployments.data.0.id', $fresh->id)->where('deployments.data.1.id', $success->id));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('deployments.total', 22));
        $this->get('/activity?state=expired')->assertInertia(fn (Assert $page) => $page
            ->where('deployments.total', 1)->where('deployments.data.0.id', $expired->id));
        $this->get('/activity?state=awaiting_confirmation')->assertInertia(fn (Assert $page) => $page->where('deployments.total', 71));
        $this->get('/deployments/'.$pending->id)->assertOk();
        $this->assertSame('awaiting_confirmation', $pending->fresh()->state);
        $this->assertSame('awaiting_approval', $approval->fresh()->state);
    }

    public function test_project_summary_skips_expired_approvals_and_pending_schedules_without_scheduler(): void
    {
        $this->freezeTime();
        $success = $this->candidate();
        $success->update(['state' => 'succeeded', 'finished_at' => now()]);
        $pending = $this->candidate();
        $pending->update(['state' => 'awaiting_approval']);
        $this->travel(60)->minutes();
        $scheduled = $pending->replicate();
        $scheduled->forceFill(['idempotency_key' => (string) Str::uuid(), 'scheduled_for' => now(), 'expires_at' => now()->addHour()])->save();

        foreach (['/dashboard', '/projects'] as $url) {
            $this->actingAs($this->owner)->get($url)->assertInertia(fn (Assert $page) => $page
                ->where('projects.0.environments.0.latest_deployment.id', $success->id));
        }
        $this->get('/environments/'.$this->environment->id)->assertInertia(fn (Assert $page) => $page
            ->where('environment.latest_deployment.id', $success->id)
            ->where('deployments.total', 1)->where('deployments.data.0.id', $success->id));
    }

    public function test_project_with_only_expired_pending_requests_has_no_latest_deployment(): void
    {
        $pending = $this->candidate();
        $pending->update(['state' => 'awaiting_approval']);
        $this->travel(61)->minutes();

        $this->actingAs($this->owner)->get('/environments/'.$this->environment->id)->assertInertia(fn (Assert $page) => $page
            ->where('environment.latest_deployment', null)->where('deployments.total', 0));
    }

    public function test_state_and_search_filters_apply_before_pagination(): void
    {
        $oldest = $this->candidate();
        $oldest->update(['state' => 'failed']);
        $this->newerCopies($oldest, 50);
        $this->actingAs($this->owner)->get('/activity?state=failed&q=Fixture')->assertInertia(fn (Assert $page) => $page
            ->where('filters.state', 'failed')->where('filters.q', 'Fixture')
            ->where('deployments.total', 1)->where('deployments.data.0.id', $oldest->id));
        $this->get('/activity?state=failed&q=missing')->assertInertia(fn (Assert $page) => $page->where('deployments.total', 0));
    }

    public function test_deployment_lists_do_not_expose_ungranted_environments(): void
    {
        $pending = $this->candidate();
        $pending->update(['state' => 'awaiting_approval']);
        $this->actingAs($this->member('developer', false))->get('/approvals')->assertInertia(fn (Assert $page) => $page
            ->where('stats.approvals', 0)->where('deployments.total', 0));
    }

    public function test_search_treats_zero_and_sql_wildcards_as_literal_text(): void
    {
        $this->remote->target = str_repeat('0', 40);
        $zero = $this->candidate();
        $this->remote->target = str_repeat('b', 40);
        app(CandidateService::class)->inspect($this->environment, $this->owner);
        $this->candidate();
        $this->actingAs($this->owner)->get('/activity?q=0')->assertInertia(fn (Assert $page) => $page
            ->where('deployments.total', 1)->where('deployments.data.0.id', $zero->id));
        foreach (['%', '_', '!'] as $search) {
            $this->get('/activity?q='.urlencode($search))->assertInertia(fn (Assert $page) => $page->where('deployments.total', 0));
        }
    }
}
