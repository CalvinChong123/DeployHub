# DeployHub proposal review and delivery record

Reviewed 8 October 2026; implementation verified 9 October 2026 (Asia/Kuala_Lumpur).

## Assessment

The proposal has a sound MVP boundary: a deployment dashboard for trusted, existing repositories, rather than a general shell. Its most valuable requirements are explicit environment permissions, verified SSH host keys, immutable commits, independent production approval and conservative treatment of interrupted execution. These are implemented in backend policies and services, not just hidden buttons.

The document's embedded AI prompt is a suggested workflow, not a separate user instruction. The user's request was to review and implement the proposal. Work therefore proceeded incrementally through the core MVP and approval safeguards instead of stopping after scaffolding. No production server was contacted.

## Decisions and review findings

| Finding | Implemented resolution |
|---|---|
| Framework version needs evidence | PHP 8.2.21 is the active Laragon CLI, with PHP 8.4.7 also installed. Laravel 12.69.3 was selected to support the active runtime without changing global PHP configuration. [Laravel release requirements](https://laravel.com/framework/docs/12.x/releases). |
| A role alone must not grant deployment | Active workspace membership, project assignment, server assignment and explicit environment grants are checked together. Admin observation does not imply execution or approval. |
| Candidate identity was underspecified | Digest binds requester, environment, repository, host/account, pinned fingerprint, credential version, canonical path, branch, production policy, pipeline version/options, base SHA and target SHA. Eloquent prevents changes to candidate fields. Every execution revalidates the digest and live permissions. |
| Queue admission could leave a reservation without a job | Candidate reservation and database-job enqueue occur in the same primary-database transaction. Database uniqueness, an execution lock, an atomic queued-to-running claim and a worker execution token prevent duplicate execution and unrelated failure callbacks. |
| Multiple environments could share one checkout | A unique server/directory constraint prevents registration of the same checkout twice. Canonical repository roots are required; symlink aliases are blocked. Administrators must not register the same physical server under multiple aliases. |
| Git cleanliness does not include ignored configuration | `git merge --ff-only --no-overwrite-ignore` preserves ignored local files when an incoming commit starts tracking their paths. Default Git merge can overwrite them. [Git merge documentation](https://git-scm.com/docs/git-merge). |
| A successful curl command is not necessarily healthy | The selected HTTPS health check requires exactly a 2xx status, verifies TLS, discards response bodies and rejects redirects. A later mandatory failure yields a failed deployment with a `code_updated` indicator. |
| Host discovery is not verification | Discovery returns a challenge with a ten-minute expiry. An administrator must attest comparison against an independent trusted source, and the peer is rediscovered before pinning. Connections verify the host key before loading credentials or authenticating. [phpseclib connection documentation](https://phpseclib.com/docs/ssh2/connect). |
| Cancellation and crash recovery need distinct behavior | Unstarted requests can be cancelled. Running requests stop at the next command boundary and can leave partial changes. Lost connections and orphaned workers quarantine their environment as `unknown`; expiry does not silently release that quarantine. |
| Chunk redaction can leak split secrets | Step status is polled live, but command output is buffered within a strict limit and sanitized before persistence. Credential values, key blocks, common token formats and secret assignments are removed. Completed-command output is shown, not a byte-by-byte shell stream. |
| Migration approval needs a real operational policy | Migration execution is intentionally absent. The proposal makes it optional and conditional on backup/maintenance policy; no such installation-specific policy was supplied. No migration or rollback shortcut was added. |
| Composer install can execute arbitrary project scripts/plugins | Composer steps use fixed production flags with scripts/plugins disabled. Laravel artisan actions execute trusted application code; this is an explicit trust boundary, not a sandbox for hostile repositories. |
| MFA recovery and membership revocation need an operational route | Privileged actions require TOTP enrollment and verified sessions. Codes cannot be replayed. Recovery is an audited local CLI action that revokes sessions. Public registration and account deletion are unavailable. |
| Existing frontend dependencies had advisories | The scaffold was moved to Tailwind 4's Vite integration and the SSH dependency constraint was corrected. Final Composer and npm audits are clean. [Tailwind Vite integration](https://tailwindcss.com/docs/installation/using-vite). |

## Delivery status

| Proposal milestone | Evidence / status |
|---|---|
| 0 — Discovery and scaffolding | Fresh repository; Laravel/Vue/Inertia/TypeScript boot, MySQL migrations, local sign-in, production asset build and executable test suite verified. |
| 1 — Identity and RBAC | Workspace, role, assignment and environment policy checks; TOTP, session challenges, revocation, account/profile and password-reset backend tests pass. No fixed application password seeded. |
| 2 — Server vault | Encrypted key/passphrase storage, key validation, credential rotation, host discovery/pinning, peer-change invalidation, sanitized errors and non-disclosure tests pass. Actual SSH transport still needs a disposable host pilot. |
| 3 — Registry and Git readiness | Project/environment registry, typed pipeline settings, origin verification, canonical paths, fetch timestamps and commit previews. Disposable real Git tests cover clean/ahead, dirty, divergent, detached, stale origin and ignored-file preservation. |
| 4 — Deployment engine | Immutable candidates, database enqueue, locks, worker claims, safe fixed commands, progress polling, bounded sanitized logs, history, cancellation and recovery. Two independent worker processes are tested against a shared fixture database. |
| 5 — Approvals and hardening | Independent production approvals, expiry, digest checks, revocation at execution, audit UI and output retention. Approval bypass, replay, enqueue failure and stale configuration tests pass. |
| 6 — Team/production pilot | Not claimed complete. Requires actual nonproduction SSH credentials, host verification, infrastructure backups and an installation-specific go/no-go review. |

## Validation and practical limits

See `VALIDATION.md` for the final recorded commands and results, and `OPERATIONS.md` for installation and recovery.

The browser review uses a clearly labelled disposable workspace with unverified example data. That account and data are removed after review. The real local owner account remains available for first-time MFA enrollment. No SSH private key is supplied in the repository.

This is a local-first implementation, not a production certification. The installed PHP patch versions should be updated before a shared or production rollout. SSH transport and cPanel shell/PHP/Composer compatibility must be exercised against a disposable actual target. Git repositories, their configuration, artisan code and the deployment account are trusted; external cPanel/manual operations do not participate in the application's lock. Redaction is bounded and pattern-based: approved application commands must not emit arbitrary confidential data.

One active workspace is presented per user in the MVP. Pipelines are versioned per environment rather than a shared template library. Git SHA-1 repositories are supported; SHA-256 repositories, SSH agents/password authentication, automatic schema migrations, rollback, SSO, notifications, public exposure and release/symlink deployment remain outside this implementation.
