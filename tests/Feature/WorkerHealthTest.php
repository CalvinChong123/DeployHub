<?php

namespace Tests\Feature;

use App\Services\WorkerHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HubFixture;
use Tests\TestCase;

class WorkerHealthTest extends TestCase
{
    use HubFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        $this->freezeTime();
    }

    public function test_worker_stays_online_through_a_three_minute_command_and_then_expires(): void
    {
        DB::table('worker_heartbeats')->insert(['id' => 'fixture-worker', 'seen_at' => now()->subMinutes(5)]);
        $deployment = $this->candidate();
        $deployment->update(['state' => 'running', 'heartbeat_at' => now()]);

        $this->travel(180)->seconds();
        $this->assertTrue(app(WorkerHealth::class)->status()['online']);

        $this->travel(30)->seconds();
        $this->assertFalse(app(WorkerHealth::class)->status()['online']);
    }

    public function test_idle_worker_heartbeat_still_expires_after_ninety_seconds(): void
    {
        DB::table('worker_heartbeats')->insert(['id' => 'fixture-worker', 'seen_at' => now()]);

        $this->travel(89)->seconds();
        $this->assertTrue(app(WorkerHealth::class)->status()['online']);

        $this->travel(1)->seconds();
        $this->assertFalse(app(WorkerHealth::class)->status()['online']);
    }
}
