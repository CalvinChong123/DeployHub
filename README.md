# DeployHub

Self-hosted, local-first SSH deployment dashboard for existing PHP/Laravel Git repositories. Laravel 12 + Vue 3 + Inertia 2 + TypeScript; MySQL-backed sessions, locks and queue; phpseclib 3 for SSH.

Implemented: workspace identity and MFA, explicit environment permissions, encrypted SSH credentials, verified host fingerprints, project/environment registry, live Git preflight and incoming commits, immutable commit review and requester confirmation for every environment including production, queued pipelines with custom Pre/Post Shell hooks, single-time scheduled deployments, sanitized console output, deployment history, audit records and conservative crash reconciliation.

Each project has one independent deployment target. Copy project prefills a new registration. Existing multi-environment projects are separated, retaining target IDs, permissions and deployment history. Deletion removes only that registration; authorized history remains in Deployment activity and remote files are unchanged. Active deployments must finish, be cancelled or be reconciled first. Failed deployments with updated code offer a fresh same-commit recovery workflow without a Git update. Activity filters and project expiry exclusions apply before pagination.

Server onboarding offers explicit first-use fingerprint trust or independent verification. Saved fingerprints are checked on every SSH connection; changed keys remain blocked and require independent verification before replacement.

Project lists show the latest confirmed deployment or valid legacy pending request and incoming commits from the last repository check. Project pages separate the last successful deployment from inspected Git state, with repository details collapsed. Review places commit/workflow information beside the Deploy now/Schedule confirmation form. Execution shows actual phase status and elapsed time; successful output is collapsed and failed/unknown output opens for inspection. These summaries do not assert live application health.

## Open the local installation

The workspace is already initialized. The local development URL is **http://127.0.0.1:8018** (port 8000 was occupied by a separate application and was left untouched).

Initial login: `owner@deployhub.test`. Its randomly generated password is in **`storage/app/private/initial-login.txt`**. Change it after login and configure an authenticator on Security before adding hosts or deploying. No real SSH credential or target is seeded.

With Node.js and `npm ci` dependencies installed, run `./local-start.sh` in Git Bash to start the website and database queue worker together using Laragon PHP 8.2.21. Ctrl+C stops both processes. For scheduled deployments, also run `php artisan schedule:work` in a separate terminal.

Alternatively, start these commands in separate terminals if they are not already running:

```powershell
php artisan serve --host=127.0.0.1 --port=8018
php artisan queue:work database --queue=default --sleep=2 --tries=1 --timeout=960
php artisan schedule:work
```

For a fresh installation, host configuration and recovery procedures, see [Operations](docs/OPERATIONS.md).

## Development and verification

```powershell
composer install
npm ci
npm run build
npm run test:qr
npm run test:deployment-polling
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit
php vendor/bin/pint --test
npm run format:check
composer audit
npm audit
```

SQLite tests are isolated from the local application's MySQL database. Real Git integration tests create guarded, disposable directories under `storage/framework/testing`; Windows uses the installed Git Bash. Concurrency tests start two independent workers against a shared disposable SQLite database. No test contacts a real deployment server. On Linux with SQLite enabled, `php vendor/bin/phpunit` is sufficient.

Helper: `./scripts/dev.ps1 -Action serve`, `worker`, `scheduler`, `test` or `migrate`. Frontend development: `npm run dev`; PHP format: `php vendor/bin/pint`; frontend format: `npm run format`.

## Deployment contract

- AI clients can use the authenticated [DeployHub MCP tools](docs/AI_DEPLOYMENTS.md) to preview an exact pushed commit, confirm and poll results. Account security provides expiring and revocable tokens with an explicit allowlist of 1–100 targets in one workspace. New projects are not added automatically. All authorized targets, including production, enter the queue after exact-candidate confirmation without independent approval. Workers recheck each target's grant validity before each step.

- Credential material is encrypted at rest, hidden from responses and absent from job payloads. Host trust is checked before authentication.
- Roles do not imply deployment permission. Membership, project, server and environment rights all matter and are rechecked by workers.
- Normal preflight uses two framed SSH exec batches; enabled Pre hooks add batched content verification. Review reuses a successful configuration-matching result for 120 seconds, while explicit refresh/recovery and workers inspect remotely. JSON digests tolerate object-key normalization while preserving hook order and values.
- A candidate pins requester, base and target SHA, server identity, credential version, path/ref, production classification, retained legacy metadata and pipeline version. Immediate candidates expire after one hour. Scheduled candidates also pin their time and display timezone, and expire one hour after that time.
- Confirmation and database enqueue commit together. Unique reservations, a database lock, an atomic worker claim and an execution token prevent duplicate runs.
- Prepare deployment opens a single review where the requester chooses Deploy now or Schedule. Scheduling pins that already-reviewed commit and workflow to a future time up to 30 days away, without repeating SSH inspection. Timing is frozen in a new immutable candidate in the confirmation transaction; the original review remains in history as cancelled. Requester confirmation must finish before that time. Future plans do not reserve the target; the scheduler rechecks permissions/configuration and atomically reserves/enqueues due plans. The worker rechecks the repository. Changed base or branch tip stops execution. Keep the scheduler, queue worker, database and hosting machine running; dispatch is checked every minute, with a one-hour execution window. Plans appear in normal deployment history/activity, with no separate scheduling page.
- Git requires the exact registered origin, canonical root, configured branch and fast-forward ancestry. Unrelated local changes can remain; Git rejects updates that would overwrite them. Enabled Pre hooks freeze tracked/untracked content for review; the worker checks that content before hooks and unchanged base/tip afterward. Automatic Git hooks are disabled; the built-in merge protects ignored files.
- Selected typed Composer/cache/optimization/queue/HTTPS health steps are mandatory. Composer scripts/plugins are disabled. Final HEAD must equal the reviewed target; selected health checks require 2xx without redirects.
- Pre deploy / Post deploy combine selectable built-in options and custom hooks in displayed phase order. Hard reset (`git reset --hard HEAD`) defaults disabled and discards tracked changes when selected. Composer/cache/optimization/queue/health options default to Post; existing selections are preserved. Administrators configure enabled scripts, frozen in review and run as the deployment user in separate `sh -e` sessions. Hooks can run on an up-to-date commit; recovery replays enabled hooks. Script changes invalidate previews. Store secrets in the remote environment.
- Progress is polled live. Output is bounded, sanitized and displayed after each command completes.
- Workflow options are grouped under Pre/Post without a per-entry phase selector. Commands and scripts are collapsed until opened; adding a custom hook opens its editor.
- Known failures preserve partial results. Lost connections and worker faults quarantine the target as unknown. Reconciliation checks actual repository state and records an administrator reason; it does not claim success.

## Documentation

- [Proposal review and milestone status](docs/PROPOSAL_REVIEW.md)
- [Architecture and trust boundaries](docs/ARCHITECTURE.md)
- [Installation and operational runbook](docs/OPERATIONS.md)
- [AI deployment authorization and Codex MCP setup](docs/AI_DEPLOYMENTS.md)
- [Portable MCP operating guide for AI clients](docs/MCP_AGENT_GUIDE.md)
- [Verification record](docs/VALIDATION.md)
- [AI agent entry point](AGENTS.md)
- [Original proposal](docs/DeployHub_Implementation_Proposal.md)

The actual nonproduction SSH/cPanel verification pilot remains outstanding; implementation tests do not access a real server. Production readiness is not claimed. Automatic application migrations, rollback, notifications, recurring schedules, SSO, container orchestration and public multi-tenant hosting are outside this MVP.
