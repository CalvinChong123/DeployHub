<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class WorkspaceAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->enabled && $request->user()->workspace(), 403, 'Your workspace access is inactive.');
        if ($request->user()->two_factor_confirmed_at && ! $request->session()->get('mfa_verified')) {
            return redirect()->route('mfa.challenge');
        }

        return $next($request);
    }
}
