<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PragmaRX\Google2FA\Google2FA;

class SecurityController extends Controller
{
    public function show(Request $request)
    {
        return Inertia::render('Hub/Security', ['enabled' => (bool) $request->user()->two_factor_confirmed_at, 'pendingSecret' => $request->session()->get('mfa_setup_secret')]);
    }

    public function setup(Request $request, Google2FA $totp)
    {
        $request->validate(['password' => 'required|current_password']);
        abort_if($request->user()->two_factor_confirmed_at, 422, 'Two-factor authentication is already enabled.');
        $request->session()->put('mfa_setup_secret', $totp->generateSecretKey());

        return back();
    }

    private function verify(User $user, string $secret, string $code, Google2FA $totp): void
    {
        $counter = $totp->verifyKeyNewer($secret, $code, $user->two_factor_last_counter ?? 0, 1, (int) floor(now()->timestamp / 30));
        if ($counter === false) {
            throw ValidationException::withMessages(['code' => 'Invalid or already used authenticator code.']);
        }
        $user->forceFill(['two_factor_last_counter' => $counter])->save();
    }

    public function enable(Request $request, Google2FA $totp)
    {
        $data = $request->validate(['code' => 'required|digits:6']);
        $secret = $request->session()->get('mfa_setup_secret');
        abort_unless($secret && ! $request->user()->two_factor_confirmed_at, 422);
        DB::transaction(function () use ($request, $totp, $data, $secret) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->verify($user, $secret, $data['code'], $totp);
            $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        });
        $request->session()->forget('mfa_setup_secret');
        $request->session()->put('mfa_verified', true);
        Audit::record($request->user()->workspace()->id, $request->user()->id, 'identity.mfa_enabled', 'user:'.$request->user()->id);

        return to_route('dashboard')->with('message', 'Two-factor authentication enabled.');
    }

    public function challenge()
    {
        return Inertia::render('Hub/Challenge');
    }

    public function authenticate(Request $request, Google2FA $totp)
    {
        $data = $request->validate(['code' => 'required|digits:6']);
        DB::transaction(function () use ($request, $data, $totp) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->enabled && $user->two_factor_confirmed_at, 403);
            $this->verify($user, $user->two_factor_secret, $data['code'], $totp);
        });
        $request->session()->regenerate();
        $request->session()->put('mfa_verified', true);

        return to_route('dashboard');
    }
}
