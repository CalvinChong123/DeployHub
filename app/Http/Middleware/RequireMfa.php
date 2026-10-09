<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireMfa
{
    public function handle(Request $request, Closure $next)
    {
        if (config('deployhub.require_mfa') && (! $request->user()->two_factor_confirmed_at || ! $request->session()->get('mfa_verified'))) {
            return redirect()->route('security')->with('message', 'Set up two-factor authentication before managing or deploying targets.');
        }

        return $next($request);
    }
}
