<?php

namespace App\Http\Controllers;

use App\Jobs\ExecuteDeployment;
use App\Models\Deployment;
use App\Models\Environment;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Deployment\CandidateService;
use App\Services\Deployment\ConnectionFactory;
use App\Services\Deployment\DeploymentConfirmation;
use App\Services\Deployment\DeploymentException;
use App\Services\Deployment\DeploymentScheduler;
use App\Services\Deployment\GitInspector;
use App\Services\Deployment\LogSanitizer;
use App\Services\WorkerHealth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class DeploymentController extends Controller
{
    public function preflight(Request $request, Environment $environment, CandidateService $candidates)
    {
        Gate::authorize('view', $environment);
        $result = $candidates->inspect($environment, $request->user());

        return back()->with('message', 'Preflight refreshed in '.number_format($result['duration_ms'] / 1000, 1).'s. Review can reuse this result for two minutes; deployment rechecks the repository.');
    }

    public function store(Request $request, Environment $environment, CandidateService $candidates)
    {
        Gate::authorize('deploy', $environment);
        $data = $request->validate(['idempotency_key' => 'required|uuid', 'scheduled_for' => 'nullable|date|after:now|before_or_equal:'.now()->addDays(30)->toIso8601String(),
            'schedule_timezone' => 'required_with:scheduled_for|nullable|timezone']);

        return to_route('deployments.show', $candidates->create($environment, $request->user(), $data['idempotency_key'],
            isset($data['scheduled_for']) ? CarbonImmutable::parse($data['scheduled_for']) : null, $data['schedule_timezone'] ?? null));
    }

    public function show(Request $request, Deployment $deployment, Access $access)
    {
        Gate::authorize('view', $deployment);
        $deployment->load(['environment.project', 'environment.server:id,name', 'user:id,name', 'steps', 'approval']);

        return Inertia::render('Hub/Deployment', ['deployment' => $deployment, 'canConfirm' => Gate::allows('confirm', $deployment), 'canApprove' => Gate::allows('approve', $deployment), 'canRecover' => Gate::allows('deploy', $deployment->environment), 'canReconcile' => $access->admin($request->user(), $deployment->environment->project->workspace_id), 'workerOnline' => app(WorkerHealth::class)->status()['online'], 'scheduler' => app(DeploymentScheduler::class)->status()]);
    }

    public function recover(Request $request, Deployment $deployment, CandidateService $candidates)
    {
        Gate::authorize('view', $deployment);
        Gate::authorize('deploy', $deployment->environment);
        $data = $request->validate(['idempotency_key' => 'required|uuid']);

        return to_route('deployments.show', $candidates->recover($deployment, $request->user(), $data['idempotency_key']));
    }

    public function events(Deployment $deployment)
    {
        Gate::authorize('view', $deployment);

        return response()->json($deployment->load(['environment.project', 'environment.server:id,name', 'steps', 'approval']))->header('Cache-Control', 'no-store');
    }

    public function confirm(Request $request, Deployment $deployment, DeploymentConfirmation $confirmation)
    {
        Gate::authorize('confirm', $deployment);
        $data = $request->validate(['reviewed' => 'accepted', 'digest' => 'required|string|size:64',
            'scheduled_for' => 'nullable|date|after:now|before_or_equal:'.now()->addDays(30)->toIso8601String(),
            'schedule_timezone' => 'required_with:scheduled_for|nullable|timezone']);
        $confirmed = $confirmation->confirm($deployment, $request->user(), $data['digest'],
            isset($data['scheduled_for']) ? CarbonImmutable::parse($data['scheduled_for']) : null, $data['schedule_timezone'] ?? null);

        $message = match ($confirmed->state) {
            'scheduled' => 'Scheduled. The reviewed commit will enter the queue at the selected time.',
            'queued' => 'Queued. The worker will revalidate the exact candidate.',
            'awaiting_approval' => 'Awaiting independent approval.',
            default => 'This deployment request has already been handled.',
        };

        return ($confirmed->id === $deployment->id ? back() : to_route('deployments.show', $confirmed))->with('message', $message);
    }

    public function approve(Request $request, Deployment $deployment, CandidateService $candidates)
    {
        Gate::authorize('approve', $deployment);
        $request->validate(['digest' => 'required|string|size:64', 'reviewed' => 'accepted']);
        DB::transaction(function () use ($request, $deployment, $candidates) {
            $deployment = Deployment::whereKey($deployment->id)->lockForUpdate()->firstOrFail();
            if ($deployment->state !== 'awaiting_approval') {
                throw new DeploymentException('Only pending candidates can be approved.');
            }
            if (! hash_equals($deployment->digest, $request->string('digest')->toString())) {
                throw new DeploymentException('Candidate digest does not match.');
            }
            $candidates->validate($deployment);
            $deployment->approval()->create(['user_id' => $request->user()->id, 'digest' => $deployment->digest, 'outcome' => 'approved', 'expires_at' => $deployment->expires_at]);
            $deployment->update(['state' => $deployment->scheduled_for ? 'scheduled' : 'queued']);
            Audit::record($deployment->environment->project->workspace_id, $request->user()->id, 'deployment.approved', 'deployment:'.$deployment->id, ['digest' => $deployment->digest]);
            if (! $deployment->scheduled_for) {
                ExecuteDeployment::dispatch($deployment->id)->onConnection('database')->beforeCommit();
            }
        });

        return back()->with('message', $deployment->fresh()->scheduled_for ? 'Exact candidate approved and scheduled.' : 'Exact candidate approved and queued.');
    }

    public function reject(Request $request, Deployment $deployment, LogSanitizer $sanitizer)
    {
        Gate::authorize('approve', $deployment);
        $data = $request->validate(['reason' => 'required|string|max:200']);
        DB::transaction(function () use ($request, $deployment, $data, $sanitizer) {
            $deployment = Deployment::whereKey($deployment->id)->lockForUpdate()->firstOrFail();
            if ($deployment->state !== 'awaiting_approval') {
                throw new DeploymentException('Only pending candidates can be rejected.');
            }
            $deployment->approval()->create(['user_id' => $request->user()->id, 'digest' => $deployment->digest, 'outcome' => 'rejected', 'reason' => $sanitizer->clean($data['reason']), 'expires_at' => $deployment->expires_at]);
            $deployment->update(['state' => 'rejected', 'active_environment_id' => null, 'finished_at' => now()]);
            Audit::record($deployment->environment->project->workspace_id, $request->user()->id, 'deployment.rejected', 'deployment:'.$deployment->id);
        });

        return back()->with('message', 'Deployment rejected.');
    }

    public function cancel(Request $request, Deployment $deployment)
    {
        Gate::authorize('confirm', $deployment);
        DB::transaction(function () use ($request, $deployment) {
            $deployment = Deployment::whereKey($deployment->id)->lockForUpdate()->firstOrFail();
            if (in_array($deployment->state, ['awaiting_confirmation', 'awaiting_approval', 'scheduled', 'queued'], true)) {
                $deployment->update(['state' => 'cancelled', 'active_environment_id' => null, 'finished_at' => now()]);
            } elseif ($deployment->state === 'running') {
                $deployment->update(['cancel_requested' => true]);
            } else {
                throw new DeploymentException('Cannot cancel in this state.');
            }
            Audit::record($deployment->environment->project->workspace_id, $request->user()->id, 'deployment.cancel_requested', 'deployment:'.$deployment->id);
        });

        return back()->with('message', 'Cancellation recorded. Running commands stop only at a safe step boundary.');
    }

    public function reconcile(Request $request, Deployment $deployment, Access $access, ConnectionFactory $connections, GitInspector $inspector, LogSanitizer $sanitizer)
    {
        Gate::authorize('view', $deployment);
        abort_unless($access->admin($request->user(), $deployment->environment->project->workspace_id), 403);
        $data = $request->validate(['reason' => 'required|string|max:200', 'remote_processes_stopped' => 'accepted']);
        $lock = Cache::lock('environment:'.$deployment->environment_id, config('deployhub.lock_seconds'));
        if (! $lock->get()) {
            throw new DeploymentException('Execution lock is still held. Stop the worker and wait for its lease to expire.');
        }
        try {
            $deployment->refresh();
            if ($deployment->state !== 'unknown') {
                throw new DeploymentException('Only unknown deployments require reconciliation.');
            }
            $state = $inspector->inspect($connections->connect($deployment->environment->server), $deployment->environment);
            $deployment->update(['state' => 'reconciled', 'active_environment_id' => null, 'finished_at' => now()]);
            Audit::record($deployment->environment->project->workspace_id, $request->user()->id, 'deployment.reconciled', 'deployment:'.$deployment->id, ['observed_head' => $state['head'], 'reason' => $sanitizer->clean($data['reason'])]);
        } finally {
            $lock->release();
        }

        return back()->with('message', 'Repository checked and environment released. Reconciliation does not claim pipeline success.');
    }
}
