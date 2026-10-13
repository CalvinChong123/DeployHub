<?php

namespace Tests\Feature;

use App\Services\Deployment\DeploymentExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HubFixture;
use Tests\TestCase;

class DeploymentPollingTest extends TestCase
{
    use HubFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
    }

    public function test_owner_polling_preserves_target_information_through_completion(): void
    {
        $deployment = $this->candidate();
        $deployment->update(['state' => 'queued', 'active_environment_id' => $this->environment->id]);

        $this->actingAs($this->owner)->get('/deployments/'.$deployment->id.'/events')
            ->assertOk()
            ->assertJsonPath('state', 'queued')
            ->assertJsonPath('environment.server.name', 'Fixture SSH')
            ->assertJsonPath('environment.project.name', 'Fixture app')
            ->assertJsonMissingPath('environment.server.host');

        app(DeploymentExecutor::class)->execute($deployment->id);

        $this->get('/deployments/'.$deployment->id.'/events')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('state', 'succeeded')
            ->assertJsonPath('environment.server.name', 'Fixture SSH')
            ->assertJsonPath('environment.project.name', 'Fixture app')
            ->assertJsonMissingPath('environment.server.host')
            ->assertJsonPath('steps.0.state', 'succeeded');
    }
}
