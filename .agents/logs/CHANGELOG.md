# AI rule changelog

## 2026-10-09

- Bootstrapped `.agents/rules/` and root `AGENTS.md` using the user-selected location.
- Added the mandatory project and language/documentation rules.
- Added conditional deployment-safety rules for candidate immutability, recovery, remote side effects and worker ownership; access rules for explicit grants, MFA and credential boundaries; Inertia rules for coupled props, pagination and live state.
- Captured the user-selected Windows/PowerShell and Laragon strategy in ignored `local-rules/runtime.local.mdc`. Installed runtime scan selected PHP 8.2.21 for the current `^8.2` platform and existing lockfile; the executable version is not fixed in active rules.
- Skipped generic Laravel, Vue styling and CRUD rule files because existing patterns and the targeted rules already cover their decision value.
- No earlier active rules existed to archive. Shared files are new, unignored Git-visible artifacts; the workstation runtime file is intentionally ignored. Future behavior-changing edits must snapshot unrecoverable rules first.
- Rule generation touched instruction files and the local-rule ignore entry. Application bug fixes were separately authorized by the user and are outside the rule skill's authoring scope.
