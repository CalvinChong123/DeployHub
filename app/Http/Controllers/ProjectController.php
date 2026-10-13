<?php

namespace App\Http\Controllers;

use App\Models\Deployment;
use App\Models\Environment;
use App\Models\EnvironmentPermission;
use App\Models\Project;
use App\Models\Server;
use App\Services\Audit;
use App\Services\Deployment\CommandBuilder;
use App\Services\Deployment\DeploymentWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function create(Request $request)
    {
        return Inertia::render('Hub/ProjectForm', ['servers' => Server::where('workspace_id', $request->user()->workspace()->id)->orderBy('name')->get(['id', 'name', 'fingerprint']), 'initial' => null, 'defaultHooks' => DeploymentWorkflow::defaults(['cache_clear'])]);
    }

    public function copy(Request $request, Environment $environment)
    {
        Gate::authorize('manage', $environment);
        $hooks = DeploymentWorkflow::defaults($environment->pipeline, $environment->hooks ?? []);
        foreach ($hooks as &$hook) {
            $hook['id'] = (string) Str::uuid();
        }

        return Inertia::render('Hub/ProjectForm', [
            'servers' => Server::where('workspace_id', $request->user()->workspace()->id)->orderBy('name')->get(['id', 'name', 'fingerprint']),
            'defaultHooks' => [], 'initial' => ['copy_of' => $environment->id, 'name' => mb_substr($environment->project->name.' — Copy', 0, 100),
                'repository' => $environment->project->repository, 'description' => $environment->project->description ?? '',
                'environment_name' => $environment->name, 'server_id' => $environment->server_id, 'path' => '', 'branch' => $environment->branch,
                'production' => $environment->production, 'requires_approval' => $environment->requires_approval, 'pipeline' => [],
                'hooks' => $hooks, 'health_url' => $environment->health_url ?? ''],
        ]);
    }

    private function configuration(Request $request, CommandBuilder $commands, ?int $projectId = null): array
    {
        $request->validate([
            'environment_name' => [Rule::unique('environments', 'name')->where('project_id', $projectId)->ignore($request->route('environment')?->id)],
            'path' => [Rule::unique('environments', 'path')->where('server_id', $request->input('server_id'))->whereNull('deleted_at')->ignore($request->route('environment')?->id)],
        ]);
        $data = $request->validate(['environment_name' => 'required|string|max:50', 'server_id' => 'required|integer', 'path' => 'required|string|max:255', 'branch' => 'required|string|max:200', 'production' => 'required|boolean', 'requires_approval' => 'required|boolean', 'pipeline' => 'present|array|max:5', 'pipeline.*' => 'required|string|distinct|in:'.implode(',', CommandBuilder::STEPS), 'health_url' => 'nullable|string|max:255']);
        abort_unless(Server::whereKey($data['server_id'])->where('workspace_id', $request->user()->workspace()->id)->exists(), 422, 'Select a server from this workspace.');
        $hookData = $request->validate([
            'hooks' => 'sometimes|array|max:26',
            'hooks.*' => 'required|array:id,name,phase,script,enabled,type',
            'hooks.*.type' => 'sometimes|in:custom,'.implode(',', array_keys(DeploymentWorkflow::OPTIONS)),
            'hooks.*.id' => 'required|uuid|distinct',
            'hooks.*.name' => 'required|string|max:80',
            'hooks.*.phase' => 'required|in:pre,post',
            'hooks.*.script' => ['required', 'string', 'max:10000', 'not_regex:/\x00/'],
            'hooks.*.enabled' => 'required|boolean',
        ]);
        if (array_key_exists('hooks', $hookData)) {
            $data['hooks'] = array_values(array_map(fn ($hook) => [...$hook, 'script' => str_replace(["\r\n", "\r"], "\n", $hook['script']), 'enabled' => (bool) $hook['enabled']], $hookData['hooks']));
            $types = [];
            foreach ($data['hooks'] as &$hook) {
                if (isset(DeploymentWorkflow::OPTIONS[$hook['type'] ?? ''])) {
                    if (in_array($hook['type'], $types, true)) {
                        throw ValidationException::withMessages(['hooks' => 'Each default option can be added once.']);
                    }
                    $types[] = $hook['type'];
                    $option = DeploymentWorkflow::OPTIONS[$hook['type']];
                    $hook['name'] = $option['name'];
                    $hook['script'] = $option['script'];
                    if ($hook['type'] === 'health') {
                        $hook['phase'] = 'post';
                    }
                }
            }
            unset($hook);
        }
        $commands->validatePath($data['path']);
        $commands->validateBranch($data['branch']);
        if (in_array('health', $data['pipeline'], true) || collect($data['hooks'] ?? [])->contains(fn ($hook) => ($hook['type'] ?? '') === 'health' && $hook['enabled'])) {
            $commands->healthUrl($data['health_url'] ?? null);
        }
        $data['pipeline'] = array_values(array_intersect(CommandBuilder::STEPS, $data['pipeline']));
        $data['requires_approval'] = $data['production'] || $data['requires_approval'];
        $data['name'] = $data['environment_name'];
        unset($data['environment_name']);

        return $data;
    }

    private function ownGrant(Environment $env, int $user): void
    {
        $env->project->users()->syncWithoutDetaching([$user]);
        $env->server->users()->syncWithoutDetaching([$user]);
        $env->permissions()->create(['user_id' => $user, 'can_view' => true, 'can_deploy' => true, 'can_approve' => true]);
    }

    public function store(Request $request, CommandBuilder $commands)
    {
        $projectData = $request->validate(['name' => 'required|string|max:100', 'repository' => ['required', 'string', 'max:255', 'regex:~^(?:git@[A-Za-z0-9.-]+:[A-Za-z0-9_./-]+|https://[A-Za-z0-9.-]+/[A-Za-z0-9_./-]+|ssh://[A-Za-z0-9_.-]+@[A-Za-z0-9.-]+(?::[0-9]+)?/[A-Za-z0-9_./-]+)$~D'], 'description' => 'nullable|string|max:1000']);
        $envData = $this->configuration($request, $commands);
        $copy = null;
        if ($request->filled('copy_of')) {
            $request->validate(['copy_of' => 'integer']);
            $copy = Environment::findOrFail($request->integer('copy_of'));
            Gate::authorize('manage', $copy);
        }
        $env = DB::transaction(function () use ($request, $projectData, $envData, $copy) {
            $project = Project::create([...$projectData, 'workspace_id' => $request->user()->workspace()->id]);
            $env = $project->environments()->create($envData);
            $this->ownGrant($env, $request->user()->id);
            Audit::record($project->workspace_id, $request->user()->id, $copy ? 'project.copied' : 'project.created', 'project:'.$project->id, $copy ? ['source_environment_id' => $copy->id] : []);

            return $env;
        });

        return to_route('environments.show', $env)->with('message', 'Project registered. Run preflight to inspect the target.');
    }

    public function updateProject(Request $request, Project $project)
    {
        abort_unless($project->workspace_id === $request->user()->workspace()->id, 404);
        $data = $request->validate(['name' => 'required|string|max:100', 'repository' => ['required', 'string', 'max:255', 'regex:~^(?:git@[A-Za-z0-9.-]+:[A-Za-z0-9_./-]+|https://[A-Za-z0-9.-]+/[A-Za-z0-9_./-]+|ssh://[A-Za-z0-9_.-]+@[A-Za-z0-9.-]+(?::[0-9]+)?/[A-Za-z0-9_./-]+)$~D'], 'description' => 'nullable|string|max:1000']);
        DB::transaction(function () use ($request, $project, $data) {
            $project->update($data);
            $project->environments()->update(['last_preflight' => null, 'status' => 'unknown', 'checked_at' => null]);
            Audit::record($project->workspace_id, $request->user()->id, 'project.configuration_changed', 'project:'.$project->id);
        });

        return back()->with('message', 'Project saved. Refresh preflight before preparing another deployment.');
    }

    public function destroy(Request $request, Project $project)
    {
        abort_unless($project->workspace_id === $request->user()->workspace()->id, 404);
        $environment = $project->environments()->sole();
        $lock = Cache::lock('environment:'.$environment->id, config('deployhub.lock_seconds'));
        if (! $lock->get()) {
            throw ValidationException::withMessages(['project' => 'A repository operation is running. Try again after it finishes.']);
        }
        try {
            DB::transaction(function () use ($request, $project, $environment) {
                Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
                $environment = Environment::whereKey($environment->id)->lockForUpdate()->firstOrFail();
                if (Deployment::where('active_environment_id', $environment->id)->exists()) {
                    throw ValidationException::withMessages(['project' => 'Finish or cancel the active deployment, or reconcile unknown state, before deleting this project.']);
                }
                $environment->deployments()->whereIn('state', ['awaiting_confirmation', 'awaiting_approval', 'scheduled'])->update(['state' => 'expired', 'error' => 'Project registration was deleted.']);
                $environment->delete();
                $project->delete();
                Audit::record($project->workspace_id, $request->user()->id, 'project.deleted', 'project:'.$project->id, ['environment_id' => $environment->id, 'history_retained' => true]);
            });
        } finally {
            $lock->release();
        }

        return to_route('projects.index')->with('message', 'Project removed. Deployment history retained; remote files were not changed.');
    }

    public function update(Request $request, Environment $environment, CommandBuilder $commands)
    {
        Gate::authorize('manage', $environment);
        DB::transaction(function () use ($request, $environment, $commands) {
            Project::whereKey($environment->project_id)->lockForUpdate()->firstOrFail();
            $environment = Environment::whereKey($environment->id)->lockForUpdate()->firstOrFail();
            $data = $this->configuration($request, $commands, $environment->project_id);
            $transferred = [];
            if ($environment->server_id !== (int) $data['server_id']) {
                $server = Server::findOrFail($data['server_id']);
                $transferred = $environment->permissions()->where('can_view', true)->whereNotIn('user_id', $server->users()->select('users.id'))->pluck('user_id')->all();
                if ($transferred) {
                    $request->validate(['transfer_server_access' => 'required|accepted'], ['transfer_server_access.accepted' => 'Confirm access for existing environment members on the selected server.', 'transfer_server_access.required' => 'Confirm access for existing environment members on the selected server.']);
                    $server->users()->syncWithoutDetaching($transferred);
                }
            }
            $environment->update([...$data, 'pipeline_version' => $environment->pipeline_version + 1, 'last_preflight' => null, 'status' => 'unknown', 'checked_at' => null]);
            Audit::record($environment->project->workspace_id, $request->user()->id, 'environment.configuration_changed', 'environment:'.$environment->id, ['pipeline_version' => $environment->pipeline_version, 'server_access_granted_to' => $transferred]);
        });

        return back()->with('message', 'Configuration saved. Previous previews are invalidated.');
    }

    public function grant(Request $request, Environment $environment)
    {
        Gate::authorize('manage', $environment);
        $data = $request->validate(['user_id' => 'required|integer', 'can_view' => 'required|boolean', 'can_deploy' => 'required|boolean', 'can_approve' => 'required|boolean']);
        $member = $request->user()->workspace()->users()->where('users.id', $data['user_id'])->first();
        abort_unless($member, 422, 'Member is outside this workspace.');
        if ($member->pivot->role === 'viewer') {
            $data['can_deploy'] = $data['can_approve'] = false;
        }
        DB::transaction(function () use ($environment, $request, $data) {
            $environment = Environment::whereKey($environment->id)->lockForUpdate()->firstOrFail();
            EnvironmentPermission::updateOrCreate(['environment_id' => $environment->id, 'user_id' => $data['user_id']], $data);
            $environment->project->users()->syncWithoutDetaching([$data['user_id']]);
            $environment->server->users()->syncWithoutDetaching([$data['user_id']]);
            Audit::record($environment->project->workspace_id, $request->user()->id, 'environment.permission_changed', 'environment:'.$environment->id, $data);
        });

        return back()->with('message', 'Environment access updated.');
    }
}
