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

For AI-triggered deployments, open **Account security → AI deployment access** after MFA verification and issue a token for one explicitly authorized target. Configure the executing Codex host using [MCP setup](AI_DEPLOYMENTS.md). Tokens support all target types; production/protected targets still require a separate authorized approver. The application and database queue worker must remain running. Revoke tokens from the same page; queued/running requests recheck their grant before each next step. Password/MFA/target-policy changes invalidate existing tokens. Restart existing workers after this update.

For local Git Bash use, `./local-start.sh` starts the website and database queue worker together with Laragon PHP 8.2.21. It requires Node.js and dependencies installed with `npm ci`; Ctrl+C stops both processes, and if either exits, the other is stopped. Run `php artisan schedule:work` separately for scheduled deployments.

To manage the worker and scheduler separately, start these commands in separate terminals in the project directory:

```powershell
php artisan queue:work database --queue=default --sleep=2 --tries=1 --timeout=960
php artisan schedule:work
```

Idle worker heartbeats expire after 90 seconds; running command heartbeats are considered recent for 150 seconds. The dashboard reports recent evidence, not an infallible process monitor. Queued requests remain queued when the worker is absent and are revalidated when a worker resumes.

The scheduler dispatches due deployments and runs candidate/crash reconciliation every minute, and prunes old step output daily. The deployment review warns when its dispatcher heartbeat is absent or older than 90 seconds, including a freshly started scheduler before its first minute tick. Deployment/audit metadata is retained. `DEPLOYHUB_LOG_RETENTION_DAYS` defaults to 30.

Click **Prepare deployment →** on the project to review the exact commit and workflow. The same confirmation form offers **Deploy now** or **Schedule**; selecting Schedule reveals a future browser-local date/time input, limited to 30 days. Review the timing, check the review box and click **Schedule deployment**, or **Request independent approval** for a protected/production target. Choosing a time uses the existing review without another SSH check. The source preview remains cancelled in history, and its replacement freezes the same commit/scripts plus time. Another authorized member must approve protected/production plans before their time. Find plans and their dates in the existing Deployments tab or Deployment activity. The plan remains `scheduled` without blocking other deployment requests. At the due time, the scheduler rechecks authorization/configuration, reserves the target and queues the job. The worker performs fresh repository checks. Changed checkout/base, branch tip, pipeline, credential version or grants stops the plan; it never substitutes newer code.

Keep the DeployHub machine awake, MySQL available and both background commands running. Dispatch has minute granularity and can wait for another active deployment within a one-hour execution window. A fully approved plan missed while offline can run within that hour; after it, the plan expires. Unconfirmed/unapproved plans expire at their chosen time. Open the scheduled deployment record to cancel before execution. Recurring deployment schedules are not implemented.

After changing application worker code, run `php artisan queue:restart`; supervised workers restart automatically, while manually managed workers need the queue command started again. Restart `schedule:work` after application updates as well.

The primary database queue is an intentional MVP choice: reservation and job creation share a transaction. Run the worker on the `database` connection even if another unrelated queue driver is configured. Keep retry-after at least 1,200 seconds, greater than the worker timeout. Windows lacks normal Unix process timeout behavior; command timeouts and the executor budget still apply, and orphaned jobs are handled conservatively. On Linux, supervise worker/scheduler processes with an appropriate service manager.

## Registering a target

1. Use a dedicated, restricted deployment account on a disposable staging host first. For cPanel, confirm SSH/shell access with the provider.
2. Register its fixed hostname/port, SSH account, dedicated private key and optional passphrase. A saved key cannot be retrieved through UI/API.
3. Discover its host fingerprint. For a server without a saved fingerprint, choose **Trust on first use**, explicitly accept the displayed host/port and fingerprint, then click **Trust & save fingerprint**. This accepts the first connection without independent identity verification. Alternatively, choose **Verify with a trusted source**, compare with a trusted provider/console source, and click **Pin verified fingerprint**. Both choices pin the key for future connections; discovery alone does not save trust. Existing fingerprints can only be replaced after independent verification. Discovery expires after ten minutes and is invalidated by changes to server configuration or saved trust.
4. Test SSH authentication. This does not claim Git readiness.
5. Register the exact origin URL, canonical checkout root and fixed branch. Do not reuse a physical checkout across environments or register a host under multiple aliases. Production is an independent target directory and always requires another approver.
6. Select supported commands based on target capabilities. Noninteractive PATH must resolve Git, PHP and any selected Composer/cURL commands correctly. Composer scripts/plugins are disabled; app-specific package discovery/build steps are not silently executed.
7. Assign member access for each environment. A viewer cannot deploy or approve; an admin also needs explicit deploy/approve grants. The requester cannot approve their own protected candidate.
8. Click **Check repository**, or **Prepare deployment →** to inspect as needed. Review incoming commits and the exact target, confirm, and inspect final step results. A selected HTTPS health endpoint must verify TLS and return 2xx without redirects.

Git authentication to origin uses the deployment account's already configured trusted credentials. This application does not manage repository tokens. The repository, its Git configuration and its artisan code must be trusted. External manual operations do not acquire DeployHub's lock; coordinate exclusive ownership of the checkout during a deployment.

## Failure response

| State | Required response |
|---|---|
| Missing host fingerprint | Discover the fingerprint and explicitly choose first-use trust or independent verification before testing authentication. |
| Changed host key | Connection is blocked. Independently investigate and reverify the replacement fingerprint; first-use trust cannot replace a saved key. |
| Local repository changes | Preview lists them without blocking. Git preserves unrelated changes and rejects updates that overwrite them. Optional Pre Hard reset discards tracked changes; it does not perform blanket untracked cleanup. Enabled Pre hooks freeze content and stop if it changes after review. Retained local changes mean the working tree may differ from the target commit. |
| Detached, mismatched or divergent repository | Restore the registered branch/origin and fast-forward ancestry before creating a fresh preview. |
| Hook failure | Review its named console step and completed commands. Later steps stop. Pre hooks may have changed files even if no merge occurred; Post hook failures retain updated code. Timeouts/lost connections require reconciliation. Each hook starts in the repository directory in a separate `sh -e` session. |
| Slow preflight or repeated review | Inspection batches reduce a normal check from eleven remote execs to two. Enabled Pre hooks add content-hash batches. Review reuses a successful configuration-matching inspection for 120 seconds; explicit Refresh and recovery bypass reuse. The page reports inspection duration. Host login and Git fetch latency still depend on the remote environment. |
| Changed target or configuration | If a real configuration change is reported, review the named changed fields and prepare a fresh candidate. JSON object key order alone no longer invalidates candidates. Cancel/expire a superseded request as needed. Never replace its SHA with the latest origin tip. |
| Queued, worker offline | Start the worker and scheduler; the exact candidate must still be unexpired and authorized. |
| Scheduled, time reached | Check that `schedule:work` and the queue worker are running. Another active reservation can delay dispatch. Open its record from Deployments or Deployment activity and inspect for expired approval, changed configuration/grants or stale repository state. |
| Failed after code update | Review completed steps and resolve the failure. Use **Prepare recovery preview** to review the current pipeline at the same commit. Recovery reruns all selected steps and enabled Pre/Post hooks without a Git merge and requires fresh protected-environment approval. HEAD and fetched origin must still equal the failed target; if origin advances, review a normal new deployment. Code and data are not rolled back. |
| Unknown | Stop the relevant worker, verify remote processes have stopped, inspect remote code/application state and wait for any execution lock lease to expire. Use administrator reconciliation with a reason. |
| Reconciled | Quarantine was released after inspection. This does not assert pipeline success. Create a fresh candidate if further deployment is needed. |

Manual maintenance commands:

```powershell
php artisan deployhub:reconcile
php artisan deployhub:dispatch-scheduled
php artisan deployhub:prune-logs
php artisan deployhub:reset-mfa member@example.com --reason="Identity verified; authenticator lost"
```

MFA recovery requires local administrative access and interactive confirmation. It clears enrollment and deletes the member's database sessions. Privileged actions remain blocked until reenrollment.

Approval/activity lists and environment history include pagination controls. Activity search and state filters operate across the full authorized history. Pending approvals are filtered for expiry before pagination, independent of scheduler execution.

Each project registers one target. Copy project prefills settings for a new independent project; select another directory or server, as saving never clones remote files. The separation migration retains existing permissions/history and enables previously selected actions inside Pre/Post workflow. Deleting a project archives only its registration and retains authorized Deployment activity/logs. Active reservations and repository operations block deletion. Remote files are untouched. Archived server references prevent deleting a server needed by history. Changing servers still requires explicit access transfer for affected viewers and preserves their existing rights.

Project lists show the latest admitted deployment and cached incoming count. A project's **Last successful deployment** is a historical result; **Last inspected HEAD / fetched origin** is cached repository evidence, not a live health check. A newer failed deployment may have changed code. Expand **Repository details** for path/inspection metadata and the administrator's **Copy project** link. In **Deployment progress**, phase status derives from recorded steps and durations from actual timestamps. Successful output defaults collapsed; failed/unknown output opens. Output appears after each command completes, rather than as a raw SSH stream. Unknown remains quarantined and must be reconciled before recovery.

Pre/Post workflow entries expose checkbox, name and sorting controls in compact rows. Expand **View command** or **Edit Shell script** when needed; adding a custom hook opens its editor. Phase is chosen by the group where it is added. Reviewed script details also default collapsed.

## Shared hosting and recovery prerequisites

Before any team/production pilot, update PHP to a supported patch, configure trusted HTTPS/VPN access and secure cookies (`SESSION_SECURE_COOKIE=true`), configure a real mail transport for password resets, supervise workers, and test with an actual disposable SSH host. Do not expose Laragon directly to the Internet.

Back up the database and its encryption key separately, restrict filesystem/database access, and test restoration on an isolated installation. Existing encrypted credentials cannot be decrypted if `APP_KEY` is lost or replaced. Keep credentials out of source control and remove unneeded initial-login files. Rotating a target key increments its credential version and invalidates previews; changing a peer clears its fingerprint.

Automatic database migrations, rollback and release/symlink management are absent. Administrators can explicitly add migration or maintenance commands as reviewed custom hooks; these run with the deployment account permissions and have no automatic rollback. Audit entries are append-only in application code; use restricted database permissions and protected backups to prevent administrative tampering.
