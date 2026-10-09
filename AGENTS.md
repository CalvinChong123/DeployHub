# DeployHub agent instructions

The active rule system is `.agents/rules/*.mdc`. Those files are the detailed source of truth.

## Loading rules

- At the start of work, read every rule with `alwaysApply: true`.
- Read rules with `alwaysApply: false` only when the task or affected files match their `globs`, or clearly involve their subsystem. Recheck when scope changes.
- Before executing local commands, read `.agents/local-rules/runtime.local.mdc` if present. This ignored file supplies workstation preferences; shared rules remain portable.

## Active rules

- `.agents/rules/project-rules.mdc` — global implementation, runtime compatibility and verification decisions.
- `.agents/rules/language-and-doc-rules.mdc` — language and current documentation truth.
- `.agents/rules/deployment-safety.mdc` — reviewed candidates, recovery, remote commands and worker ownership.
- `.agents/rules/access-and-credentials.mdc` — workspace permissions, MFA and credential disclosure boundaries.
- `.agents/rules/inertia-contracts.mdc` — coupled controller, typed Vue, pagination and live-status contracts.

## Inactive records

`.agents/logs/` contains user changelogs and recovery archives. Do not load these as active instructions unless the user requests restoration, audit or comparison.
