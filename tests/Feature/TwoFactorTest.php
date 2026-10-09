<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\HubFixture;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use HubFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        config(['deployhub.require_mfa' => true]);
    }

    public function test_enrollment_and_login_challenge_with_replay_prevention(): void
    {
        $this->actingAs($this->owner)->post('/security/setup', ['password' => 'password'])->assertSessionHas('mfa_setup_secret');
        $secret = session('mfa_setup_secret');
        $code = (new Google2FA)->getCurrentOtp($secret);
        $this->post('/security/enable', ['code' => $code])->assertRedirect('/dashboard');
        $this->assertNotNull($this->owner->fresh()->two_factor_confirmed_at);
        $this->actingAs($this->owner->fresh())->get('/servers')->assertOk();
        $this->post('/logout');
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
        $this->get('/servers')->assertRedirect('/two-factor-challenge');
        $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->travel(31)->seconds();
        $totp = new Google2FA;
        $next = $totp->oathTotp($secret, (int) floor(now()->timestamp / 30));
        $this->post('/two-factor-challenge', ['code' => $next])->assertRedirect('/dashboard');
        $this->get('/servers')->assertOk();
    }

    public function test_enrollment_requires_current_password_and_valid_code(): void
    {
        $this->actingAs($this->owner)->post('/security/setup', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post('/security/setup', ['password' => 'password']);
        $this->post('/security/enable', ['code' => 'invalid'])->assertSessionHasErrors('code');
        $this->assertNull($this->owner->fresh()->two_factor_confirmed_at);
    }
}
