# DeployHub installation and operations

## Local installation

Requirements: PHP 8.2+ with PDO MySQL, OpenSSL, mbstring, DOM/XML, cURL and fileinfo; Composer; Node 20.19+; MySQL/MariaDB; Git. Disposable integration tests use PDO SQLite and Git Bash on Windows, or `/bin/sh` on Linux.

For a new installation:

```powershell
composer install
npm ci
Copy-Item .env.example .env
php artisan key:generate
# Create the deployhub database and configure its credentials in .env.
php artisan migrate
php artisan deployhub:install --email=you@example.com --name="Your Name" --workspace="Your Workspace"
npm run build
php artisan serve --host=127.0.0.1 --port=8018
```

`scripts/create-local-database.php` is an optional shortcut specifically for Laragon's local root account with an empty password. It creates only `deployhub`. Do not use that account for shared or production hosting. This workspace is already migrated and initialized; do not reinstall or regenerate its encryption key.

The current installation's initial owner is `owner@deployhub.test`. A randomly generated initial password is in `storage/app/private/initial-login.txt`, outside the public document root and ignored by Git. Sign in, change it on the profile page, enroll an authenticator on Security, then remove the initial-login file. Neither seeding nor public registration creates users. Administrators create additional members on Team & access and grant each environment separately.

On Security, confirm the current password, scan the enrollment QR code with a TOTP authenticator, and submit its current six-digit code to enable MFA. The QR code is generated locally in the browser and includes the DeployHub issuer and account email. Manual setup remains available under "Can't scan? Enter the setup key manually". Enrollment material is shown only while setup is pending; successful enrollment clears the pending secret and removes both setup methods from the page.

For Laragon virtual hosting, set the vhost's document root to this project's `public` directory, enable rewriting and set `APP_URL` to the chosen local hostname. Do not serve the repository root. No global PHP or Laragon vhost setting was changed by this implementation.

## Worker and scheduler

Start separate terminals in the project directory:

```powershell
php artisan queue:work database --queue=default --sleep=2 --tries=1 --timeout=960
php artisan schedule:work
```

Idle worker heartbeats expire after 90 seconds; running command heartbeats are considered recent for 150 seconds. The dashboard reports recent evidence, not an infallible process monitor. Queued requests remain queued when the worker is absent and are revalidated when a worker resumes.

The scheduler runs candidate/crash reconciliation every minute and prunes old step output daily. Deployment/audit metadata is retained. `DEPLOYHUB_LOG_RETENTION_DAYS` defaults to 30.

The primary database queue is an intentional MVP choice: reservation and job creation share a transaction. Run the worker on the `database` connection even if another unrelated queue driver is configured. Keep retry-after at least 1,200 seconds, greater than the worker timeout. Windows lacks normal Unix process timeout behavior; command timeouts and the executor budget still apply, and orphaned jobs are handled conservatively. On Linux, supervise worker/scheduler processes with an appropriate service manager.

## Registering a target

1. Use a dedicated, restricted deployment account on a disposable staging host first. For cPanel, confirm SSH/shell access with the provider.
2. Register its fixed hostname/port, SSH account, dedicated private key and optional passphrase. A saved key cannot be retrieved through UI/API.
3. Discover its host fingerprint and compare it with a trusted provider/console source. Do not treat discovery itself as proof of trust. Pin only the verified fingerprint.
4. Test SSH authentication. This does not claim Git readiness.
5. Register the exact origin URL, canonical checkout root and fixed branch. Do not reuse a physical checkout across environments or register a host under multiple aliases. Production is an independent target directory and always requires another approver.
6. Select supported commands based on target capabilities. Noninteractive PATH must resolve Git, PHP and any selected Composer/cURL commands correctly. Composer scripts/plugins are disabled; app-specific package discovery/build steps are not silently executed.
7. Assign member access for each environment. A viewer cannot deploy or approve; an admin also needs explicit deploy/approve grants. The requester cannot approve their own protected candidate.
8. Refresh preflight, review all incoming commits and the exact target, confirm, and inspect final step results. A selected HTTPS health endpoint must verify TLS and return 2xx without redirects.

Git authentication to origin uses the deployment account's already configured trusted credentials. This application does not manage repository tokens. The repository, its Git configuration and its artisan code must be trusted. External manual operations do not acquire DeployHub's lock; coordinate exclusive ownership of the checkout during a deployment.

## Failure response

| State | Required response |
|---|---|
| Unverified/changed host key | Independently investigate and explicitly reverify the fingerprint. Credentials are not sent to an unverified peer. |
| Dirty, detached, mismatched or divergent repository | Resolve manually under your team's process, then create a fresh preview. No reset, cleanup or force-pull is offered. |
| Changed target or configuration | Cancel/expire the old request and review a fresh candidate. Never replace its SHA with the latest origin tip. |
| Queued, worker offline | Start the worker and scheduler; the exact candidate must still be unexpired and authorized. |
| Failed after code update | Review completed steps and resolve the failure. Use **Prepare recovery preview** on the failed deployment to review the current pipeline at the same commit. Recovery reruns all selected steps without a Git merge and requires fresh protected-environment approval. HEAD and fetched origin must still equal the failed target; if origin advances, review a normal new deployment. Code and data are not rolled back. |
| Unknown | Stop the relevant worker, verify remote processes have stopped, inspect remote code/application state and wait for any execution lock lease to expire. Use administrator reconciliation with a reason. |
| Reconciled | Quarantine was released after inspection. This does not assert pipeline success. Create a fresh candidate if further deployment is needed. |

Manual maintenance commands:

```powershell
php artisan deployhub:reconcile
php artisan deployhub:prune-logs
php artisan deployhub:reset-mfa member@example.com --reason="Identity verified; authenticator lost"
```

MFA recovery requires local administrative access and interactive confirmation. It clears enrollment and deletes the member's database sessions. Privileged actions remain blocked until reenrollment.

Approval/activity lists and environment history include pagination controls. Activity search and state filters operate across the full authorized history. Pending approvals are filtered for expiry before pagination, independent of scheduler execution.

Environment names must be unique within their project. Changing a target server requires confirming access transfer for existing environment viewers missing the new server assignment. Their environment rights are preserved; viewers do not gain deploy/approve permission. Existing assignments on the old server remain intact because other environments may use them.

## Shared hosting and recovery prerequisites

Before any team/production pilot, update PHP to a supported patch, configure trusted HTTPS/VPN access and secure cookies (`SESSION_SECURE_COOKIE=true`), configure a real mail transport for password resets, supervise workers, and test with an actual disposable SSH host. Do not expose Laragon directly to the Internet.

Back up the database and its encryption key separately, restrict filesystem/database access, and test restoration on an isolated installation. Existing encrypted credentials cannot be decrypted if `APP_KEY` is lost or replaced. Keep credentials out of source control and remove unneeded initial-login files. Rotating a target key increments its credential version and invalidates previews; changing a peer clears its fingerprint.

Automatic migration, rollback, release/symlink management and arbitrary command execution are deliberately absent. A later migration feature needs explicit backup/maintenance/approval design. Audit entries are append-only in application code; use restricted database permissions and protected backups to prevent administrative tampering.
