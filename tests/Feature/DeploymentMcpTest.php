<?php

namespace Tests\Feature;

use App\Jobs\ExecuteDeployment;
use App\Models\AutomationGrant;
use App\Models\Deployment;
use App\Models\EnvironmentPermission;
use App\Services\AutomationAccess;
use App\Services\Deployment\CommandResult;
use App\Services\Deployment\DeploymentExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\HubFixture;
use Tests\TestCase;

class DeploymentMcpTest extends TestCase
{
    use HubFixture, RefreshDatabase;

    private AutomationGrant $grant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        config(['deployhub.require_mfa' => true, 'app.debug' => false]);
        $this->owner->forceFill(['two_factor_secret' => (new Google2FA)->generateSecretKey(), 'two_factor_confirmed_at' => now()])->save();
        [$this->grant, $this->token] = app(AutomationAccess::class)->issue($this->owner, $this->environment, 'Fixture Codex', 14);
        Queue::fake();
    }

    private function rpc(string $method, array $params = [], ?string $token = null)
    {
        return $this->postJson('/mcp/deployhub', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params],
            ['Authorization' => 'Bearer '.($token ?? $this->token), 'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-11-25']);
    }

    private function callTool(string $name, array $arguments = [], ?string $token = null)
    {
        return $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments], $token);
    }

    private function toolResult($response): array
    {
        $response->assertOk()->assertJsonPath('result.isError', false);

        return json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function prepare(?string $key = null): array
    {
        return $this->toolResult($this->callTool('prepare_deployment', ['environment_id' => $this->environment->id,
            'expected_sha' => $this->remote->target, 'idempotency_key' => $key ?? (string) Str::uuid()]));
    }

    private function execute(array $preview): array
    {
        return $this->toolResult($this->callTool('execute_deployment', ['deployment_id' => $preview['deployment_id'], 'digest' => $preview['digest']]));
    }

    public function test_protocol_initialization_and_tools_are_available_with_a_bearer_token(): void
    {
        $this->rpc('initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
            'clientInfo' => ['name' => 'Fixture', 'version' => '1.0']])->assertOk()->assertJsonPath('result.serverInfo.name', 'DeployHub');
        $tools = $this->rpc('tools/list')->assertOk()->json('result.tools');
        $this->assertSame(['list_deployment_targets', 'prepare_deployment', 'execute_deployment', 'get_deployment_status'], array_column($tools, 'name'));
        $this->assertTrue($tools[0]['annotations']['readOnlyHint']);
        $target = $this->toolResult($this->callTool('list_deployment_targets'))['targets'][0];
        $this->assertSame($this->environment->id, $target['environment_id']);
        $this->assertSame('staging', $target['branch']);
        $this->assertEmpty($this->remote->commands);
    }

    public function test_stateless_protocol_discovery_and_tool_calls_use_authenticated_context(): void
    {
        $meta = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => (object) []];
        $headers = ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2026-07-28'];
        $discovery = $this->postJson('/mcp/deployhub', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => $meta]],
            [...$headers, 'MCP-Method' => 'server/discover'])->assertOk()->json('result');
        $this->assertSame('DeployHub', $discovery['_meta']['io.modelcontextprotocol/serverInfo']['name']);
        $response = $this->postJson('/mcp/deployhub', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'list_deployment_targets', 'arguments' => (object) [], '_meta' => $meta]],
            [...$headers, 'MCP-Method' => 'tools/call', 'MCP-Name' => 'list_deployment_targets']);
        $this->assertSame($this->environment->id, $this->toolResult($response)['targets'][0]['environment_id']);
        $this->assertEmpty($this->remote->commands);
    }

    public function test_prepare_execute_poll_and_lost_response_retries_use_one_exact_deployment(): void
    {
        $key = (string) Str::uuid();
        $preview = $this->prepare($key);
        $this->assertSame('awaiting_confirmation', $preview['state']);
        $this->assertSame($this->remote->target, $preview['target_sha']);
        $this->assertSame($preview['deployment_id'], $this->prepare($key)['deployment_id']);
        Queue::assertNothingPushed();
        $this->assertSame('queued', $this->execute($preview)['state']);
        $this->assertSame('queued', $this->execute($preview)['state']);
        Queue::assertPushed(ExecuteDeployment::class, 1);
        $deployment = Deployment::findOrFail($preview['deployment_id']);
        $this->assertSame($this->grant->id, $deployment->candidate['automation_grant_id']);
        app(DeploymentExecutor::class)->execute($deployment->id);
        $status = $this->toolResult($this->callTool('get_deployment_status', ['deployment_id' => $deployment->id]));
        $this->assertSame('succeeded', $status['state']);
        $this->assertSame($preview['target_sha'], $this->remote->head);
        $this->assertNotEmpty($status['steps']);
        $this->assertSame('succeeded', $this->execute($preview)['state']);
        Queue::assertPushed(ExecuteDeployment::class, 1);
        $this->assertStringNotContainsString($this->token, json_encode($status));
        $this->assertArrayNotHasKey('candidate', $status);
        $this->assertArrayNotHasKey('token_hash', $this->grant->toArray());
    }

    public function test_wrong_expected_sha_and_conflicting_retry_do_not_create_another_candidate(): void
    {
        $args = ['environment_id' => $this->environment->id, 'expected_sha' => str_repeat('c', 40), 'idempotency_key' => (string) Str::uuid()];
        $this->callTool('prepare_deployment', $args)->assertOk()->assertJsonPath('result.isError', true);
        $this->assertDatabaseCount('deployments', 0);
        $key = (string) Str::uuid();
        $this->prepare($key);
        $args['idempotency_key'] = $key;
        $this->callTool('prepare_deployment', $args)->assertOk()->assertJsonPath('result.isError', true);
        $this->assertDatabaseCount('deployments', 1);
        Queue::assertNothingPushed();
    }

    public function test_bad_digest_and_invalid_arguments_do_not_enqueue(): void
    {
        $preview = $this->prepare();
        $this->callTool('execute_deployment', ['deployment_id' => $preview['deployment_id'], 'digest' => str_repeat('0', 64)])->assertOk()->assertJsonPath('result.isError', true);
        $this->callTool('prepare_deployment', ['environment_id' => $this->environment->id, 'expected_sha' => 'latest', 'idempotency_key' => 'retry'])->assertOk()->assertJsonPath('result.isError', true);
        $this->assertSame('awaiting_confirmation', Deployment::find($preview['deployment_id'])->state);
        Queue::assertNothingPushed();
    }

    public function test_browser_session_cannot_replace_token_and_hostile_origins_are_rejected(): void
    {
        $this->actingAs($this->owner)->withSession(['mfa_verified' => true]);
        $this->postJson('/mcp/deployhub', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
        $this->postJson('/mcp/deployhub', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            ['Authorization' => 'Bearer '.$this->token, 'Origin' => 'https://attacker.test'])->assertForbidden();
        $this->assertEmpty($this->remote->commands);
    }

    public function test_scope_cannot_access_other_targets_tokens_or_web_candidates(): void
    {
        $this->callTool('prepare_deployment', ['environment_id' => $this->environment->id + 1, 'expected_sha' => $this->remote->target,
            'idempotency_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('result.isError', true);
        $this->assertEmpty($this->remote->commands);
        $preview = $this->prepare();
        [, $otherToken] = app(AutomationAccess::class)->issue($this->owner, $this->environment, 'Another client', 14);
        $this->callTool('get_deployment_status', ['deployment_id' => $preview['deployment_id']], $otherToken)->assertOk()->assertJsonPath('result.isError', true);
        $web = $this->candidate();
        $this->callTool('execute_deployment', ['deployment_id' => $web->id, 'digest' => $web->digest])->assertOk()->assertJsonPath('result.isError', true);
        Queue::assertNothingPushed();
    }

    public static function revocations(): array
    {
        return array_map(fn ($reason) => [$reason], ['token', 'expiry', 'disabled_user', 'membership', 'permission', 'server_assignment',
            'mfa_reset', 'mfa_changed', 'password', 'production', 'approval', 'branch', 'server_peer']);
    }

    #[DataProvider('revocations')]
    public function test_revocation_is_enforced_by_the_api_and_worker_before_ssh(string $reason): void
    {
        $preview = $this->prepare();
        $this->execute($preview);
        $commandCount = count($this->remote->commands);
        match ($reason) {
            'token' => $this->grant->update(['revoked_at' => now()]),
            'expiry' => $this->grant->update(['expires_at' => now()]),
            'disabled_user' => $this->owner->forceFill(['enabled' => false])->save(),
            'membership' => $this->workspace->users()->updateExistingPivot($this->owner->id, ['active' => false]),
            'permission' => EnvironmentPermission::where('user_id', $this->owner->id)->update(['can_deploy' => false]),
            'server_assignment' => $this->environment->server->users()->detach($this->owner),
            'mfa_reset' => $this->owner->forceFill(['two_factor_confirmed_at' => null])->save(),
            'mfa_changed' => $this->owner->forceFill(['two_factor_secret' => (new Google2FA)->generateSecretKey()])->save(),
            'password' => $this->owner->update(['password' => 'changed-password']),
            'production' => $this->environment->update(['production' => true]),
            'approval' => $this->environment->update(['requires_approval' => true]),
            'branch' => $this->environment->update(['branch' => 'another-branch']),
            'server_peer' => $this->environment->server->update(['host' => 'another.test']),
        };
        $this->callTool('list_deployment_targets')->assertUnauthorized();
        app(DeploymentExecutor::class)->execute($preview['deployment_id']);
        $this->assertSame('failed', Deployment::find($preview['deployment_id'])->state);
        $this->assertCount($commandCount, $this->remote->commands);
        $this->assertSame($preview['starting_sha'], $this->remote->head);
    }

    public function test_grant_revocation_during_a_hook_stops_subsequent_commands(): void
    {
        $this->environment->update(['hooks' => [['id' => (string) Str::uuid(), 'name' => 'Fixture step', 'script' => 'fixture-step', 'phase' => 'post', 'enabled' => true]]]);
        $preview = $this->prepare();
        $this->execute($preview);
        $this->remote->hookHandlers['fixture-step'] = function () {
            $this->grant->update(['revoked_at' => now()]);

            return new CommandResult('Completed fixture step.', 0);
        };
        app(DeploymentExecutor::class)->execute($preview['deployment_id']);
        $deployment = Deployment::find($preview['deployment_id']);
        $this->assertSame('failed', $deployment->state);
        $this->assertTrue($deployment->code_updated);
        $this->assertFalse($deployment->steps()->where('type', 'verify_head')->exists());
    }

    public function test_changed_branch_tip_and_unknown_remote_state_never_report_success(): void
    {
        $preview = $this->prepare();
        $this->execute($preview);
        $this->remote->target = str_repeat('c', 40);
        app(DeploymentExecutor::class)->execute($preview['deployment_id']);
        $status = $this->toolResult($this->callTool('get_deployment_status', ['deployment_id' => $preview['deployment_id']]));
        $this->assertSame('failed', $status['state']);
        $this->assertSame($preview['starting_sha'], $this->remote->head);
        $next = $this->prepare();
        $this->execute($next);
        $this->remote->loseConnection = true;
        app(DeploymentExecutor::class)->execute($next['deployment_id']);
        $status = $this->toolResult($this->callTool('get_deployment_status', ['deployment_id' => $next['deployment_id']]));
        $this->assertSame('unknown', $status['state']);
        $this->assertSame('unknown', $this->execute($next)['state']);
        Queue::assertPushed(ExecuteDeployment::class, 2);
    }

    public function test_enqueue_failure_rolls_back_reservation_and_confirmation(): void
    {
        $preview = $this->prepare();
        Bus::partialMock()->shouldReceive('dispatch')->andThrow(new \RuntimeException('Queue unavailable'));
        $this->callTool('execute_deployment', ['deployment_id' => $preview['deployment_id'], 'digest' => $preview['digest']])->assertOk()->assertJsonPath('result.isError', true);
        $deployment = Deployment::find($preview['deployment_id']);
        $this->assertSame('awaiting_confirmation', $deployment->state);
        $this->assertNull($deployment->active_environment_id);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_status_excludes_credentials_and_sanitizes_recorded_output(): void
    {
        $preview = $this->prepare();
        Deployment::find($preview['deployment_id'])->steps()->create(['type' => 'fixture', 'state' => 'failed', 'output' => 'APP_KEY=fixture-sensitive']);
        $response = $this->callTool('get_deployment_status', ['deployment_id' => $preview['deployment_id']]);
        $response->assertOk();
        $this->assertStringNotContainsString('fixture-sensitive', $response->getContent());
        $this->assertStringNotContainsString('fixture.test:22', $response->getContent());
        $this->assertStringNotContainsString($this->grant->token_hash, $response->getContent());
    }

    public function test_token_issuance_requires_verified_mfa_password_and_explicit_target_access(): void
    {
        $data = ['name' => 'Phone Codex', 'environment_id' => $this->environment->id, 'days' => 7, 'password' => 'password'];
        $this->actingAs($this->owner)->post('/automation', $data)->assertRedirect('/two-factor-challenge');
        $this->withSession(['mfa_verified' => true])->post('/automation', [...$data, 'password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post('/automation', $data)->assertRedirect('/automation')->assertSessionHas('automation_token');
        $this->assertDatabaseCount('automation_grants', 2);
        $this->get('/automation')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Hub/Automation')->where('issuedToken', fn ($token) => str_starts_with($token, 'dhai_')));
        $this->get('/automation')->assertInertia(fn (Assert $page) => $page->where('issuedToken', null));
        $this->environment->update(['production' => true]);
        $this->post('/automation', $data)->assertRedirect('/automation');
        $this->environment->update(['production' => false, 'requires_approval' => true]);
        $this->post('/automation', $data)->assertRedirect('/automation');
        $this->environment->update(['requires_approval' => false]);
        $this->travel(61)->seconds();
        EnvironmentPermission::where('user_id', $this->owner->id)->update(['can_deploy' => false]);
        $this->post('/automation', $data)->assertForbidden();
    }

    public function test_mfa_is_required_for_grants_even_if_global_mfa_requirement_is_disabled(): void
    {
        config(['deployhub.require_mfa' => false]);
        $this->owner->forceFill(['two_factor_confirmed_at' => null])->save();
        $this->actingAs($this->owner)->withSession(['mfa_verified' => true])->post('/automation',
            ['name' => 'Unverified', 'environment_id' => $this->environment->id, 'days' => 7, 'password' => 'password'])->assertForbidden();
    }

    public function test_only_owner_can_revoke_a_token(): void
    {
        $other = $this->member();
        $this->actingAs($other)->delete('/automation/'.$this->grant->id)->assertNotFound();
        $this->actingAs($this->owner)->withSession(['mfa_verified' => true])->delete('/automation/'.$this->grant->id)->assertRedirect('/automation');
        $this->assertNotNull($this->grant->fresh()->revoked_at);
        $this->callTool('list_deployment_targets')->assertUnauthorized();
    }

    public static function protectedTargets(): array
    {
        return [['production'], ['requires_approval']];
    }

    #[DataProvider('protectedTargets')]
    public function test_protected_targets_wait_for_independent_approval_before_execution(string $flag): void
    {
        $this->environment->update([$flag => true]);
        [$this->grant, $this->token] = app(AutomationAccess::class)->issue($this->owner, $this->environment, 'Protected target', 14);
        $target = $this->toolResult($this->callTool('list_deployment_targets'))['targets'][0];
        $this->assertTrue($target['requires_independent_approval']);
        $preview = $this->prepare();
        $requested = $this->execute($preview);
        $this->assertSame('awaiting_approval', $requested['state']);
        $this->assertStringContainsString('independent', $requested['message']);
        $this->assertSame('awaiting_approval', $this->execute($preview)['state']);
        Queue::assertNothingPushed();
        $commandCount = count($this->remote->commands);
        app(DeploymentExecutor::class)->execute($preview['deployment_id']);
        $this->assertCount($commandCount, $this->remote->commands);
        $this->actingAs($this->owner)->withSession(['mfa_verified' => true])->post('/deployments/'.$preview['deployment_id'].'/approve',
            ['digest' => $preview['digest'], 'reviewed' => true])->assertForbidden();
        $approver = $this->member();
        $approver->forceFill(['two_factor_secret' => (new Google2FA)->generateSecretKey(), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($approver)->withSession(['mfa_verified' => true])->post('/deployments/'.$preview['deployment_id'].'/approve',
            ['digest' => $preview['digest'], 'reviewed' => true])->assertSessionMissing('error');
        Queue::assertPushed(ExecuteDeployment::class, 1);
        app(DeploymentExecutor::class)->execute($preview['deployment_id']);
        $this->assertSame('succeeded', $this->toolResult($this->callTool('get_deployment_status', ['deployment_id' => $preview['deployment_id']]))['state']);
    }
}
