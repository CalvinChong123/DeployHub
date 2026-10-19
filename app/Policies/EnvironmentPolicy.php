<?php

namespace App\Policies;

use App\Models\Environment;
use App\Models\User;
use App\Services\Access;

class EnvironmentPolicy
{
    public function __construct(private Access $access) {}

    public function view(User $user, Environment $environment): bool
    {
        return $this->access->environment($user, $environment);
    }

    public function deploy(User $user, Environment $environment): bool
    {
        return $this->access->environment($user, $environment, 'deploy');
    }

    public function manage(User $user, Environment $environment): bool
    {
        return $this->access->environment($user, $environment, 'manage');
    }
}
