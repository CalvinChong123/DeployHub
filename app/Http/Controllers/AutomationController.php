<?php

namespace App\Http\Controllers;

use App\Models\AutomationGrant;
use App\Models\Environment;
use App\Services\Audit;
use App\Services\AutomationAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AutomationController extends Controller
{
    public function index(Request $request, AutomationAccess $access)
    {
        $user = $request->user();
        $workspace = $user->workspace();
        $targets = Environment::with('project', 'server')->whereHas('project', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->get()
            ->filter(fn ($environment) => $access->eligible($user, $environment))
            ->map(fn ($environment) => ['id' => $environment->id, 'name' => $environment->name,
                'project' => $environment->project->name, 'branch' => $environment->branch])->values();
        $grants = AutomationGrant::with('environments.project')->where('user_id', $user->id)->where('workspace_id', $workspace->id)
            ->latest('id')->limit(50)->get()->map(fn ($grant) => ['id' => $grant->id, 'name' => $grant->name,
                'targets' => $grant->environments->map(fn ($environment) => ['id' => $environment->id,
                    'label' => $environment->project->name.' / '.$environment->name])->values(),
                'expires_at' => $grant->expires_at->toIso8601String(), 'revoked_at' => $grant->revoked_at?->toIso8601String(),
                'expired' => $grant->expires_at->lessThanOrEqualTo(now())]);
        Inertia::encryptHistory();

        // Inertia must be able to reflash the token if an asset-version reload replaces this response.
        return Inertia::render('Hub/Automation', ['targets' => $targets, 'grants' => $grants,
            'mfaEnabled' => (bool) $user->two_factor_confirmed_at, 'issuedToken' => $request->session()->get('automation_token'),
            'mcpUrl' => url('/mcp/deployhub')])->toResponse($request)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, AutomationAccess $access)
    {
        abort_unless($request->user()->two_factor_confirmed_at && $request->session()->get('mfa_verified'), 403, 'Complete authenticator verification before granting automation access.');
        $data = $request->validate(['name' => 'required|string|max:100',
            'environment_ids' => 'required|array|min:1|max:'.AutomationAccess::MAX_TARGETS,
            'environment_ids.*' => 'required|integer|distinct',
            'days' => 'required|integer|between:1,90', 'password' => 'required|current_password']);
        $ids = $data['environment_ids'];
        $environments = Environment::whereHas('project', fn ($query) => $query->where('workspace_id', $request->user()->workspace()->id))->whereIn('id', $ids)->get();
        abort_unless($environments->count() === count($ids), 404);
        [, $token] = $access->issue($request->user(), $environments, $data['name'], (int) $data['days']);
        $request->session()->flash('automation_token', $token);

        return to_route('automation.index')->with('message', 'Deployment automation token created. Copy it now; it will not be shown again.');
    }

    public function destroy(Request $request, AutomationGrant $grant)
    {
        abort_unless($grant->user_id === $request->user()->id && $grant->workspace_id === $request->user()->workspace()->id, 404);
        if (! $grant->revoked_at) {
            $grant->update(['revoked_at' => now()]);
            Audit::record($grant->workspace_id, $request->user()->id, 'automation.revoked', 'automation:'.$grant->id);
        }

        return to_route('automation.index')->with('message', 'Automation token revoked. Queued and running deployments recheck this authorization before their next step.');
    }
}
