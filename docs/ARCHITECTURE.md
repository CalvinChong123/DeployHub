# DeployHub architecture

## Application boundaries

Laravel 12, Inertia 2, Vue 3 and TypeScript serve a session-authenticated dashboard. MySQL stores records, encrypted credentials, sessions, cache locks and queue jobs. phpseclib 3 is the only production outbound SSH transport. The local test transport exists under `tests/Support` and cannot be selected through application configuration or the UI.

The application presents one active workspace per user, with workspace-aware relationships throughout the schema. `Access` combines active membership, role, project assignment, server assignment and environment grants. Policies protect environment actions and every deployment/log read. HTTP middleware also checks session MFA and admin membership. Workers repeat requester and approver authorization from fresh database records.

## Data and credential handling

`ServerCredential` uses Laravel encrypted casts and hides both key and passphrase. The connection service verifies the peer fingerprint before decrypting keys or authenticating. Registration validates the key; neither returned props nor job payloads contain credential material. Credential rotation and peer changes invalidate existing deployment digests.

The candidate snapshot contains only configuration, not credentials. Its digest includes requester, exact starting and target commits, server identity, credential version, path/ref, production/approval policy and pipeline version/options. Candidate columns are immutable through Eloquent. State, timestamps, worker token, cancellation and result fields are separately mutable.

`AuditLog` rejects updates/deletes through Eloquent. Audit rows reference actor IDs and retain safe metadata; this is application-level append-only behavior, not cryptographic tamper evidence against a database administrator.

## Repository and execution lifecycle

1. A policy-authorized inspection acquires the environment lock.
2. The SSH service pins the peer, authenticates and opens a restricted command channel.
3. Git plumbing checks canonical repository root, exact origin URL, configured non-detached branch and tracked/untracked cleanliness.
4. Fetch retrieves the configured branch. Ancestry, incoming commits and full SHA-1 IDs are inspected from completed commands.
5. A deployment preview freezes configuration, commits and a one-hour expiry. No Git update occurs during preview.
6. Confirmation reserves the environment and atomically inserts a database job, or awaits independent approval. An approval queues that same candidate in the same transaction.
7. A worker acquires the lock and atomically claims `queued → running`, storing a unique execution token.
8. Authorization, digest, approval and Git state are rechecked. Each remote command records a heartbeat and checks execution ownership.
9. Git merges only the reviewed SHA with `--ff-only --no-overwrite-ignore`; hooks and local/ext Git transports are disabled.
10. Selected typed pipeline steps run in a fixed order. All are mandatory. The final HEAD must match the target, and any selected health check must return 2xx.
11. Completed step output is sanitized before saving. Polling runs every 2.5 seconds with normal session/policy protection and no-store responses.

Only one active request can reserve an environment through a unique nullable active-environment column. The same server/directory cannot be registered twice. Database locks are shared by HTTP and workers. A lock lease is 1,200 seconds; commands time out after 120 seconds; the executor uses a 900-second budget; the queue timeout is 960 seconds and retry-after is 1,200 seconds. Enqueue is deliberately tied to the primary database; Redis queue migration requires an outbox/admission design rather than just changing an environment variable.

## Failure and recovery

Known mandatory-stage failures are `failed`, with completed stages and `code_updated` preserved. Network loss or unexpected worker faults are `unknown`, retain the environment reservation and require reconciliation. A separate worker token prevents a failed duplicate job from marking another worker's execution unknown.

A failed deployment with updated code can create a separate recovery candidate. Its digest includes the source deployment ID, fresh configuration, requester and exact SHA. Fresh preflight requires HEAD and fetched origin to equal the failed target; the recovery pipeline skips fast-forward and reruns every selected typed step. Review, expiry, explicit grants, MFA, independent protected-environment approval and worker revalidation apply to recovery. The source record remains unchanged. Unknown state cannot enter recovery without the separate reconciliation process.

Approval queries filter pending unexpired records before pagination. Activity search/state filters run on the server; history pagination orders by creation time and ID. All queries remain limited to visible environments. Environment-name validation mirrors the per-project database constraint, with project row locks serializing names on create/edit. Server reassignment identifies members with view grants who lack the new server assignment, requires explicit transfer and audits the transferred IDs in the same transaction as the configuration update.

The scheduler expires unexecuted candidates and quarantines running jobs whose heartbeats exceed the lock lease. It never automatically declares remote success or releases unknown quarantine. Administrator reconciliation requires explicit process-stopped attestation, a safe repository inspection and an audit reason. It produces `reconciled`, not `succeeded`.

There is no automatic retry of a partially executed pipeline. A fresh preview is required. There is no code, database or shared-file rollback.

## Browser/UI

`HubLayout` provides responsive navigation, accessible controls, dark/light themes and role-aware navigation. Tables become labelled cards at small widths. Registry forms, approval reviews and console states reflect backend capabilities. No fabricated connection or repository readiness is displayed: new targets are unverified/unknown until actually inspected.

Step output is command-buffered rather than raw-streamed to prevent disclosure across chunk boundaries. The sanitizer limits persisted output to 32 KiB per step; SSH collection is bounded at 128 KiB per command. Health response bodies are discarded entirely.
