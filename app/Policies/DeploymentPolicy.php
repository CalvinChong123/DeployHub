<?php

namespace App\Policies;

use App\Models\Deployment;
use App\Models\User;
use App\Services\Access;

class DeploymentPolicy
{
    public function __construct(private Access $access) {}

    public function view(User $user, Deployment $deployment): bool
    {
        return $this->access->environment($user, $deployment->environment);
    }

    public function confirm(User $user, Deployment $deployment): bool
    {
        return $user->id === $deployment->user_id && $this->access->environment($user, $deployment->environment, 'deploy');
    }
}
