<?php

namespace App\Http\Middleware;

use App\Models\AutomationGrant;
use App\Services\AutomationAccess;
use App\Services\Deployment\DeploymentException;
use Closure;
use Illuminate\Http\Request;

class AuthenticateAutomation
{
    public function __construct(private AutomationAccess $access) {}

    public function handle(Request $request, Closure $next)
    {
        $origin = $request->header('Origin');
        $appUrl = parse_url(config('app.url'));
        $allowedOrigin = ($appUrl['scheme'] ?? 'http').'://'.($appUrl['host'] ?? 'localhost').(isset($appUrl['port']) ? ':'.$appUrl['port'] : '');
        if ($origin !== null && $origin !== $allowedOrigin) {
            return response()->json(['message' => 'Origin is not allowed.'], 403);
        }
        $token = $request->bearerToken();
        if (! is_string($token) || ! preg_match('/^dhai_[a-f0-9]{64}$/D', $token)) {
            return $this->unauthorized();
        }
        try {
            $grant = $this->access->validate(AutomationGrant::where('token_hash', hash('sha256', $token))->first());
        } catch (DeploymentException) {
            return $this->unauthorized();
        }
        $request->attributes->set('automation_grant', $grant);
        $request->setUserResolver(fn () => $grant->user);

        return $next($request)->header('Cache-Control', 'no-store');
    }

    private function unauthorized()
    {
        return response()->json(['message' => 'A valid DeployHub automation token is required.'], 401)
            ->header('WWW-Authenticate', 'Bearer realm="DeployHub"')->header('Cache-Control', 'no-store');
    }
}
