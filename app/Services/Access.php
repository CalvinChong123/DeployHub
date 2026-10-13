<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\EnvironmentPermission;
use App\Models\Project;
use App\Models\User;

class Access
{
    public function role(User $user, int $workspaceId): ?string
    {
        if (! $user->enabled) {
            return null;
        }

        return $user->workspaces()->where('workspaces.id', $workspaceId)->wherePivot('active', true)->first()?->pivot->role;
    }

    public function admin(User $user, int $workspaceId): bool
    {
        return in_array($this->role($user, $workspaceId), ['owner', 'admin'], true);
    }

    public function project(User $user, Project $project): bool
    {
        return $this->role($user, $project->workspace_id) !== null &&
            ($this->admin($user, $project->workspace_id) || $project->users()->where('users.id', $user->id)->exists());
    }

    public function environment(User $user, Environment $environment, string $action = 'view'): bool
    {
        $project = $environment->project;
        $role = $this->role($user, $project->workspace_id);
        if (! $role || ! $this->project($user, $project)) {
            return false;
        }
        if ($action !== 'view' && ($environment->trashed() || $project->trashed())) {
            return false;
        }
        if ($action === 'manage') {
            return $this->admin($user, $project->workspace_id);
        }
        if ($role === 'viewer' && $action !== 'view') {
            return false;
        }
        // Admins can observe all targets, but execution and approval always require explicit grants.
        if ($action === 'view' && $this->admin($user, $project->workspace_id)) {
            return true;
        }
        if (! $environment->server->users()->where('users.id', $user->id)->exists()) {
            return false;
        }
        $permission = EnvironmentPermission::where('environment_id', $environment->id)->where('user_id', $user->id)->first();

        return $permission?->can_view && match ($action) {
            'view' => true, 'deploy' => $permission->can_deploy, 'approve' => $permission->can_approve, default => false,
        };
    }
}
