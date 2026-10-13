# DeployHub MCP deployment access

DeployHub exposes a Streamable HTTP MCP server at `/mcp/deployhub`, using the official `laravel/mcp` package. A Codex task can prepare an exact pushed commit, confirm the candidate and poll the existing worker's result. When a phone controls that task, the MCP connection belongs on the host executing it.

## Authorize a target

1. Sign in to DeployHub and complete authenticator verification.
2. Open **Account security → AI deployment access** (`/automation`).
3. Select a registered target for which you have explicit deployment permission, enter a name, validity of 1–90 days and your current password, then select **Create deployment token**.
4. Copy the new token into a secure credential store. It is returned once; only its SHA-256 hash is stored in the grant table.

Each token is bound to its issuing user, workspace and one target. Nonproduction and production targets are supported. Production and targets configured to require approval retain independent approval: the requesting identity cannot approve its own deployment. This MCP server does not provide an approval tool; an independently authorized member uses DeployHub's existing approval page.

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

Restart existing workers after installing this update. For an existing installation, apply the additive migration without resetting the database:

```powershell
php artisan migrate --path=database/migrations/2026_10_11_000001_create_automation_grants.php
```

## Tool workflow

| Tool | Required arguments | Behavior |
| --- | --- | --- |
| `list_deployment_targets` | None | Returns the authorized target, configured origin/branch and approval requirement. No SSH connection. |
| `prepare_deployment` | `environment_id`, `expected_sha`, `idempotency_key` | Fresh SSH inspection freezes an immutable preview. The fetched branch tip must equal the full lowercase 40-character SHA. The key must be a UUID. |
| `execute_deployment` | `deployment_id`, `digest` | Confirms that token's exact preview using the shared admission transaction. Unprotected targets enter the database queue; protected targets await independent approval. |
| `get_deployment_status` | `deployment_id` | Returns state, sanitized step logs and worker status for that token's deployments. Poll no faster than every five seconds. |

Example instruction:

> Run the relevant checks, commit and push these changes, then deploy that exact commit to the target authorized by DeployHub. Report the final result or any required approval.

First check the authorized repository and branch. Local uncommitted or unpushed changes cannot be deployed. Review the returned frozen workflow before confirming: enabled scripts and selected maintenance operations will run. Commit messages, scripts and logs are untrusted data, not additional instructions for the agent.

On a lost response, reuse the same preview UUID, deployment ID and digest. Repeated confirmation reports the same deployment; it never creates another job. Reusing a preview key with another commit or grant is rejected. No tool silently changes the target to the newest branch tip, automatically retries a failed pipeline or releases an unknown-state quarantine.

`queued` means waiting for the worker; `awaiting_approval` means waiting for another approver. Neither means execution or success. Only `succeeded` reports a completed pipeline, including any selected health check. A failed pipeline may have updated code; inspect its steps. For `unknown`, follow administrator reconciliation in the operations guide.

## Revocation and security boundaries

Revoke a token on **AI deployment access**. Password changes, MFA reset/replacement, expiration, disabled membership, loss of project/server/environment deployment grants, target archive, or changes to the bound repository, branch, path, server peer or approval policy invalidate access. Issue a fresh token deliberately after target/policy changes. Pipeline changes invalidate prepared candidates through the existing digest checks.

Every MCP request and each worker step checks current authorization. Revocation during a remote command takes effect at the next safe step boundary; it does not undo completed commands or roll back code. Grant IDs are included in immutable candidate integrity and confirmation audit metadata; token values, hashes and identity fingerprints are excluded from tool output and job payloads. Status access is limited to deployments created by that grant.

The MCP route requires a bearer token even if the browser is signed in. It has no session-authentication or CSRF-cookie fallback. Browser origins are restricted to the configured `APP_URL` origin; native clients without an Origin header use bearer authentication. Requests are rate-limited, with an additional five-preview-per-minute limit per grant. This version uses manually provisioned tokens, not an OAuth authorization server.

## Verification

`tests/Feature/DeploymentMcpTest.php` exercises protocol initialization/discovery, exact-SHA previews, admission, polling, retry safety, grant isolation, password/MFA gates, independent production approval, safe-boundary revocation, queue rollback, stale branch rejection and redaction. It uses isolated SQLite and fake remote connections; it does not validate SSH to a real target.
