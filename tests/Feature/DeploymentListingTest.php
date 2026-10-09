<?php

namespace Tests\Feature;

use App\Models\Deployment;
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
        $this->candidate();
        $this->actingAs($this->owner)->get('/activity?q=0')->assertInertia(fn (Assert $page) => $page
            ->where('deployments.total', 1)->where('deployments.data.0.id', $zero->id));
        foreach (['%', '_', '!'] as $search) {
            $this->get('/activity?q='.urlencode($search))->assertInertia(fn (Assert $page) => $page->where('deployments.total', 0));
        }
    }
}
