# DeployHub

Self-hosted, local-first SSH deployment dashboard for existing PHP/Laravel Git repositories. Laravel 12 + Vue 3 + Inertia 2 + TypeScript; MySQL-backed sessions, locks and queue; phpseclib 3 for SSH.

Implemented: workspace identity and MFA, explicit environment permissions, encrypted SSH credentials, verified host fingerprints, project/environment registry, live Git preflight and incoming commits, immutable commit review, independent production approvals, queued fixed pipelines, sanitized console output, deployment history, audit records and conservative crash reconciliation.

Failed deployments with updated code offer a same-commit recovery preview. Recovery reruns the selected pipeline after fresh checks and approval, without a Git update. Approval/activity lists and environment history are paginated; approval and activity filters apply before pagination. Environment names are unique within a project. Moving an environment to another server requires an explicit access transfer for members missing that server assignment.

## Open the local installation

The workspace is already initialized. The local development URL is **http://127.0.0.1:8018** (port 8000 was occupied by a separate application and was left untouched).

Initial login: `owner@deployhub.test`. Its randomly generated password is in **`storage/app/private/initial-login.txt`**. Change it after login and configure an authenticator on Security before adding hosts or deploying. No real SSH credential or target is seeded.

Start these commands in separate terminals if they are not already running:

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
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit
php vendor/bin/pint --test
npm run format:check
composer audit
npm audit
```

SQLite tests are isolated from the local application's MySQL database. Real Git integration tests create guarded, disposable directories under `storage/framework/testing`; Windows uses the installed Git Bash. Concurrency tests start two independent workers against a shared disposable SQLite database. No test contacts a real deployment server. On Linux with SQLite enabled, `php vendor/bin/phpunit` is sufficient.

Helper: `./scripts/dev.ps1 -Action serve`, `worker`, `test` or `migrate`. Frontend development: `npm run dev`; PHP format: `php vendor/bin/pint`; frontend format: `npm run format`.

## Deployment contract

- Credential material is encrypted at rest, hidden from responses and absent from job payloads. Host trust is checked before authentication.
- Roles do not imply deployment permission. Membership, project, server and environment rights all matter and are rechecked by workers.
- A candidate pins requester, base and target SHA, server identity, credential version, path/ref, approval policy and pipeline version. It expires after one hour.
- Confirmation and database enqueue commit together. Unique reservations, a database lock, an atomic worker claim and an execution token prevent duplicate runs.
- Git requires the exact registered origin, canonical root, configured branch, clean tracked/untracked tree and fast-forward ancestry. Hooks are disabled; ignored local files cannot be overwritten. A changed base/tip blocks execution.
- Selected typed Composer/cache/optimization/queue/HTTPS health steps are mandatory. Composer scripts/plugins are disabled. Final HEAD must equal the reviewed target; selected health checks require 2xx without redirects.
- Progress is polled live. Output is bounded, sanitized and displayed after each command completes. No terminal or raw-command input is exposed.
- Known failures preserve partial results. Lost connections and worker faults quarantine the target as unknown. Reconciliation checks actual repository state and records an administrator reason; it does not claim success.

## Documentation

- [Proposal review and milestone status](docs/PROPOSAL_REVIEW.md)
- [Architecture and trust boundaries](docs/ARCHITECTURE.md)
- [Installation and operational runbook](docs/OPERATIONS.md)
- [Verification record](docs/VALIDATION.md)
- [AI agent entry point](AGENTS.md)
- [Original proposal](docs/DeployHub_Implementation_Proposal.md)

The actual nonproduction SSH/cPanel pilot remains outstanding; no real server was accessed. Production readiness is not claimed. Migrations, rollback, notifications, schedules, SSO, container orchestration and public multi-tenant hosting are outside this MVP.
