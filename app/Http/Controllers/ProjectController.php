<?php

namespace App\Http\Controllers;

use App\Models\Deployment;
use App\Models\Environment;
use App\Models\EnvironmentPermission;
use App\Models\Project;
use App\Models\Server;
use App\Services\Audit;
use App\Services\Deployment\CommandBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function create(Request $request)
    {
        return Inertia::render('Hub/ProjectForm', ['servers' => Server::where('workspace_id', $request->user()->workspace()->id)->orderBy('name')->get(['id', 'name', 'fingerprint'])]);
    }

    private function configuration(Request $request, CommandBuilder $commands, ?int $projectId = null): array
    {
        $request->validate([
            'environment_name' => [Rule::unique('environments', 'name')->where('project_id', $projectId)->ignore($request->route('environment')?->id)],
            'path' => [Rule::unique('environments', 'path')->where('server_id', $request->input('server_id'))->ignore($request->route('environment')?->id)],
        ]);
        $data = $request->validate(['environment_name' => 'required|string|max:50', 'server_id' => 'required|integer', 'path' => 'required|string|max:255', 'branch' => 'required|string|max:200', 'production' => 'required|boolean', 'requires_approval' => 'required|boolean', 'pipeline' => 'present|array|max:5', 'pipeline.*' => 'required|string|distinct|in:'.implode(',', CommandBuilder::STEPS), 'health_url' => 'nullable|string|max:255']);
        abort_unless(Server::whereKey($data['server_id'])->where('workspace_id', $request->user()->workspace()->id)->exists(), 422, 'Select a server from this workspace.');
        $commands->validatePath($data['path']);
        $commands->validateBranch($data['branch']);
        if (in_array('health', $data['pipeline'], true)) {
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
        $env = DB::transaction(function () use ($request, $projectData, $envData) {
            $project = Project::create([...$projectData, 'workspace_id' => $request->user()->workspace()->id]);
            $env = $project->environments()->create($envData);
            $this->ownGrant($env, $request->user()->id);
            Audit::record($project->workspace_id, $request->user()->id, 'project.created', 'project:'.$project->id);

            return $env;
        });

        return to_route('environments.show', $env)->with('message', 'Project registered. Run preflight to inspect the target.');
    }

    public function addEnvironment(Request $request, Project $project, CommandBuilder $commands)
    {
        abort_unless($project->workspace_id === $request->user()->workspace()->id, 404);
        $env = DB::transaction(function () use ($project, $request, $commands) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $data = $this->configuration($request, $commands, $project->id);
            $env = $project->environments()->create($data);
            $this->ownGrant($env, $request->user()->id);
            Audit::record($project->workspace_id, $request->user()->id, 'environment.created', 'environment:'.$env->id);

            return $env;
        });

        return to_route('environments.show', $env);
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
        abort_if(Deployment::whereIn('environment_id', $project->environments()->select('id'))->exists(), 422, 'Projects with deployment history are retained for audit.');
        DB::transaction(function () use ($request, $project) {
            $project->environments()->delete();
            Audit::record($project->workspace_id, $request->user()->id, 'project.deleted', 'project:'.$project->id);
            $project->delete();
        });

        return to_route('projects.index')->with('message', 'Unused project removed.');
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
