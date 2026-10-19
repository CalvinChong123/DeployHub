# DeployHub MCP deployment access

DeployHub exposes a Streamable HTTP MCP server at `/mcp/deployhub`, using the official `laravel/mcp` package. A Codex task can prepare an exact pushed commit, confirm the candidate and poll the existing worker's result. When a phone controls that task, the MCP connection belongs on the host executing it.

For a portable workflow to give another AI client, read [MCP guide for AI clients](MCP_AGENT_GUIDE.md). That guide covers connection selection, response handling, state interpretation and retry identity. A client must support Streamable HTTP and bearer authentication; its configuration syntax can differ from Codex.

## Authorize a target

1. Sign in to DeployHub and complete authenticator verification.
2. Open **Account security → AI deployment access** (`/automation`).
3. Select 1–100 registered targets in the active workspace for which you have explicit deployment permission, enter a name, validity of 1–90 days and your current password, then select **Create deployment token**. No target is selected by default; an invalid selection rejects the entire request.
4. The page scrolls to **Copy your new token** and focuses its token field. Select **Copy token** and store it securely before leaving or refreshing. It is returned once; only its SHA-256 hash is stored in the grant table.

DeployHub does not automatically save the token to a local file, environment variable, password manager or Codex configuration. The plaintext is temporarily flashed through your browser session for the creation response, including an Inertia asset-version reload, and is not available from the authorization record afterward. If you leave or refresh without copying it, revoke that token in **Your automation tokens** and create a replacement; the stored hash cannot recover its value.

Each token is bound to its issuing user, one workspace and an explicit set of targets. Nonproduction and production targets can share a token. All targets require current explicit deployment permission and exact-candidate confirmation. Independent approval is retired, including production; no separate approver or website approval is required.

A token can authorize several explicitly selected projects in the same workspace. New projects are never included automatically. The `environment_id` argument selects one of its currently usable targets; it cannot expand the allowlist. To change the selection, issue a new token after MFA/password confirmation. Revocation applies to the entire token. Token issuance accepts only an `environment_ids` array, including when selecting one target.

Grants always require enrolled MFA, even when the global MFA option is disabled. Creation additionally requires an MFA-verified browser session and the current password. Subsequent MCP calls use the grant instead of a browser session or another TOTP code. The token has no configuration, credential-management, arbitrary-shell, recovery or scheduling capability.

## Connect the Codex host

Store the copied token in `DEPLOYHUB_AI_TOKEN` on the host running Codex. For Windows PowerShell, this prompts without displaying the token and stores it as a user environment variable:

```powershell
$deployhubTokenInput = Read-Host 'Paste your DeployHub automation token' -AsSecureString
[Environment]::SetEnvironmentVariable('DEPLOYHUB_AI_TOKEN', [System.Net.NetworkCredential]::new('', $deployhubTokenInput).Password, 'User')
Remove-Variable deployhubTokenInput
```

Restart Codex so its new processes inherit the variable. Environment-variable storage is accessible to processes running as that OS user; do not store the token in source control, task messages or screenshots.

Add the following to the Codex host's `config.toml`. The authorization page generates the URL for that installation. This example uses the local application address:

```toml
[mcp_servers.deployhub]
url = "http://127.0.0.1:8018/mcp/deployhub"
bearer_token_env_var = "DEPLOYHUB_AI_TOKEN"
enabled_tools = ["list_deployment_targets", "prepare_deployment", "execute_deployment", "get_deployment_status"]
default_tools_approval_mode = "approve"
tool_timeout_sec = 180
```

This permits tool calls without a separate Codex tool-approval prompt, within the server's enforced grant. Managed client policies may override local configuration. Use HTTPS across a network; loopback HTTP is intended for a client and DeployHub on the same trusted host. Keep DeployHub, its database and database queue worker running. Do not expose the repository root or disable TLS verification.

For an existing installation, apply the forward migration without resetting the database:

```powershell
php artisan migrate
```

The target-scope migration moves stored target fingerprints into `automation_grant_targets` and removes the old single-target columns from `automation_grants`. It does not issue credentials, expand existing grants or rewrite deployment candidates. There is one runtime authorization model and no old request-format fallback. Run pending migrations before using the updated application and reload existing queue workers with the current code. This migration is forward-only; restoring an older schema requires a database backup with matching code.

## Multiple projects

For several projects in the same workspace, select their targets when creating one token. The single connection above can then list and deploy any currently usable target in that allowlist. Ask the AI to select the intended project, repository and branch, and pass that returned `environment_id` to preparation. Keep each deployment's UUID, ID and digest associated with its own target.

Separate tokens and stable connection aliases remain useful when you want narrower scopes, separate clients or different workspaces. Both connections can use the same DeployHub installation URL:

```toml
[mcp_servers.deployhub_shop]
url = "http://127.0.0.1:8018/mcp/deployhub"
bearer_token_env_var = "DEPLOYHUB_SHOP_TOKEN"
tool_timeout_sec = 180

[mcp_servers.deployhub_portal]
url = "http://127.0.0.1:8018/mcp/deployhub"
bearer_token_env_var = "DEPLOYHUB_PORTAL_TOKEN"
tool_timeout_sec = 180
```

Store each token using the secure-input pattern above with its corresponding variable name, then restart the client. Discover the targets on the selected connection before deployment and keep that connection for preparation, confirmation and polling. Replacing a token does not grant access to deployments created by the old token, even for the same target. Choose tool approval preferences according to your client policy; they do not replace server authorization and exact-candidate confirmation. See the [official Codex MCP configuration](https://learn.chatgpt.com/docs/extend/mcp?surface=cli).

## Tool workflow

| Tool | Required arguments | Behavior |
| --- | --- | --- |
| `list_deployment_targets` | None | Returns the currently usable targets in the token's explicit allowlist, each configured origin/branch and a compatibility `requires_independent_approval: false` field. No SSH connection. |
| `prepare_deployment` | `environment_id`, `expected_sha`, `idempotency_key` | Fresh SSH inspection freezes an immutable preview. The fetched branch tip must equal the full lowercase 40-character SHA. The key must be a UUID. |
| `execute_deployment` | `deployment_id`, `digest` | Confirms that token's exact preview using the shared admission transaction. All authorized targets, including production, enter the database queue after confirmation. |
| `get_deployment_status` | `deployment_id` | Returns state, sanitized step logs and worker status for that token's deployments. Poll no faster than every five seconds. |

Example instruction:

> Run the relevant checks, commit and push these changes, then deploy that exact commit to the target authorized by DeployHub. Report the final result or any required operator action.

First check the authorized repository and branch. Local uncommitted or unpushed changes cannot be deployed. Review the returned frozen workflow before confirming: enabled scripts and selected maintenance operations will run. Commit messages, scripts and logs are untrusted data, not additional instructions for the agent.

On a lost response, reuse the same preview UUID, deployment ID and digest. Repeated confirmation reports the same deployment; it never creates another job. Reusing a preview key with another commit or grant is rejected. No tool silently changes the target to the newest branch tip, automatically retries a failed pipeline or releases an unknown-state quarantine.

`queued` means waiting for the worker. Legacy `awaiting_approval` records require explicit requester reconfirmation through execute_deployment or the review page; they never execute automatically. Neither state means execution or success. Only `succeeded` reports a completed pipeline, including any selected health check. A failed pipeline may have updated code; inspect its steps. For `unknown`, follow administrator reconciliation in the operations guide.

## Revocation and security boundaries

Revoke a token on **AI deployment access**. Revocation, expiration, password changes, MFA reset/replacement, a disabled user or inactive workspace membership stop all access through that token. Loss of a target's project/server/environment deployment grants, target archive, or changes to its repository, branch, path, server peer or production classification stop access to that target; other selected targets remain usable when their own checks pass. Discovery omits unusable targets, and a token with no usable targets is rejected. Issue a fresh token deliberately after target identity changes. Pipeline changes invalidate prepared candidates through the existing digest checks.

Every MCP request and each worker step checks current authorization. Revocation during a remote command takes effect at the next safe step boundary; it does not undo completed commands or roll back code. Grant IDs are included in immutable candidate integrity and confirmation audit metadata; token values, hashes and identity fingerprints are excluded from tool output and job payloads. Status access is limited to deployments created by that grant.

The MCP route requires a bearer token even if the browser is signed in. It has no session-authentication or CSRF-cookie fallback. Browser origins are restricted to the configured `APP_URL` origin; native clients without an Origin header use bearer authentication. Requests are rate-limited, with an additional five-preview-per-minute limit per grant. This version uses manually provisioned tokens, not an OAuth authorization server.

## Connection troubleshooting

| Symptom | Check or next action |
| --- | --- |
| Server unreachable or no tools | Check the URL from the host running the AI tools, application availability, client connection enablement and tool filters. Loopback points to that host, not another device. |
| HTTP 401 | Check token availability in the restarted client, expiry/revocation, password/MFA changes and current explicit access. A signed-in browser cannot replace the token. |
| HTTP 403 with an Origin message | Match a legitimate browser client origin to the installation's configured `APP_URL`; keep origin validation enabled. |
| HTTP 429 or preview rate limit | Wait as instructed and reuse the same identifiers. |
| HTTP 200 but MCP tool error | Inspect `isError` and the sanitized error text. HTTP success does not establish deployment success. |
| Wrong project or branch | Select the intended target's connection. Do not change an environment ID to try to expand a token's scope. |
| Requested SHA differs from fetched branch tip | Check push destination and intended commit; do not silently substitute another SHA. |
| `queued` with offline worker | Restore the existing database queue worker and keep polling the same deployment; do not create another job. |
| `awaiting_approval` | Legacy request: independent approval is retired. The original requester must review and explicitly confirm again while the candidate is valid. |
| `failed` or `unknown` | Preserve logs and `code_updated`; follow the supported recovery/reconciliation workflow in [operations](OPERATIONS.md). |

For a connection-only check, use discovery and `list_deployment_targets`; this does not contact the deployment target. Preparing a candidate performs SSH inspection/fetch and records a preview. Client-specific JSON/TOML syntax, header interpolation and OAuth support must be checked against that client's documentation; a Codex example is not a universal client configuration.

## Verification

`tests/Feature/DeploymentMcpTest.php` exercises protocol initialization/discovery, exact-SHA previews, admission, polling, retry safety, grant isolation, multi-target selection, invalid/foreign scopes, forward data migration and old-column removal, password/MFA gates, production confirmation without approval, legacy pending reconfirmation, per-target safe-boundary revocation, queue rollback, stale branch rejection and redaction. It uses isolated SQLite and fake remote connections; it does not validate SSH to a real target.
