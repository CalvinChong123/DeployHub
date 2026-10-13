<?php

namespace Tests\Feature;

use App\Services\Deployment\CandidateService;
use App\Services\Deployment\DeploymentException;
use App\Services\Deployment\DeploymentExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\HubFixture;
use Tests\TestCase;

class PreflightPerformanceTest extends TestCase
{
    use HubFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
    }

    private function reordered(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->reordered($item);
            }
        }
        unset($item);
        if (! array_is_list($value)) {
            krsort($value);
        }

        return $value;
    }

    public function test_mysql_json_key_order_does_not_invalidate_new_or_legacy_candidates(): void
    {
        $this->environment->update(['hooks' => [['id' => (string) Str::uuid(), 'name' => 'Post fixture', 'phase' => 'post', 'script' => 'echo ok', 'enabled' => true]]]);
        foreach ([false, true] as $legacy) {
            $d = $this->candidate();
            $snapshot = $d->candidate;
            $digest = $legacy ? hash('sha256', json_encode([$snapshot, $d->starting_sha, $d->target_sha, $d->user_id], JSON_THROW_ON_ERROR)) : $d->digest;
            DB::table('deployments')->where('id', $d->id)->update(['candidate' => json_encode($this->reordered($snapshot)), 'digest' => $digest]);
            DB::table('environments')->where('id', $this->environment->id)->update(['hooks' => json_encode($this->reordered($this->environment->fresh()->hooks))]);
            $this->actingAs($this->owner)->post('/deployments/'.$d->id.'/confirm', ['digest' => $digest, 'reviewed' => true])->assertSessionMissing('error');
            $this->assertSame('queued', $d->fresh()->state);
            // Remove the queued database job: execute directly against the isolated fake transport.
            DB::table('jobs')->delete();
            app(DeploymentExecutor::class)->execute($d->id);
            $this->assertSame('succeeded', $d->fresh()->state);
        }
    }

    public function test_hook_order_changes_are_detected_despite_object_key_normalization(): void
    {
        $hooks = [];
        foreach (['one', 'two'] as $name) {
            $hooks[] = ['id' => (string) Str::uuid(), 'name' => $name, 'phase' => 'post', 'script' => 'echo '.$name, 'enabled' => true];
        }
        $this->environment->update(['hooks' => $hooks]);
        $d = $this->candidate();
        $this->environment->update(['hooks' => array_reverse($hooks)]);
        $this->expectException(DeploymentException::class);
        $this->expectExceptionMessage('hooks');
        app(CandidateService::class)->validate($d->fresh());
    }

    public function test_inspection_uses_two_remote_commands_and_review_reuses_the_result(): void
    {
        $service = app(CandidateService::class);
        $service->inspect($this->environment, $this->owner);
        $this->assertCount(2, $this->remote->commands);
        $this->remote->commands = [];
        $d = $this->candidate();
        $this->assertCount(0, $this->remote->commands);
        $this->assertSame($this->environment->fresh()->last_preflight, $d->preflight);
        $this->assertNotNull($d->preflight['duration_ms']);
        // Explicit refresh always connects, even inside the reuse window.
        $service->inspect($this->environment, $this->owner);
        $this->assertCount(2, $this->remote->commands);
    }

    public function test_expiry_failed_checks_and_changed_configuration_force_fresh_inspection(): void
    {
        $service = app(CandidateService::class);
        foreach (['expired', 'failed', 'configuration', 'credentials', 'old_format'] as $reason) {
            $service->inspect($this->environment, $this->owner);
            $env = $this->environment->fresh();
            match ($reason) {
                'expired' => $env->update(['checked_at' => now()->subSeconds(121)]),
                'failed' => $env->update(['last_preflight' => ['error' => 'Inspection failed.']]),
                'configuration' => $env->increment('pipeline_version'),
                'credentials' => $env->server->increment('credential_version'),
                'old_format' => $env->update(['last_preflight' => array_diff_key($env->last_preflight, ['inspection_version' => true])]),
            };
            $this->remote->commands = [];
            $this->candidate();
            $this->assertCount(2, $this->remote->commands, $reason);
        }
    }

    public function test_worker_refetches_and_rejects_remote_advance_after_cached_review(): void
    {
        app(CandidateService::class)->inspect($this->environment, $this->owner);
        $this->remote->commands = [];
        $d = $this->candidate();
        $this->assertCount(0, $this->remote->commands);
        $this->remote->target = str_repeat('c', 40);
        $d->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertFalse($d->fresh()->code_updated);
        $this->assertCount(2, $this->remote->commands);
        $this->assertStringContainsString('Repository state changed', $d->fresh()->error);
        $this->assertStringNotContainsString("'log'", implode("\n", $this->remote->commands));
    }

    public function test_pre_hook_inspection_batches_untracked_hashes_and_rechecks_without_refetch(): void
    {
        $this->environment->update(['hooks' => [['id' => (string) Str::uuid(), 'name' => 'Prepare', 'phase' => 'pre', 'script' => 'echo prepare', 'enabled' => true]]]);
        $this->remote->dirty = true;
        $d = $this->candidate();
        $this->assertCount(4, $this->remote->commands);
        $this->remote->commands = [];
        $d->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertSame('succeeded', $d->fresh()->state);
        $this->assertCount(1, array_filter($this->remote->commands, fn ($command) => str_contains($command, 'fetch')));
        $this->assertCount(0, array_filter($this->remote->commands, fn ($command) => str_contains($command, 'rev-list') || str_contains($command, '--format=%H')));
    }
}
