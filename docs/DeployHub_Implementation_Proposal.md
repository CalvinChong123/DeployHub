# DeployHub — Product & Implementation Proposal

**Version:** 1.0  
**Date:** 2026-10-08  
**Status:** Proposed / ready for AI-assisted implementation  
**Primary environment:** Windows + Laragon (local-first)  
**Target deployment:** Existing cPanel SSH and Linux/VPS hosts  
**Primary users:** Owner/Admin, Developer, Viewer

---

## 1. Executive summary

DeployHub is a **self-hosted, multi-user, agentless SSH deployment manager** for existing Git-based PHP/Laravel applications on cPanel, VPS, and Linux servers. Administrators register SSH connections and map projects to remote directories and Git branches. Authorized developers can inspect deployment readiness, review incoming Git commits, execute approved deployment pipelines, watch sanitized live logs, and inspect an audit trail—**without receiving or seeing SSH private keys**.

The first version runs locally on **Windows + Laragon**, at a hostname such as `deployhub.test`. Other developers may later access it over a controlled LAN or private VPN, with production hosting on a VPS as a subsequent stage.

**Primary success measure:** A developer with the correct permission deploys a registered staging project using the dashboard—connection check → preflight → commit preview → confirmation → deploy → health check → history—without opening cPanel Terminal.

### Explicit boundaries

- This is **not** a generic browser SSH terminal, server administration panel, PaaS, container orchestrator, or CI/CD replacement.
- No unrestricted shell commands, no arbitrary command strings entered by developers, no SSH private-key download.
- No unattended automatic production deployments in MVP.
- No promise of automatic rollback for schema/data migrations or externally shared files.
- No need to install agents on target servers.

---

## 2. Product goals and user stories

### Goals

1. Manage multiple workspaces (schema-ready; one workspace in MVP), servers, projects, and staging/production environments.
2. Share project access with developers using least-privilege, environment-specific permissions.
3. Provide safe, observable Git-based deployments using SSH and fixed, allowlisted pipeline steps.
4. Capture every deployment's initiator, target commit, checks, steps, output, duration, result, and approval.
5. Keep local development simple, portable, and secure for a later Linux/VPS rollout.

### Representative user stories

- **Admin:** I can register a host and its SSH key, verify its host fingerprint, create a project, set its remote path and fixed branch, and authorize specific developers.
- **Developer:** I can see only my authorized projects, inspect incoming commits, deploy to permitted environments, and read the relevant sanitized logs.
- **Production approver:** I can review the exact immutable deployment candidate and approve or reject a request without having to share server credentials.
- **Viewer:** I can observe statuses and sanitized deployment history of explicitly shared projects but cannot run jobs or see credentials.
- **Admin:** I can revoke access and rotate credentials without altering the whole application's deployment workflow.

---

## 3. UI/UX concept and information architecture

### Visual direction

- Developer-tool aesthetic: clean, compact, polished dark/light themes; left sidebar; information-dense tables; clear status pills; monospaced paths/commit hashes/logs.
- Accent: cool indigo/blue; success green, warnings amber, failures red. Never communicate status by color alone.
- Desktop-first responsive design, usable on tablet; mobile limited to read-only/status/approval if specifically implemented.
- Fast navigation, clear breadcrumbs, keyboard-friendly forms, accessible focus states and error messages.
- Use server-side authorization for every screen/action; the UI merely reflects permissions.

### Navigation

```text
Workspace switcher
├─ Overview
├─ Projects
│  └─ [Project]
│     ├─ Overview
│     ├─ Environments (Staging / Production)
│     ├─ Deployments
│     ├─ Pipeline (admin only)
│     └─ Access (admin only)
├─ Servers (admin; metadata selectively visible to others)
├─ Deployment Activity
├─ Approvals
├─ Team & Permissions (admin)
├─ Audit Log (admin)
└─ Settings / My Profile / 2FA
```

### Screen A — Workspace Overview

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ DeployHub      [Workspace: ETC ▾]           ⌕ Search         [Profile ▾]    │
├───────────────────┬──────────────────────────────────────────────────────────┤
│ ▣ Overview        │ Overview                           [+ Add Project]*      │
│ ◫ Projects        │                                          *Admins only     │
│ ▤ Servers         │ ┌──────────┐ ┌──────────┐ ┌───────────┐ ┌────────────┐    │
│ ⇅ Deployments     │ │ Projects │ │ Servers  │ │ Deploys   │ │ Needs      │    │
│ ✓ Approvals       │ │   12     │ │    3     │ │ 48 / 30d  │ │ Approval 2 │    │
│ ♙ Team & Access   │ └──────────┘ └──────────┘ └───────────┘ └────────────┘    │
│ ⚙ Settings        │                                                          │
│                   │ Your Projects                                             │
│                   │ Project        Env         Status         Action          │
│                   │ RUMA           Production  Ready          [Review →]      │
│                   │ Succeo HQ      Production  Attention      [Review →]      │
│                   │ ETC Website    Staging     Ready          [Review →]      │
│                   │                                                          │
│                   │ Recent Deployments: user / project / target SHA / state  │
└───────────────────┴──────────────────────────────────────────────────────────┘
```

**Interactions:** project search/filter; role-scoped “Add Project”; connection/last-check timestamps; badges distinguish `ready`, `needs attention`, `running`, `failed`, `unknown`; no fabricated “connected” state without a recent test.

### Screen B — Servers / Add SSH Server

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Servers                                     [+ Register Server]              │
│ Name          Host                   SSH user       Last Check   Projects    │
│ cPanel-01     [masked hostname]      deployer       Verified     4           │
│ VPS-01        [masked hostname]      deploy         Unknown      7           │
├──────────────────────────────────────────────────────────────────────────────┤
│ Register Server                                                             │
│ Name             [________________________]                                  │
│ Host             [________________________]    Port [22____]                 │
│ Username         [________________________]                                  │
│ Authentication   (•) SSH private key     ( ) other [not in MVP]              │
│ Private key      [Secure input / upload — not echoed back]                    │
│ Passphrase       [*********************] [optional]                          │
│ Host fingerprint [discovered fingerprint + explicit verify]                  │
│                                                                              │
│ [Test Connection]         [Save]                                              │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Safety UX:** server verification explicitly shows SSH host-key fingerprint and must not silently accept changed keys. Credentials remain encrypted at rest and never returned after saving. Distinguish connection test, authentication, repository access, and Git status; do not conflate them.

### Screen C — Project / Environment Details

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Projects / ETC Website                          [Staging ▾] [Request Deploy] │
│                                                                              │
│ Branch: staging       Current SHA: 43e349b      Origin SHA: 5f90b56         │
│ Server: cPanel-01     Path: /home/account/site   Last deployed: 2h ago       │
│                                                                              │
│ [Overview] [Deployments] [Pipeline]* [Access]*                               │
│                                                                              │
│ Git Status:  ✓ working tree clean                                            │
│              ✓ branch matches configuration                                  │
│              ✓ origin/staging is ahead by 1 commit                           │
│                                                                              │
│ Incoming Commits                                                             │
│ ● 5f90b56  Refactor: animation enhancement        by developer              │
│                                                                              │
│ [Refresh status]                                    [Review Deployment →]    │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Crucial distinction:** never present the remote `origin/*` as current until a controlled `git fetch` completes. Show fetch time and commit IDs. When HEAD is detached, the tree is dirty, branches diverge, or the configured branch doesn't match, preflight blocks by default.

### Screen D — Deployment Review and Confirmation

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Review deployment                                           Step 2 of 3     │
│ ETC Website / Staging / cPanel-01                                             │
│ Current HEAD       43e349b                                                │
│ Target (full SHA)  5f90b56...                                              │
│ Branch             staging                                                  │
│ Change             1 commit; fast-forward only                              │
│ Status             ✓ clean tree ✓ matching branch ✓ reachable target        │
│                                                                              │
│ Planned steps: fetch → merge --ff-only target → cache clear → health check  │
│                                                                              │
│ [ ] I reviewed the exact target commit and environment.                     │
│ [Cancel]                                          [Deploy to Staging →]      │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Safety contract:** reviewed SHA is frozen in the deployment request. Revalidate that exact SHA and repository state under a project lock immediately before execution. If source state has changed, **stop and request a fresh preview** rather than silently deploying newer code. Do not use a stale preflight result.

### Screen E — Live Deployment Console

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Deployment #DEP-000128    ● Running             [Back to Project]            │
│ Requested by: developer  Target: 5f90b56...    Started: 14:32             │
│                                                                              │
│ ✓ Authenticate SSH                            0.7s                          │
│ ✓ Validate target path/repository              0.3s                          │
│ ✓ Verify Git preflight + pinned SHA            1.4s                          │
│ ✓ Fetch / fast-forward                         1.3s                          │
│ ◌ Clear Laravel cache                          running                       │
│ ○ Health check                                 pending                       │
│                                                                              │
│ Live output (sanitized, scrollable, copyable):                                │
│ > git fetch origin [redacted]                                               │
│ > git merge --ff-only 5f90b56...                                            │
│   Updating 43e349b..5f90b56                                                │
│   Fast-forward ...                                                          │
│                                                                              │
│ Final state: only SUCCESS if all mandatory stages and checks passed.         │
└──────────────────────────────────────────────────────────────────────────────┘
```

Logs must stream only to authorized viewers, mask secrets, be length-limited, and avoid revealing command input containing tokens/keys. Avoid exposing a direct arbitrary-command prompt.

### Screen F — Approvals / Team Access

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Production Approval Requests                                                │
│ Project       Environment   Requested by    Target SHA     Action           │
│ Succeo HQ     Production    Dev A           abc123...      [Review]         │
│                                                                              │
│ Review: target commit, preflight summary, approved pipeline, requester       │
│ [Reject + reason]  [Approve exact deployment candidate]                      │
│                                                                              │
│ Project Access — RUMA / Production                                           │
│ Member          View     Deploy     Approve     Manage                       │
│ Dev A            ✓         —          —          —                         │
│ Dev B            ✓         ✓          —          —                         │
│ Team Lead        ✓         ✓          ✓          —                         │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Approval policy:** enabled for production by default. Prevent self-approval for production when approval is required. Approval ties to an immutable target SHA + environment + pipeline version + expiry; changes invalidate it. Revalidate the state on execution.

### Additional UI states

- Empty state (no servers, no projects) with clear onboarding CTA.
- Network/SSH failure with actionable diagnostics and safe retry.
- Blocked preflight with exact reason, never a dangerous automatic reset.
- Running/queued/cancel requested/completed/failed/unknown statuses.
- Loading, permission denied, stale data, worker offline, SSH host-key changed, and approval expired.
- Responsive tables that become stacked cards at smaller widths.

---

## 4. Permission model

Use Laravel Policies/Gates for every action. A workspace membership alone does not grant project deployment access. Environment rights are explicit.

| Capability | Workspace owner/admin | Developer | Viewer |
|---|---|---|---|
| Register/rotate SSH credentials | Yes | No | No |
| Manage members and access | Yes | No | No |
| View assigned project and sanitized logs | Yes | If assigned | If assigned |
| Review incoming commits | Yes | If assigned | If assigned |
| Deploy staging | Yes, if assigned by policy | Explicit grant | No |
| Deploy production | Subject to production safeguards | Explicit grant + approval where configured | No |
| Approve production | Explicit approver privilege | Only if explicitly granted, no self-approval | No |
| Edit pipeline / environment | Admin only | No | No |
| Read SSH keys, passphrases or secret values | Never through UI/API | Never | Never |

**Role is not sufficient:** server access, project membership, environment action permission, and approval policy must all pass server-side.

---

## 5. Proposed architecture

```text
Users (local browser / trusted LAN / private VPN)
               │
         HTTPS when shared
               │
 Laravel + Inertia + Vue 3 dashboard
               │
        ┌──────┴─────────┐
        │ Auth / RBAC    │ Project + deployment API
        └──────┬─────────┘
               │
    Database + encrypted secret references
               │
    Queue / worker / project execution lock
               │
    SSH execution service (outbound only)
               │
   cPanel SSH hosts ── Linux VPS ── other SSH hosts
```

### Preferred technologies

- Laravel (version chosen only after inspecting project's Composer/toolchain compatibility; Laravel 13 is a candidate, not a requirement to bypass verification).
- Vue 3 + Inertia + TypeScript where compatible with the selected Laravel starter.
- MySQL / MariaDB via Laragon.
- SSH client library: **phpseclib 3**, validate support for encrypted keys, fingerprint handling, command execution, timeouts, and output streaming before final selection.
- Laravel Jobs/Queues. A database queue can be used initially; Redis + Supervisor/systemd recommended on VPS.
- SSE or polling for real-time progress in MVP; WebSockets can be introduced only if justified.
- PHP/Laravel test suite and frontend tests. Use existing repository conventions.
- UI components: established project scaffolding (e.g. Tailwind + accessible components), not a redundant custom component framework.

### Local-first operating model

- Application served by Laragon at `deployhub.test`.
- Outbound SSH originates from the Windows host running the application.
- Local queue worker must run continuously for deployment jobs. Expose worker heartbeat/status; fail gracefully if offline.
- Other developers may access via a private, encrypted network (e.g. VPN); **do not publish a local Laragon installation directly to the public Internet**.
- If local machine sleeps, restarts, or loses network, jobs may be interrupted. Persist stage transitions and resolve orphaned/unknown jobs conservatively on restart.
- Avoid local machine-specific hardcoded filesystem paths and absolute hostnames in the application code.

---

## 6. Data model (proposed)

Use UUID/ULID or consistent IDs following repository conventions. All workspace-scoped records have workspace references and authorization-aware lookup scopes.

| Table | Important columns / purpose |
|---|---|
| `users` | identity, auth, enabled, MFA settings |
| `workspaces` | name, slug, owner reference |
| `workspace_members` | workspace, user, workspace role/status |
| `servers` | workspace, label, host, port, ssh username, host-key fingerprint, credential ref, status metadata |
| `server_credentials` | encrypted private-key material and optional encrypted passphrase; key version/rotation metadata |
| `projects` | workspace, name, repository display metadata, visibility |
| `project_environments` | project, server, environment name, absolute remote path, target branch, deployment policy |
| `environment_permissions` | user/team or scoped role, environment, allowed action |
| `pipeline_templates` | versioned, approved sequence of typed steps and validated options |
| `deployments` | environment, initiator, pinned target SHA, starting SHA, pipeline version, state, timestamps, error summary |
| `deployment_steps` | deployment, step identifier, state, started/ended, sanitized logs, exit code |
| `deployment_approvals` | deployment request, approver, outcome, reason, expiry, immutable candidate digest |
| `audit_logs` | actor, subject, action, safe metadata, timestamp, IP/user agent if configured |
| `worker_heartbeats` | worker health and last-seen timestamp (or health mechanism) |

**Rules:** never put credential plaintext, `.env` contents, arbitrary unredacted command output or raw connection secrets into audit/log columns. Index deployment queries by project/environment, state and date; use foreign keys and appropriate deletion/retention rules.

---

## 7. Secure SSH & Git execution contract

### SSH security

1. Accept only configured hosts/ports and fixed SSH users, with explicit Admin authorization.
2. Store private keys and passphrases encrypted at rest; restrict access to dedicated backend services. Protect backups and the encryption key separately.
3. Require host-key fingerprint verification at onboarding; fail closed if the fingerprint changes.
4. Never return credential material over API, reveal it in UI, log it, or embed it in job payloads. Queue a server reference and read credentials only within the worker.
5. Use restricted server accounts and minimum filesystem permissions. For cPanel, verify shell/SSH access is enabled.
6. Bound connection, command execution and overall deployment times; capture exit statuses and handle lost connections.
7. Restrict user-configured target paths to vetted absolute deployment directories; reject malformed input and shell metacharacter injection.
8. Define a safe, tested command-builder layer: no free-form command input, no unvalidated interpolation, no execution of arbitrary Git hooks/scripts beyond the agreed trust boundary.

### Git deployment preflight

- Verify host and remote directory are reachable; ensure it is a Git worktree for the configured project.
- Verify branch is the configured local branch and not detached; ensure tracked/untracked state matches the strict cleanliness policy.
- Fetch the configured remote ref; show the remote tracking tip and fetch timestamp.
- Compare current HEAD vs remote with ancestry checks. **Default: fast-forward-only; block divergence and uncommitted work.**
- Review all incoming commits, exact target SHA and planned pipeline.
- Never silently `git reset --hard`, `git clean -fd`, `git checkout` another branch or force-pull to recover.
- Acquire a distributed project/environment lock before revalidating and executing. Recheck the reviewed SHA, base state, pipeline version, approval status and authorization.
- If the source branch advanced after review, stop and request a fresh review. Never silently substitute the latest commit.
- For an approved candidate that remains valid, use explicit verified target commit via a safe noninteractive, fast-forward update. Ensure proper error handling of remote origin/authentication.
- Cherry-picking selected commits is **out of MVP**; it changes history/semantics and must be designed separately.

### Deployment pipeline (typed/allowlisted)

Suggested actions (each individually configurable and with role-scoped change permissions):

1. SSH authentication and remote path checks.
2. Repository preflight + fetch + pinned candidate validation.
3. Fast-forward-only update to the approved target SHA.
4. Optional `composer install` with approved flags, if remote environment supports it.
5. Optional Laravel `artisan optimize:clear`.
6. Optional Laravel `artisan migrate --force` **only with explicit admin opt-in, approval and maintenance/backup policy**.
7. Optional Laravel `artisan optimize` / `queue:restart`.
8. Optional approved health check with secure timeouts and result interpretation.

**Critical:** Deployment **success** requires all mandatory steps and the selected health check to pass. A Git update succeeding while a later required step fails is a **failed/partial deployment**, not a success. Retrying must be safe or explicitly guarded. Migration rollback is not guaranteed.

---

## 8. Deployment state machine

```text
DRAFT / PREVIEW
  ├─ BLOCKED (failed preflight)
  └─ AWAITING_CONFIRMATION
        ├─ AWAITING_APPROVAL (when required)
        │    ├─ REJECTED / EXPIRED
        │    └─ APPROVED
        └─ QUEUED
              └─ RUNNING
                  ├─ SUCCEEDED
                  ├─ FAILED (known stage failure)
                  └─ UNKNOWN (connection lost / crash; manual resolution)
```

- A queued candidate is immutable (server, environment, SHA, pipeline version, actor, approval digest).
- Only one running deployment per environment; enforce with a real lock and database state guard.
- At-most-one active request via idempotency token when clicking Deploy repeatedly.
- A cancellation request cannot be assumed to stop a remote command immediately; support only safe boundaries and document it.
- Store stage timestamps, exit codes, safe error messages. Running state cannot remain forever after worker crash: mark conservatively `UNKNOWN` and require reconciliation.

---

## 9. Security, privacy, and operational controls

- Authentication: secure session cookies, CSRF protection, rate limiting, password reset, MFA for all privileged accounts.
- Authorization: policies in HTTP handlers, service layer, broadcasting/log endpoints, approval actions, and job execution.
- Encryption: use framework encrypted attributes or a dedicated vault/KMS compatible design; key rotation and encrypted backups plan.
- Production safeguards: approvals from a different authorized user; explicit confirmation; target SHA immutable; default no auto-deploy.
- Redaction: avoid output of full env dumps, command lines with secrets, repository access tokens, raw SSH messages containing private data; restrict log size/retention and read access.
- Audit: immutable-style append-only action history for credentials changed, permissions changed, preview/deploy approved/rejected/executed, pipeline versions changed.
- Remote host trust: host fingerprint pinned and rotated through an audited administrative flow.
- Credential lifecycle: least-privilege deployment account, rotation, revocation, and incident response.
- Restrict outbound SSH to allowlisted hosts where practical; protect against arbitrary network target entry by untrusted roles.
- Do not equate any package, encryption, or SSH connection with a fully secure production rollout without threat-model review and testing.

---

## 10. User journeys and acceptance criteria

### Journey 1: First-time setup

1. Admin logs in and configures MFA.
2. Admin creates a workspace (or uses seeded default).
3. Admin registers a server, imports encrypted SSH key, reviews fingerprint, tests connection.
4. Admin registers Project and Staging environment with target directory and branch.
5. Admin assigns Developer deploy permissions on Staging, Viewer access on project.

**Accept:** admin sees server/project; unauthorized developer cannot read credential or create arbitrary target execution; fingerprint mismatch blocks connection.

### Journey 2: Safe staging deployment

1. Developer opens assigned staging environment and initiates preflight.
2. App fetches remote branch and shows incoming commits and pinned target SHA.
3. Developer confirms exact candidate.
4. Worker locks the environment and repeats preflight against the immutable candidate.
5. Pipeline runs, live progress appears, health check completes, history records every step.

**Accept:** target SHA equals reviewed SHA; no uncommitted state overwritten; status succeeds only when required steps succeed; other environments unaffected.

### Journey 3: Production approval

1. Developer with production request permission creates candidate.
2. Independent authorized approver reviews exact SHA, environment, pipeline version.
3. Approved job revalidates and runs; changed candidate or expired approval returns to review.

**Accept:** developer cannot self-approve; denial/expiry prevents execution; audit trail shows requester and approver.

### Journey 4: Failure cases

- Wrong SSH key → authentication error; no Git operations.
- Host fingerprint changed → hard block requiring audited re-verification.
- Repository dirty → blocked, with list of affected paths safely summarized.
- Branch mismatch/detached HEAD/divergence → blocked; no force reset.
- Remote advances between preview and execution → stale preview, must review again.
- Artisan migration fails after code update → deployment failed/partial with explicit remediation guidance.
- Queue worker offline → tasks cannot be reported as running; useful user feedback.
- Developer revoked mid-queue → re-check authorization and reject execution.
- Two users click Deploy → one effective active deployment and safe response to second user.

---

## 11. Delivery roadmap (do not implement everything in one AI prompt)

### Milestone 0 — Discovery & scaffolding

- Inspect existing repository if provided; otherwise scaffold a fresh project in the current workspace.
- Confirm PHP/Composer/Node/Laragon versions and availability, framework/starter compatibility, DB connection and queue support.
- Choose established patterns. Document architecture, decisions, dependencies and an env example without secrets.
- Set up authentication, project quality tools and test baseline.
- **Exit gate:** application boots locally, database migrates, login works and tests can run.

### Milestone 1 — Workspace + identity + RBAC

- Users, workspace memberships, role model, project/environment permissions, authenticated layouts, MFA where supported.
- Seed a development workspace with synthetic demo users/data; no real passwords or production credentials in seed files.
- Implement Policies and authorization tests first.
- **Exit gate:** developer/viewer/admin restrictions pass API tests, not merely visual tests.

### Milestone 2 — Server vault & connectivity

- Server CRUD; encrypted SSH-key storage; pin host fingerprint; test connection; SSH service abstraction with mock driver.
- Server list/detail and connection-error UI.
- **Exit gate:** host mismatch fails closed; key never appears in response/logs; SSH integration mock tests pass.

### Milestone 3 — Project registry & Git readiness

- Project/environment CRUD, server linkage, fixed path/ref, repository state checks, fetch/commit comparison and review UI.
- Parse results reliably; do not rely on English terminal output if Git plumbing/status porcelain can supply stable machine-readable data.
- **Exit gate:** clean/ahead/dirty/diverged/detached/missing-project states tested with disposable local repos.

### Milestone 4 — Deployment engine and live logs (MVP complete)

- Immutable candidate, queue job, environment lock, safe command builder, fixed typed steps, sanitized SSE/polling logs, history and result state.
- Preflight recheck under lock, worker crash/loss handling, retry safety.
- **Exit gate:** end-to-end staging deployment against an isolated test repo works; concurrent and failure tests pass.

### Milestone 5 — Production approvals + hardening

- Approval queue, independent approval, expiry/digest checks, environment-specific production policy, audit features and cleanup/retention.
- Document recovery and disaster scenarios, verify authorization across read endpoints and streamed logs.
- **Exit gate:** approval bypass, revocation, stale review, changed pipeline and replay attacks fail in tests.

### Milestone 6 — Team pilot and production readiness

- Expose local test version only on trusted LAN/VPN over secure transport; onboard test users.
- Test with disposable accounts and staging projects before any production project.
- Confirm backups, persistent worker, secret rotation, operating runbook and incident response.
- **Exit gate:** multi-user pilot succeeds; production use requires explicit go/no-go security review.

### Post-MVP (do not implement by default)

- GitHub/GitLab metadata and webhook integrations; deploy notifications.
- True release-based deployment/rollback with symlinked releases, shared storage and migration limitations.
- Scheduled deploy windows, advanced approvals, SSO, multi-tenant billing, container support, server telemetry.
- Select-commit/cherry-pick workflows; separate design due to history/conflict implications.
- General-purpose terminal is intentionally excluded unless separately threat-modeled with powerful privilege isolation.

---

## 12. Suggested API / service boundaries

These are conceptual names; align actual names with the framework's idioms and conventions.

**Services:** `SshConnectionService`, `HostKeyVerifier`, `CredentialVault`, `GitRepositoryInspector`, `DeploymentCandidateService`, `DeploymentExecutor`, `PipelineRunner`, `DeploymentLogSanitizer`, `DeploymentAuthorizationService`, `AuditLogger`.

**Jobs:** `InspectRemoteGitState`, `ExecuteDeployment`, `ReconcileUnknownDeployment`.

**Endpoints (example):**

```text
GET    /dashboard
GET    /servers
POST   /servers
POST   /servers/{server}/connection-test
GET    /projects
GET    /projects/{project}/environments/{environment}
POST   /environments/{environment}/preflight
POST   /environments/{environment}/deployment-requests
POST   /deployments/{deployment}/confirm
POST   /deployments/{deployment}/approve
POST   /deployments/{deployment}/reject
GET    /deployments/{deployment}
GET    /deployments/{deployment}/events
GET    /audit-logs
```

All endpoints require appropriate workspace/project/environment-scoped authorization; event streams must authenticate and filter exactly like ordinary read routes.

---

## 13. Test strategy

### Automated test matrix

- **Unit:** Git ancestry/status parser; candidate digest; authorization matrix; command argument escaping; log redaction; state transitions.
- **Feature:** login/MFA; tenant scoping; policy denial; server key non-disclosure; approval/revocation; immutable candidate validation; events/logs authorization.
- **Integration:** temporary local bare + working Git repositories (nonproduction), controlled SSH fixture/mock, clean/ahead/diverged/dirty/detached branches, command exit status.
- **Concurrency:** duplicate click, competing developers, worker retry, lock expiry, worker crash, lost SSH session.
- **Security:** host-key mismatch, malicious path, authorization IDOR, forged approval, replay/stale candidate, sensitive log output, secret backup handling.
- **UI:** responsive layout, forms, keyboard navigation, accessible status labels, empty/error/offline states.

### Definition of done for any milestone

- Code is implementable, builds, and passes relevant tests.
- No user-facing actions without working backend behavior.
- No placeholder success state; errors are truthful and actionable.
- Documentation/config examples updated to match implementation.
- No real server credentials committed to the repository.
- Changed files, migrations, tests run and outstanding limitations summarized.

---

## 14. AI implementation operating instructions (paste into Cursor/Codex)

> You are building **DeployHub**, a secure local-first multi-user deployment dashboard for existing cPanel SSH and Linux/VPS Git repositories. The authoritative product spec is `DeployHub_Implementation_Proposal.md`. Read the entire file before changes.
>
> **Start with Milestone 0 only.** Inspect existing repository, `composer.json`, frontend manifest, PHP versions (system vs Laragon), installed extensions, migration/test conventions and architecture. If no project exists, scaffold only after verifying toolchain compatibility. Do not assume a fixed `C:\\laragon` path. State any blockers with concrete evidence.
>
> Use Laravel + Vue/Inertia if compatible. Prefer established framework components/services and consistent English developer-facing docs. Build services, policies, migrations and tests incrementally. Implement each milestone in small verifiable changes; do not claim another milestone is complete before its exit gate passes.
>
> SSH keys are secret, host fingerprints must be pinned, and user-entered command execution is forbidden. Never substitute `git reset --hard`, force checkout or silent latest-branch deployments for the spec's strict fast-forward-only immutable-candidate flow. Keep a mock SSH executor for safe tests. Do not touch a real production server or deploy real code without explicit approval and credentials supplied through a secure channel.
>
> For each milestone: (1) state inspected repo evidence, (2) list files to create/change, (3) implement, (4) run tests, (5) report exact results and remaining risks, (6) update README and architecture notes. Ask questions only when an essential security/operational detail is genuinely missing; otherwise choose conservative documented defaults.

---

## 15. Concrete MVP demonstration script

1. Start Laragon, DB and queue worker; browse to `deployhub.test`.
2. Login as Admin; create staging workspace, register a safe test SSH host and verify fingerprint.
3. Create a test Git repository/project, set branch/path/pipeline.
4. Invite/assign Developer and Viewer; verify access boundaries.
5. Make a new commit in the test remote; Developer sees incoming commit details.
6. Review, confirm, deploy; watch each stage update with sanitized logs.
7. Verify target repository HEAD matches reviewed SHA and deployment history shows who/what/when.
8. Make remote repository dirty or diverged and confirm deployment blocks safely.
9. Request Production deployment; verify independent approval requirements and audit trail.
10. Stop the worker and simulate SSH loss; verify graceful unknown/blocked states instead of false success.

**MVP is accepted only when this script and automated safety tests pass on nonproduction fixtures.**

---

## 16. Open decisions to resolve in implementation (safe defaults)

| Decision | Suggested default |
|---|---|
| Workspace count | One active workspace in MVP, workspace-aware data design |
| Local team access | Trusted LAN/private VPN only, avoid public exposure |
| Server authentication | Encrypted SSH private key only |
| Git strategy | Fixed branch + immutable target, fast-forward only |
| Dirty worktree | Hard block; no automatic cleanup |
| Deployment commands | Admin-defined allowlisted step types; no raw free-form shell |
| Production approvals | Independent approver required by default |
| Real-time updates | Polling or SSE first; adopt WebSockets only when needed |
| Logs retention | Configurable, conservative retention and redaction |
| Rollback | Out of MVP |
| Multi-tenant SaaS | Out of MVP |

**End of proposal.**
