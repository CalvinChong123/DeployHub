# Verification record

Local verification: 9 October 2026, Asia/Kuala_Lumpur.

## Recorded results

| Check | Result |
|---|---|
| Full PHP suite | **95 tests passed, 531 assertions**. Includes authentication, permissions, credential non-disclosure, TOTP, candidates, approvals, partial failure/recovery, actual Git and independent worker concurrency. |
| Recovery/listing/registry regression | **20 tests passed, 232 assertions**. Includes the 16 added tests for same-commit recovery, fresh production approval, revoked access/configuration, paginated lists, literal search, duplicate names and explicit server access transfer. |
| Real Git integration with guarded cleanup | Six integration cases pass within the full suite: exact-SHA update, dirty/divergent/detached state, advancing origin and preservation of ignored local configuration. Fixtures clean themselves successfully, including read-only Windows Git objects. |
| `npm run build` | TypeScript check and Vite production build passed. |
| `php vendor/bin/pint --test` | Passed. |
| `npm run format:check` | Passed. |
| `composer validate --no-check-publish` | Installation verification recorded a valid manifest; not rerun for these repairs. |
| `composer audit` / `npm audit` | Installation verification recorded no advisories; not rerun for these repairs. No package constraints or lockfiles changed. |
| MySQL migrations | Installation has six migrations. These repairs require no schema migration. |
| Local HTTP smoke check | Sign-in renders at loopback `http://127.0.0.1:8018`; application name/version/runtime verified. |
| Browser review | Disposable MFA sign-in; pending approval behind 50 newer records; activity filters including literal `%`; failed recovery controls; duplicate-name validation; server transfer warning; history page three preserving its tab. Selected checkbox appearance and sizing inspected; no browser console errors observed. |
| Disposable browser cleanup | Scoped review users, workspace, projects, unverified servers, deployment records and sessions removed. Real users and targets were not used. |
| Worker/scheduler | Browser displayed offline/unconfirmed heartbeat during these checks. No real deployment was queued or executed. Automated worker/concurrency checks use isolated fixtures. |
| Agent rule validation | Root `AGENTS.md` inventory matches five active `.agents/rules` files: two global and three conditional. Local Laragon/PowerShell preferences are ignored; no second active rule system exists. |
| Secret/source check | `.env` and initial-login file are ignored. No SSH key, database fixture or initial password is included in the Git file list. |

These are separate recorded runs; assertion counts must not be added together as independent coverage.

See [Repair validation](FIXES_2026-10-09.md) for the resolved findings and [Rule bootstrap report](../.agents/logs/BOOTSTRAP_REPORT.md) for skill suitability and instruction validation.

## Commands

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit --filter 'DeployHub|Registry|Worker|TwoFactor'
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit --filter DeployHubAccessTest
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit --filter RealGitDeploymentTest
npm run build
php vendor/bin/pint --test
npm run format:check
composer validate --no-check-publish
composer audit
npm audit
php artisan migrate:status
```

## Preview

The screenshot uses labelled disposable UI data, with an unverified/unknown target. It does not represent a real connected server or a completed deployment. That data was removed after review.

![Verified dashboard layout with disposable test data](ui-preview.png)

## Remaining acceptance work

Actual SSH transport, cPanel noninteractive PATH, Git origin authentication and an HTTPS health check must still be exercised on a disposable real host. No production host was contacted. Team/VPN/HTTPS deployment, mail delivery, backups/restoration and an installation-specific security go/no-go remain milestone 6 work.

Automatic approval review rejected recursive shell deletion of older Git fixture directories with “blocked by policy.” Those earlier directories remain ignored under `storage/framework/testing`. This does not affect the application or source-control contents. Newly created integration fixtures now verify successful teardown.
