<?php

namespace App\Services;

use App\Models\AutomationGrant;
use App\Models\Environment;
use App\Models\User;
use App\Services\Deployment\DeploymentException;
use Carbon\CarbonImmutable;

class AutomationAccess
{
    public function __construct(private Access $access) {}

    public function eligible(User $user, Environment $environment): bool
    {
        return $this->access->environment($user, $environment, 'deploy');
    }

    public function identityFingerprint(User $user): string
    {
        return hash_hmac('sha256', json_encode([$user->password, $user->two_factor_secret], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function targetFingerprint(Environment $environment): string
    {
        $project = $environment->project;
        $server = $environment->server;

        return hash('sha256', json_encode([$environment->id, $project->id, $project->workspace_id,
            $server->id, $server->host, $server->port, $server->username, $environment->path, $project->repository, $environment->branch,
            $environment->production, $environment->requires_approval], JSON_THROW_ON_ERROR));
    }

    public function issue(User $user, Environment $environment, string $name, int $days): array
    {
        $user = $user->fresh();
        $environment->refresh()->load('project', 'server');
        abort_unless($user->two_factor_confirmed_at && $this->eligible($user, $environment), 403, 'Enrolled MFA and explicit deployment permission for the target are required.');
        abort_unless($days >= 1 && $days <= 90, 422);
        $token = 'dhai_'.bin2hex(random_bytes(32));
        $grant = AutomationGrant::create(['user_id' => $user->id, 'workspace_id' => $environment->project->workspace_id,
            'environment_id' => $environment->id, 'name' => $name, 'token_hash' => hash('sha256', $token),
            'identity_fingerprint' => $this->identityFingerprint($user), 'target_fingerprint' => $this->targetFingerprint($environment),
            'expires_at' => CarbonImmutable::now()->addDays($days)]);
        Audit::record($grant->workspace_id, $user->id, 'automation.granted', 'automation:'.$grant->id,
            ['environment_id' => $environment->id, 'expires_at' => $grant->expires_at->toIso8601String()]);

        return [$grant, $token];
    }

    public function validate(?AutomationGrant $grant, ?int $environmentId = null, ?int $userId = null): AutomationGrant
    {
        $grant = $grant?->fresh(['user', 'environment.project', 'environment.server']);
        $user = $grant?->user;
        $environment = $grant?->environment;
        if (! $grant || ! $user || ! $environment || $grant->revoked_at || $grant->expires_at->lessThanOrEqualTo(now())
            || ($environmentId !== null && $grant->environment_id !== $environmentId)
            || ($userId !== null && $grant->user_id !== $userId)
            || ! $user->two_factor_confirmed_at || $grant->workspace_id !== $environment->project->workspace_id
            || ! $this->eligible($user, $environment)
            || ! hash_equals($grant->identity_fingerprint, $this->identityFingerprint($user))
            || ! hash_equals($grant->target_fingerprint, $this->targetFingerprint($environment))) {
            throw new DeploymentException('Automation authorization is expired, revoked or no longer valid for this deployment target.');
        }

        return $grant;
    }
}
