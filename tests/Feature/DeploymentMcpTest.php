<?php

namespace Tests\Feature;

use App\Jobs\ExecuteDeployment;
use App\Models\AutomationGrant;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\EnvironmentPermission;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\AutomationAccess;
use App\Services\Deployment\CommandResult;
use App\Services\Deployment\DeploymentExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
        [$this->grant, $this->token] = app(AutomationAccess::class)->issue($this->owner, collect([$this->environment]), 'Fixture Codex', 14);
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

    private function anotherTarget(string $name = 'Portal', ?Workspace $workspace = null): Environment
    {
        $project = Project::create(['workspace_id' => ($workspace ?? $this->workspace)->id, 'name' => $name, 'repository' => $this->environment->project->repository]);
        $environment = $project->environments()->create(['server_id' => $this->environment->server_id,
            'name' => 'Staging', 'path' => '/home/deploy/'.strtolower($name), 'branch' => 'staging',
            'pipeline' => ['health'], 'health_url' => 'https://fixture.test/up']);
        $project->users()->attach($this->owner);
        EnvironmentPermission::create(['environment_id' => $environment->id, 'user_id' => $this->owner->id,
            'can_view' => true, 'can_deploy' => true, 'can_approve' => true]);

        return $environment;
    }

    private function prepareTarget(Environment $environment): array
    {
        $this->remote->root = $environment->path;

        return $this->toolResult($this->callTool('prepare_deployment', ['environment_id' => $environment->id,
            'expected_sha' => $this->remote->target, 'idempotency_key' => (string) Str::uuid()]));
    }

    public function test_one_token_lists_only_its_explicit_targets_and_never_includes_new_projects(): void
    {
        $second = $this->anotherTarget();
        [$this->grant, $this->token] = app(AutomationAccess::class)->issue($this->owner, collect([$second, $this->environment]), 'Selected projects', 14);
        $unselected = $this->anotherTarget('Unselected');
        $targets = $this->toolResult($this->callTool('list_deployment_targets'))['targets'];
        $this->assertSame([$this->environment->id, $second->id], array_column($targets, 'environment_id'));
        $this->assertEmpty($this->remote->commands);
        $this->callTool('prepare_deployment', ['environment_id' => $unselected->id, 'expected_sha' => $this->remote->target,
            'idempotency_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('result.isError', true);
        $this->assertEmpty($this->remote->commands);
        $serialized = json_encode($this->grant->fresh(['environments'])->toArray());
        foreach ($this->grant->environments as $environment) {
            $this->assertStringNotContainsString($environment->pivot->target_fingerprint, $serialized);
        }
        $this->assertStringNotContainsString($this->grant->token_hash, $serialized);
    }

    public function test_selected_targets_use_independent_candidates_and_the_same_token_can_execute_and_poll_each(): void
    {
        $second = $this->anotherTarget();
        [$this->grant, $this->token] = app(AutomationAccess::class)->issue($this->owner, collect([$this->environment, $second]), 'Two projects', 14);
        $firstPreview = $this->prepareTarget($this->environment);
        $secondPreview = $this->prepareTarget($second);
        $this->assertNotSame($firstPreview['deployment_id'], $secondPreview['deployment_id']);
        foreach ([$firstPreview, $secondPreview] as $preview) {
            $this->assertSame('queued', $this->execute($preview)['state']);
            $this->remote->root = Environment::findOrFail($preview['environment_id'])->path;
            $this->remote->head = $preview['starting_sha'];
            app(DeploymentExecutor::class)->execute($preview['deployment_id']);
            $status = $this->toolResult($this->callTool('get_deployment_status', ['deployment_id' => $preview['deployment_id']]));
            $this->assertSame('succeeded', $status['state']);
            $this->assertSame($preview['environment_id'], $status['environment_id']);
            $this->assertSame($preview['target_sha'], $status['target_sha']);
            $this->assertSame('succeeded', $this->execute($preview)['state']);
        }
        Queue::assertPushed(ExecuteDeployment::class, 2);
        [, $otherToken] = app(AutomationAccess::class)->issue($this->owner, collect([$this->environment, $second]), 'Other client', 14);
        $this->callTool('get_deployment_status', ['deployment_id' => $secondPreview['deployment_id']], $otherToken)->assertOk()->assertJsonPath('result.isError', true);
    }

    public static function targetRevocations(): array
    {
        return array_map(fn ($reason) => [$reason], ['permission', 'scope', 'branch', 'production', 'archive']);
    }

    #[DataProvider('targetRevocations')]
    public function test_one_invalid_target_is_blocked_by_api_and_worker_without_disabling_other_targets(string $reason): void
    {
        $second = $this->anotherTarget();
        [$this->grant, $this->token] = app(AutomationAccess::class)->issue($this->owner, collect([$this->environment, $second]), 'Two projects', 14);
        $preview = $this->prepareTarget($second);
        $this->execute($preview);
        $commandCount = count($this->remote->commands);
        match ($reason) {
            'permission' => EnvironmentPermission::where('environment_id', $second->id)->update(['can_deploy' => false]),
            'scope' => $this->grant->environments()->detach($second),
            'branch' => $second->update(['branch' => 'changed']),
            'production' => $second->update(['production' => true]),
            'archive' => $second->delete(),
        };
        $targets = $this->toolResult($this->callTool('list_deployment_targets'))['targets'];
        $this->assertSame([$this->environment->id], array_column($targets, 'environment_id'));
        $this->callTool('get_deployment_status', ['deployment_id' => $preview['deployment_id']])->assertOk()->assertJsonPath('result.isError', true);
        $this->callTool('execute_deployment', ['deployment_id' => $preview['deployment_id'], 'digest' => $preview['digest']])->assertOk()->assertJsonPath('result.isError', true);
        app(DeploymentExecutor::class)->execute($preview['deployment_id']);
        $this->assertSame('failed', Deployment::findOrFail($preview['deployment_id'])->state);
        $this->assertCount($commandCount, $this->remote->commands);
        $this->remote->root = $this->environment->path;
        $firstPreview = $this->prepare();
        $this->execute($firstPreview);
        app(DeploymentExecutor::class)->execute($firstPreview['deployment_id']);
        $this->assertSame('succeeded', $this->toolResult($this->callTool('get_deployment_status', ['deployment_id' => $firstPreview['deployment_id']]))['state']);
    }

    public function test_target_revocation_during_a_step_stops_that_target_even_when_another_target_remains_usable(): void
    {
        $second = $this->anotherTarget();
        $second->update(['hooks' => [['id' => (string) Str::uuid(), 'name' => 'Fixture step', 'script' => 'fixture-step', 'phase' => 'post', 'enabled' => true]]]);
        [$this->grant, $this->token] = app(AutomationAccess::class)->issue($this->owner, collect([$this->environment, $second]), 'Two projects', 14);
        $preview = $this->prepareTarget($second);
        $this->execute($preview);
        $this->remote->hookHandlers['fixture-step'] = function () use ($second) {
            EnvironmentPermission::where('environment_id', $second->id)->update(['can_deploy' => false]);

            return new CommandResult('Completed fixture step.', 0);
        };
        app(DeploymentExecutor::class)->execute($preview['deployment_id']);
        $deployment = Deployment::findOrFail($preview['deployment_id']);
        $this->assertSame('failed', $deployment->state);
        $this->assertTrue($deployment->code_updated);
        $this->assertFalse($deployment->steps()->where('type', 'verify_head')->exists());
        $this->assertSame([$this->environment->id], array_column($this->toolResult($this->callTool('list_deployment_targets'))['targets'], 'environment_id'));
    }

    public function test_revoking_a_multi_target_token_stops_all_queued_targets_before_ssh(): void
    {
        $second = $this->anotherTarget();
        [$this->grant, $this->token] = app(AutomationAccess::class)->issue($this->owner, collect([$this->environment, $second]), 'Two projects', 14);
        $previews = [$this->prepareTarget($this->environment), $this->prepareTarget($second)];
        foreach ($previews as $preview) {
            $this->execute($preview);
        }
        $commandCount = count($this->remote->commands);
        $this->grant->update(['revoked_at' => now()]);
        $this->callTool('list_deployment_targets')->assertUnauthorized();
        foreach ($previews as $preview) {
            app(DeploymentExecutor::class)->execute($preview['deployment_id']);
            $this->assertSame('failed', Deployment::findOrFail($preview['deployment_id'])->state);
        }
        $this->assertCount($commandCount, $this->remote->commands);
    }

    public function test_issuance_service_rejects_cross_workspace_scopes_even_with_rights_in_both_workspaces(): void
    {
        $foreign = Workspace::create(['name' => 'Foreign', 'slug' => 'foreign']);
        $foreign->users()->attach($this->owner, ['role' => 'owner', 'active' => true]);
        $second = $this->anotherTarget('Foreign', $foreign);
        try {
            app(AutomationAccess::class)->issue($this->owner, collect([$this->environment, $second]), 'Mixed workspace', 14);
            $this->fail('A token must not span workspaces.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('automation_grants', 1);
        $this->assertDatabaseCount('automation_grant_targets', 1);
    }

    public function test_multi_target_token_confirms_production_for_the_requested_target(): void
    {
        $second = $this->anotherTarget();
        $second->update(['production' => true]);
        [$this->grant, $this->token] = app(AutomationAccess::class)->issue($this->owner, collect([$this->environment, $second]), 'Mixed targets', 14);
        $targets = $this->toolResult($this->callTool('list_deployment_targets'))['targets'];
        $this->assertFalse($targets[0]['requires_independent_approval']);
        $this->assertFalse($targets[1]['requires_independent_approval']);
        $preview = $this->prepareTarget($second);
        $this->assertSame('queued', $this->execute($preview)['state']);
        $this->assertSame($second->id, Deployment::findOrFail($preview['deployment_id'])->active_environment_id);
        Queue::assertPushed(ExecuteDeployment::class, 1);
        app(DeploymentExecutor::class)->execute($preview['deployment_id']);
        $this->assertSame('succeeded', Deployment::findOrFail($preview['deployment_id'])->state);
    }

    public function test_browser_can_issue_a_multi_target_token_and_projects_are_projected_without_fingerprints(): void
    {
        $second = $this->anotherTarget();
        $this->actingAs($this->owner)->withSession(['mfa_verified' => true])->post('/automation', [
            'name' => 'Selected projects', 'environment_ids' => [$this->environment->id, $second->id], 'days' => 7, 'password' => 'password',
        ])->assertRedirect('/automation')->assertSessionHas('automation_token');
        $grant = AutomationGrant::latest('id')->firstOrFail();
        $this->assertSame([$this->environment->id, $second->id], $grant->environments->modelKeys());
        $this->get('/automation')->assertInertia(fn (Assert $page) => $page->component('Hub/Automation')->has('targets', 2)
            ->has('grants.0.targets', 2)->where('grants.0.targets.1.id', $second->id)->missing('grants.0.token_hash')->missing('grants.0.targets.0.target_fingerprint'));
    }

    public static function invalidScopeSelections(): array
    {
        return [['empty'], ['duplicate'], ['single_target_payload'], ['too_many'], ['ungranted'], ['foreign_workspace']];
    }

    #[DataProvider('invalidScopeSelections')]
    public function test_invalid_target_selection_never_issues_a_partial_grant(string $reason): void
    {
        $second = $this->anotherTarget();
        $ids = [$this->environment->id, $second->id];
        $extra = [];
        if ($reason === 'empty') {
            $ids = [];
        } elseif ($reason === 'duplicate') {
            $ids = [$this->environment->id, $this->environment->id];
        } elseif ($reason === 'single_target_payload') {
            $extra['environment_id'] = $this->environment->id;
        } elseif ($reason === 'too_many') {
            $ids = range(1, AutomationAccess::MAX_TARGETS + 1);
        } elseif ($reason === 'ungranted') {
            EnvironmentPermission::where('environment_id', $second->id)->update(['can_deploy' => false]);
        } else {
            $foreign = Workspace::create(['name' => 'Foreign', 'slug' => 'foreign']);
            $second->project->update(['workspace_id' => $foreign->id]);
        }
        $data = [
            'name' => 'Invalid scope', 'environment_ids' => $ids, 'days' => 7, 'password' => 'password', ...$extra,
        ];
        if ($reason === 'single_target_payload') {
            unset($data['environment_ids']);
        }
        $response = $this->actingAs($this->owner)->withSession(['mfa_verified' => true])->post('/automation', $data);
        if ($reason === 'ungranted') {
            $response->assertForbidden();
        } elseif ($reason === 'foreign_workspace') {
            $response->assertNotFound();
        } else {
            $response->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('automation_grants', 1);
        $this->assertDatabaseCount('automation_grant_targets', 1);
    }

    public function test_forward_scope_migration_transfers_data_and_removes_the_old_columns(): void
    {
        $preview = $this->prepare();
        $before = Deployment::findOrFail($preview['deployment_id'])->only('candidate', 'digest', 'target_sha');
        $hash = $this->grant->token_hash;
        $fingerprint = $this->grant->environments->first()->pivot->target_fingerprint;
        Schema::drop('automation_grant_targets');
        Schema::table('automation_grants', function ($table) {
            $table->foreignId('environment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('target_fingerprint', 64)->nullable();
        });
        DB::table('automation_grants')->where('id', $this->grant->id)->update([
            'environment_id' => $this->environment->id, 'target_fingerprint' => $fingerprint,
        ]);
        $migration = require database_path('migrations/2026_10_18_000001_create_automation_grant_targets.php');
        $migration->up();
        $this->assertFalse(Schema::hasColumn('automation_grants', 'environment_id'));
        $this->assertFalse(Schema::hasColumn('automation_grants', 'target_fingerprint'));
        $this->assertSame($hash, $this->grant->fresh()->token_hash);
        $this->assertSame($before, Deployment::findOrFail($preview['deployment_id'])->only('candidate', 'digest', 'target_sha'));
        $this->assertSame('queued', $this->execute($preview)['state']);
        app(DeploymentExecutor::class)->execute($preview['deployment_id']);
        $this->assertSame('succeeded', $this->toolResult($this->callTool('get_deployment_status', ['deployment_id' => $preview['deployment_id']]))['state']);
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
        [, $otherToken] = app(AutomationAccess::class)->issue($this->owner, collect([$this->environment]), 'Another client', 14);
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
        $data = ['name' => 'Phone Codex', 'environment_ids' => [$this->environment->id], 'days' => 7, 'password' => 'password'];
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
            ['name' => 'Unverified', 'environment_ids' => [$this->environment->id], 'days' => 7, 'password' => 'password'])->assertForbidden();
    }

    public function test_new_token_survives_an_inertia_asset_version_reload_and_is_delivered_once(): void
    {
        config(['app.asset_url' => 'https://assets.fixture.test/current']);
        $this->actingAs($this->owner)->withSession(['mfa_verified' => true])->post('/automation', [
            'name' => 'Version reload', 'environment_ids' => [$this->environment->id], 'days' => 7, 'password' => 'password',
        ], ['X-Inertia' => 'true', 'X-Inertia-Version' => 'previous-build'])->assertRedirect('/automation');
        $this->get('/automation', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'previous-build'])
            ->assertStatus(409)->assertSessionHas('automation_token');
        $this->get('/automation')->assertOk()->assertViewHas('page', fn ($page) => $page['encryptHistory'] === true)
            ->assertInertia(fn (Assert $page) => $page->where('issuedToken', fn ($token) => is_string($token) && str_starts_with($token, 'dhai_')));
        $this->get('/automation')->assertInertia(fn (Assert $page) => $page->where('issuedToken', null));
        $this->assertDatabaseCount('automation_grants', 2);
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

    public function test_legacy_mcp_pending_request_requires_explicit_execute_and_queues_once(): void
    {
        $preview = $this->prepare();
        $deployment = Deployment::findOrFail($preview['deployment_id']);
        $deployment->update(['state' => 'awaiting_approval', 'active_environment_id' => $this->environment->id]);
        $status = $this->toolResult($this->callTool('get_deployment_status', ['deployment_id' => $deployment->id]));
        $this->assertSame('awaiting_approval', $status['state']);
        $this->assertFalse($status['requires_independent_approval']);
        Queue::assertNothingPushed();
        $this->assertSame('queued', $this->execute($preview)['state']);
        $this->assertSame('queued', $this->execute($preview)['state']);
        Queue::assertPushed(ExecuteDeployment::class, 1);
        $this->assertSame($preview['digest'], $deployment->fresh()->digest);
    }

    #[DataProvider('protectedTargets')]
    public function test_production_and_legacy_protected_targets_execute_without_approval(string $flag): void
    {
        $this->environment->update([$flag => true]);
        [$this->grant, $this->token] = app(AutomationAccess::class)->issue($this->owner, collect([$this->environment]), 'Deployment target', 14);
        $target = $this->toolResult($this->callTool('list_deployment_targets'))['targets'][0];
        $this->assertFalse($target['requires_independent_approval']);
        $preview = $this->prepare();
        $this->assertFalse($preview['requires_independent_approval']);
        $this->assertSame('queued', $this->execute($preview)['state']);
        $this->assertSame('queued', $this->execute($preview)['state']);
        Queue::assertPushed(ExecuteDeployment::class, 1);
        app(DeploymentExecutor::class)->execute($preview['deployment_id']);
        $this->assertSame('succeeded', Deployment::findOrFail($preview['deployment_id'])->state);
        $this->assertDatabaseCount('deployment_approvals', 0);
    }
}
