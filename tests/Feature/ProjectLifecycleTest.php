<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Models\Project;
use App\Services\Access;
use App\Services\Deployment\DeploymentExecutor;
use App\Services\Deployment\DeploymentWorkflow;
use App\Services\ProjectRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\HubFixture;
use Tests\TestCase;

class ProjectLifecycleTest extends TestCase
{
    use HubFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
    }

    public function test_copy_prefills_configuration_and_creates_an_independent_registration(): void
    {
        $member = $this->member();
        $history = $this->candidate();
        $hooks = DeploymentWorkflow::defaults(['health']);
        $this->environment->update(['hooks' => $hooks, 'pipeline' => []]);
        $response = $this->actingAs($this->owner)->get('/environments/'.$this->environment->id.'/copy');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('Hub/ProjectForm')
            ->where('initial.copy_of', $this->environment->id)->where('initial.path', '')
            ->where('initial.repository', $this->environment->project->repository)
            ->has('initial.hooks', 6)->where('initial.hooks.0.enabled', false));
        $initial = $response->viewData('page')['props']['initial'];
        $this->assertNotSame($hooks[0]['id'], $initial['hooks'][0]['id']);
        $this->post('/projects', [...$initial, 'path' => '/home/deploy/copy'])->assertSessionHasNoErrors();
        $copy = Environment::where('path', '/home/deploy/copy')->firstOrFail();
        $this->assertNotSame($this->environment->project_id, $copy->project_id);
        $this->assertSame(0, $copy->deployments()->count());
        $this->assertFalse(app(Access::class)->environment($member, $copy));
        $this->assertTrue(app(Access::class)->environment($this->owner, $copy, 'deploy'));
        $this->delete('/projects/'.$copy->project_id)->assertRedirect('/projects');
        $this->assertNotNull($this->environment->fresh());
        $this->assertDatabaseHas('deployments', ['id' => $history->id]);
        $this->post('/projects', [...$initial, 'path' => '/home/deploy/copy'])->assertSessionHasNoErrors();
    }

    public function test_active_reservation_and_repository_lock_block_deletion(): void
    {
        $d = $this->candidate();
        foreach (['awaiting_approval', 'queued', 'running', 'unknown'] as $state) {
            $d->update(['state' => $state, 'active_environment_id' => $this->environment->id]);
            $this->actingAs($this->owner)->delete('/projects/'.$this->environment->project_id)->assertSessionHasErrors('project');
            $this->assertNotNull($this->environment->fresh());
        }
        $d->update(['state' => 'cancelled', 'active_environment_id' => null]);
        $lock = Cache::lock('environment:'.$this->environment->id, 60);
        $this->assertTrue($lock->get());
        try {
            $this->delete('/projects/'.$this->environment->project_id)->assertSessionHasErrors('project');
        } finally {
            $lock->release();
        }
    }

    public function test_separation_preserves_environment_ids_permissions_and_immutable_history(): void
    {
        Schema::table('environments', fn (Blueprint $table) => $table->dropUnique('environments_project_id_unique'));
        $member = $this->member();
        $first = $this->environment;
        $second = $first->project->environments()->create(['server_id' => $first->server_id, 'name' => 'Production',
            'path' => '/home/deploy/production', 'branch' => 'main', 'production' => true, 'pipeline' => []]);
        $this->environment = $second;
        $this->grant($this->owner);
        $this->grant($member, false, false);
        $this->remote->branch = 'main';
        $this->remote->root = $second->path;
        $history = $this->candidate();
        $snapshot = $history->candidate;
        DB::transaction(fn () => ProjectRegistry::separateTargets());
        $first->refresh();
        $second->refresh();
        $this->assertNotSame($first->project_id, $second->project_id);
        $this->assertSame(2, Project::count());
        $this->assertSame($snapshot, $history->fresh()->candidate);
        $this->assertSame($second->id, $history->fresh()->environment_id);
        $this->assertTrue(app(Access::class)->environment($member, $second));
        $this->assertFalse(app(Access::class)->environment($member, $second, 'deploy'));
        $this->assertTrue(app(Access::class)->environment($member, $first, 'deploy'));
        DB::transaction(fn () => ProjectRegistry::separateTargets());
        $this->assertSame(2, Project::count());
    }

    public function test_default_options_preserve_selections_and_use_typed_commands(): void
    {
        $hooks = DeploymentWorkflow::defaults(['health', 'cache_clear']);
        $this->assertCount(6, $hooks);
        $this->assertFalse($hooks[0]['enabled']);
        $this->assertTrue(collect($hooks)->firstWhere('type', 'health')['enabled']);
        $hooks[0]['enabled'] = true;
        $hooks[0]['script'] = 'rm -rf something';
        $env = $this->environment;
        $this->actingAs($this->owner)->put('/environments/'.$env->id, ['environment_name' => $env->name,
            'server_id' => $env->server_id, 'path' => $env->path, 'branch' => $env->branch, 'production' => false,
            'requires_approval' => false, 'pipeline' => [], 'hooks' => $hooks, 'health_url' => $env->health_url])->assertSessionHasNoErrors();
        $this->assertSame('git reset --hard HEAD', $env->fresh()->hooks[0]['script']);
        // Use only reset and health so the adapter can verify both typed effects.
        $this->environment->update(['hooks' => array_values(array_filter($env->fresh()->hooks,
            fn ($hook) => in_array($hook['type'], ['hard_reset', 'health'])))]);
        $this->remote->dirty = true;
        $this->remote->healthStatus = '302';
        $d = $this->candidate();
        $d->update(['state' => 'queued', 'active_environment_id' => $env->id]);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertFalse($this->remote->dirty);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertTrue($d->fresh()->code_updated);
        $this->assertStringContainsString('2xx', $d->steps()->where('state', 'failed')->firstOrFail()->output);
    }
}
