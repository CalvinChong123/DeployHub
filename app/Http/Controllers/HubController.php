<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Services\Access;
use App\Services\WorkerHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class HubController extends Controller
{
    public function index(Request $request, Access $access)
    {
        $workspace = $request->user()->workspace();
        $projects = Project::where('workspace_id', $workspace->id)->with('environments.server')->orderBy('name')->get()->filter(fn ($p) => $access->project($request->user(), $p))->values();
        foreach ($projects as $project) {
            $project->setRelation('environments', $project->environments->filter(fn ($e) => $access->environment($request->user(), $e))->values());
            foreach ($project->environments as $env) {
                $env->setAttribute('can_deploy', $access->environment($request->user(), $env, 'deploy'));
                $env->setRelation('server', new Server($env->server->only(['id', 'name', 'status', 'checked_at'])));
            }
        }
        $ids = $projects->flatMap(fn ($p) => $p->environments->pluck('id'));
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'state' => ['nullable', Rule::in(['all', 'awaiting_confirmation', 'awaiting_approval', 'queued', 'running', 'succeeded', 'failed', 'unknown', 'expired', 'rejected', 'cancelled', 'reconciled'])]]);
        $section = $request->route()->getName();
        $deployments = Deployment::whereIn('environment_id', $ids)->with(['user:id,name', 'environment.project:id,name', 'environment.server:id,name']);
        if ($section === 'approvals') {
            $deployments->where('state', 'awaiting_approval')->where('expires_at', '>', now());
        }
        if (in_array($section, ['activity', 'approvals'], true)) {
            if (($filters['state'] ?? 'all') !== 'all') {
                $deployments->where('state', $filters['state']);
            }
            $search = $filters['q'] ?? '';
            if ($search !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
                $deployments->where(function ($query) use ($pattern) {
                    $query->whereRaw("target_sha LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereHas('user', fn ($user) => $user->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]))
                        ->orWhereHas('environment.project', fn ($project) => $project->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                });
            }
        }

        return Inertia::render('Hub/Index', ['section' => $section, 'projects' => $projects, 'filters' => ['q' => $filters['q'] ?? '', 'state' => $filters['state'] ?? 'all'],
            'deployments' => $deployments->latest()->orderByDesc('id')->paginate(50)->withQueryString(),
            'worker' => app(WorkerHealth::class)->status(),
            'stats' => ['projects' => $projects->count(), 'environments' => $ids->count(), 'deployments' => Deployment::whereIn('environment_id', $ids)->where('created_at', '>=', now()->subDays(30))->count(), 'approvals' => Deployment::whereIn('environment_id', $ids)->where('state', 'awaiting_approval')->where('expires_at', '>', now())->count()]]);
    }

    public function environment(Request $request, Environment $environment, Access $access)
    {
        Gate::authorize('view', $environment);
        $environment->load('project', 'server');
        $canDeploy = $access->environment($request->user(), $environment, 'deploy');
        $manage = $access->admin($request->user(), $environment->project->workspace_id);
        $environment->setRelation('server', new Server($environment->server->only(['id', 'name', 'status', 'checked_at'])));

        $servers = $manage ? Server::where('workspace_id', $environment->project->workspace_id)->with('users:id')->get(['id', 'name']) : collect();

        return Inertia::render('Hub/Environment', ['environment' => $environment, 'canDeploy' => $canDeploy, 'canManage' => $manage,
            'members' => $manage ? $request->user()->workspace()->users()->get(['users.id', 'users.name', 'users.email']) : [],
            'servers' => $servers->map(fn ($server) => $server->only(['id', 'name'])),
            'serverAccess' => $servers->mapWithKeys(fn ($server) => [$server->id => $server->users->pluck('id')]),
            'permissions' => $manage ? $environment->permissions()->get() : [], 'deployments' => $environment->deployments()->with('user:id,name')->latest()->orderByDesc('id')->paginate(20)->withQueryString()]);
    }

    public function audit(Request $request)
    {
        return Inertia::render('Hub/Audit', ['entries' => AuditLog::where('workspace_id', $request->user()->workspace()->id)->with('user:id,name')->latest('id')->paginate(50)]);
    }
}
