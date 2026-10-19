<?php

namespace Tests\Feature;

use App\Services\Deployment\CandidateService;
use App\Services\Deployment\CommandResult;
use App\Services\Deployment\ConnectionLost;
use App\Services\Deployment\DeploymentExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\HubFixture;
use Tests\TestCase;

class DeploymentHooksTest extends TestCase
{
    use HubFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
    }

    private function hook(string $script, string $phase = 'pre', bool $enabled = true): array
    {
        return ['id' => (string) Str::uuid(), 'name' => 'Fixture hook', 'phase' => $phase, 'script' => $script, 'enabled' => $enabled];
    }

    private function execute($deployment): void
    {
        $deployment->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);
        app(DeploymentExecutor::class)->execute($deployment->id);
    }

    private function configuration(array $hooks): array
    {
        $env = $this->environment;

        return ['environment_name' => $env->name, 'server_id' => $env->server_id, 'path' => $env->path, 'branch' => $env->branch,
            'production' => false, 'requires_approval' => false, 'pipeline' => [], 'hooks' => $hooks];
    }

    public function test_admin_can_save_ordered_hooks_and_developer_cannot_edit_them(): void
    {
        $hooks = [$this->hook("echo 'first'\r\necho second"), $this->hook('echo post', 'post', false)];
        $this->actingAs($this->owner)->put('/environments/'.$this->environment->id, $this->configuration($hooks))->assertSessionHasNoErrors();
        $saved = $this->environment->fresh()->hooks;
        $this->assertSame("echo 'first'\necho second", $saved[0]['script']);
        $this->assertFalse($saved[1]['enabled']);
        $this->assertSame(2, $this->environment->fresh()->pipeline_version);
        $this->actingAs($this->member())->put('/environments/'.$this->environment->id, $this->configuration([]))->assertForbidden();
        $this->assertSame($saved, $this->environment->fresh()->hooks);
    }

    public function test_invalid_hooks_are_validation_errors(): void
    {
        $valid = $this->hook('echo ok');
        foreach (['script' => "echo\0bad", 'phase' => 'before-any-check', 'id' => 'invalid', 'enabled' => 'maybe'] as $field => $value) {
            $this->actingAs($this->owner)->put('/environments/'.$this->environment->id, $this->configuration([[...$valid, $field => $value]]))->assertSessionHasErrors('hooks.0.'.$field);
        }
        $this->put('/environments/'.$this->environment->id, $this->configuration([$valid, $valid]))->assertSessionHasErrors('hooks.0.id');
    }

    public function test_hooks_run_in_phase_order_and_disabled_hooks_are_skipped(): void
    {
        $pre = $this->hook('echo pre');
        $post = $this->hook('echo post', 'post');
        $other = $this->hook('echo second');
        $disabled = $this->hook('exit 42', 'pre', false);
        $this->environment->update(['hooks' => [$post, $pre, $disabled, $other], 'pipeline' => ['cache_clear', 'health']]);
        $d = $this->candidate();
        $this->assertSame([$post, $pre, $other], $d->candidate['hooks']);
        $this->assertStringNotContainsString("'sh' '-e'", implode('\n', $this->remote->commands));
        $this->execute($d);
        $this->assertSame('succeeded', $d->fresh()->state);
        $this->assertSame(['preflight', 'pre_hook:'.$pre['id'], 'pre_hook:'.$other['id'], 'repository_ready', 'fast_forward', 'cache_clear', 'post_hook:'.$post['id'], 'health', 'verify_head'], $d->steps()->pluck('type')->all());
        $this->assertStringNotContainsString('exit 42', implode('\n', $this->remote->commands));
        $count = count($this->remote->commands);
        app(DeploymentExecutor::class)->execute($d->id);
        $this->assertCount($count, $this->remote->commands);
    }

    public function test_dirty_preview_defers_cleanup_and_allows_unrelated_changes_to_remain(): void
    {
        $hook = $this->hook('git reset --hard HEAD');
        $this->environment->update(['hooks' => [$hook]]);
        $this->remote->dirty = true;
        $d = $this->candidate();
        $this->assertFalse($d->preflight['clean']);
        $this->assertSame('local_changes', $this->environment->fresh()->status);
        $this->assertTrue($this->remote->dirty);
        $this->remote->hookHandlers[$hook['script']] = function ($remote) {
            $remote->dirty = false;

            return new CommandResult('Prepared', 0);
        };
        $this->execute($d);
        $this->assertSame('succeeded', $d->fresh()->state);
        $this->remote->dirty = true;
        $this->remote->hookHandlers = [];
        $next = $this->candidate();
        $this->execute($next);
        $this->assertSame('succeeded', $next->fresh()->state);
        $this->assertTrue($this->remote->dirty);
        $this->assertTrue($next->fresh()->code_updated);
    }

    public function test_changed_dirty_content_blocks_hooks_before_any_side_effect(): void
    {
        $this->environment->update(['hooks' => [$this->hook('echo cleanup')]]);
        $this->remote->dirty = true;
        $d = $this->candidate();
        $this->remote->worktreeContent = 'different local work';
        $this->remote->commands = [];
        $this->execute($d);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertStringContainsString('Working tree changed', $d->fresh()->error);
        $this->assertStringNotContainsString("'sh' '-e'", implode('\n', $this->remote->commands));
    }

    public function test_pre_failure_stops_merge_and_post_failure_keeps_updated_code(): void
    {
        foreach (['pre', 'post'] as $phase) {
            $hook = $this->hook('exit 7', $phase);
            $this->environment->update(['hooks' => [$hook]]);
            $this->remote->hookHandlers[$hook['script']] = fn () => new CommandResult('APP_KEY=secret-value', 7);
            $d = $this->candidate();
            $this->execute($d);
            $this->assertSame('failed', $d->fresh()->state);
            $this->assertSame($phase === 'post', $d->fresh()->code_updated);
            $this->assertNull($d->fresh()->active_environment_id);
            $this->assertStringNotContainsString('secret-value', $d->steps()->where('exit_code', 7)->first()->output);
        }
    }

    public function test_changed_scripts_block_worker_but_production_needs_no_approval(): void
    {
        $this->environment->update(['hooks' => [$this->hook('echo old')]]);
        $d = $this->candidate();
        $this->environment->update(['hooks' => [$this->hook('echo new')]]);
        $this->remote->commands = [];
        $this->execute($d);
        $this->assertSame('failed', $d->fresh()->state);
        $this->assertCount(0, $this->remote->commands);
        $this->environment->update(['production' => true]);
        $next = $this->candidate();
        $this->remote->commands = [];
        $this->execute($next);
        $this->assertSame('succeeded', $next->fresh()->state);
        $this->assertNotEmpty($this->remote->commands);
        $this->assertNull($next->approval);
    }

    public function test_hook_connection_loss_and_pre_head_change_quarantine_environment(): void
    {
        $hook = $this->hook('echo prepare');
        $this->environment->update(['hooks' => [$hook]]);
        $d = $this->candidate();
        $this->remote->hookHandlers[$hook['script']] = function () {
            throw new ConnectionLost('Lost during hook.');
        };
        $this->execute($d);
        $this->assertSame('unknown', $d->fresh()->state);
        $this->assertSame($this->environment->id, $d->fresh()->active_environment_id);
        $d->update(['state' => 'reconciled', 'active_environment_id' => null]);
        $next = $this->candidate();
        $this->remote->hookHandlers[$hook['script']] = function ($remote) {
            $remote->head = $remote->target;

            return new CommandResult('Changed HEAD', 0);
        };
        $this->execute($next);
        $this->assertSame('unknown', $next->fresh()->state);
        $this->assertStringContainsString('changed the reviewed commit', $next->fresh()->error);
    }

    public function test_same_commit_hook_run_and_recovery_replay_enabled_hooks(): void
    {
        $hook = $this->hook('echo post', 'post');
        $pre = $this->hook('echo pre');
        $this->environment->update(['hooks' => [$pre, $hook]]);
        $this->remote->head = $this->remote->target;
        $d = $this->candidate();
        $this->assertSame(0, $d->preflight['count']);
        $this->remote->hookHandlers[$hook['script']] = fn () => new CommandResult('failed', 1);
        $this->execute($d);
        $this->assertTrue($d->fresh()->code_updated);
        $this->remote->hookHandlers = [];
        $recovery = app(CandidateService::class)->recover($d->fresh(), $this->owner, (string) Str::uuid());
        $this->execute($recovery);
        $this->assertSame('succeeded', $recovery->fresh()->state);
        $this->assertSame(['preflight', 'pre_hook:'.$pre['id'], 'repository_ready', 'post_hook:'.$hook['id'], 'health', 'verify_head'], $recovery->steps()->pluck('type')->all());
    }
}
