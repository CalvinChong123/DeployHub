<?php

namespace App\Services;

use App\Models\AutomationGrant;
use App\Models\Environment;
use App\Models\User;
use App\Services\Deployment\DeploymentException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AutomationAccess
{
    public const MAX_TARGETS = 100;

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

        // Preserve issued token fingerprints; the retired approval flag no longer gates execution.
        return hash('sha256', json_encode([$environment->id, $project->id, $project->workspace_id,
            $server->id, $server->host, $server->port, $server->username, $environment->path, $project->repository, $environment->branch,
            $environment->production, $environment->requires_approval], JSON_THROW_ON_ERROR));
    }

    public function issue(User $user, Collection $environments, string $name, int $days): array
    {
        $user = $user->fresh();
        abort_unless($environments->count() >= 1 && $environments->count() <= self::MAX_TARGETS, 422);
        $environments = $environments->map(fn (Environment $environment) => $environment->fresh(['project', 'server']))->sortBy('id')->values();
        $workspaceId = $environments->first()->project->workspace_id;
        abort_unless($user->two_factor_confirmed_at && $environments->every(fn ($environment) => $environment->project->workspace_id === $workspaceId && $this->eligible($user, $environment)),
            403, 'Enrolled MFA and explicit deployment permission for every target in the same workspace are required.');
        abort_unless($days >= 1 && $days <= 90, 422);

        return DB::transaction(function () use ($user, $environments, $workspaceId, $name, $days) {
            $token = 'dhai_'.bin2hex(random_bytes(32));
            $grant = AutomationGrant::create(['user_id' => $user->id, 'workspace_id' => $workspaceId,
                'name' => $name, 'token_hash' => hash('sha256', $token),
                'identity_fingerprint' => $this->identityFingerprint($user),
                'expires_at' => CarbonImmutable::now()->addDays($days)]);
            $grant->environments()->attach($environments->mapWithKeys(fn ($environment) => [
                $environment->id => ['target_fingerprint' => $this->targetFingerprint($environment)],
            ])->all());
            Audit::record($grant->workspace_id, $user->id, 'automation.granted', 'automation:'.$grant->id,
                ['environment_ids' => $environments->pluck('id')->all(), 'expires_at' => $grant->expires_at->toIso8601String()]);

            return [$grant, $token];
        });
    }

    public function validate(?AutomationGrant $grant, ?int $environmentId = null, ?int $userId = null): AutomationGrant
    {
        $grant = $grant?->fresh(['user', 'environments.project', 'environments.server']);
        $user = $grant?->user;
        if (! $grant || ! $user || $grant->revoked_at || $grant->expires_at->lessThanOrEqualTo(now())
            || ($userId !== null && $grant->user_id !== $userId)
            || ! $user->two_factor_confirmed_at
            || ! hash_equals($grant->identity_fingerprint, $this->identityFingerprint($user))
            || ! ($environmentId === null ? $grant->environments : $grant->environments->where('id', $environmentId))
                ->contains(fn ($environment) => $this->usableTarget($grant, $environment))) {
            throw new DeploymentException('Automation authorization is expired, revoked or no longer valid for this deployment target.');
        }

        return $grant;
    }

    public function targets(AutomationGrant $grant): Collection
    {
        return $grant->environments->filter(fn ($environment) => $this->usableTarget($grant, $environment))->values();
    }

    private function usableTarget(AutomationGrant $grant, Environment $environment): bool
    {
        return $environment->project && $environment->server && $grant->workspace_id === $environment->project->workspace_id
            && $this->eligible($grant->user, $environment)
            && hash_equals($environment->pivot->target_fingerprint, $this->targetFingerprint($environment));
    }
}
