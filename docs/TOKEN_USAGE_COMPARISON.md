# Agent rule token assessment — 9 October 2026

The installed rules increase the amount of instruction text available to the model. Their effect on total task token consumption is **not measured yet**. The bug review and subsequent implementation are different tasks, with different code and conversation history, so their token counts cannot establish a before/after saving.

## Measured text size

| Instruction text loaded | UTF-8 bytes | Approximate tokens |
| --- | ---: | ---: |
| Root `AGENTS.md` | 1,295 | 324 |
| Two mandatory global rules | 4,164 | 1,041 |
| Entry point plus mandatory rules | 5,459 | 1,365 |
| Entry point, mandatory rules and local runtime guidance | 6,945 | 1,737 |
| All seven instruction files, including conditional rules | 14,335 | 3,584 |

Estimates use bytes / 4, rounded for the combined text. They are not tokenizer-exact counts and exclude message framing, tool definitions/results, reasoning, user prompts and other instructions. The [OpenAI Codex prompting guide](https://developers.openai.com/cookbook/examples/gpt-5/codex_prompting_guide) describes this rough approximation.

Only the root entry point is discovered as an `AGENTS.md` instruction file. It directs the agent to read the global and relevant conditional `.mdc` files. The frontmatter is this repository's loading convention, not a claim that Codex automatically injects every `.mdc` file. See [instruction discovery](https://learn.chatgpt.com/docs/agent-configuration/agents-md).

The rule-authoring skill and its references are one-time setup work and should be measured separately from routine project tasks. Logs and archives are not active instructions. Rule text may remain in context across multiple model calls; cached input affects cost, but does not erase the raw input-token count. See [prompt caching](https://developers.openai.com/api/docs/guides/prompt-caching).

## Fair comparison

1. Create two isolated copies of the **same current code snapshot**, outside the original project's instruction hierarchy. Keep dependencies and runtime configuration equivalent. Exclude real credentials, `.env`, private storage and real deployment data. Remove the new `AGENTS.md` and `.agents` instruction/log files from the baseline copy; retain them in the treatment copy. Do not remove rules from the live project.
2. Use identical prompts, model, reasoning effort, tools, permissions, global instructions and CLI version. Start a fresh CLI session for every run. Do not resume this long chat. Exclude installation and rule-generation work from the steady-state comparison.
3. Start with a few read-only tasks, then expand to representative repairs in disposable working copies. Run each task at least three times per group, preferably five. Alternate or randomize A/B order, and keep first-run and reused-cache results visible rather than combining them blindly.
4. Capture JSONL usage, final answers, elapsed time and task quality. Confirm the treatment actually reads the relevant rule files and the baseline does not obtain them through a parent directory or repository documentation. Treat failure or incomplete work as a quality outcome, not a token-saving success.
5. Compare medians per task. Report raw input, cached input, uncached input, output, available reasoning details, total tokens and observed tool items. Then compare tokens per successful task and rework required. Use the same acceptance checklist for both groups; correctness can justify a modest token increase.

Suggested tasks for this project:

- Trace production approval from request to worker execution, citing code and tests.
- Explain how requester permission revocation blocks a queued deployment.
- Trace same-commit pipeline recovery and list the checks that prevent an unsafe replay.
- Review paginated approvals/activity responses and their typed Vue consumers for flow mismatches.
- In disposable repair trials, give both groups the same deliberately introduced validation or state-transition defect and run identical acceptance tests.

## Capture a run

The installed CLI is `codex-cli 0.160.1`. Its local help confirms `--json`, `--ephemeral`, `--sandbox` and model/config overrides. Official [non-interactive documentation](https://learn.chatgpt.com/docs/non-interactive-mode) includes a `turn.completed` usage object with input, cached input, output and reasoning counters. This measures a CLI experiment; it does not reproduce all desktop app instructions/tool definitions or calculate account quota consumption.

The following is a template. Set the model/effort to the same values for both groups and use existing disposable copies; the script does not create them or run extra model calls automatically.

```powershell
$baselineCopy = 'C:\path\to\disposable\without-rules'
$treatmentCopy = 'C:\path\to\disposable\with-rules'
$benchmarkModel = 'YOUR_MODEL_ID'
$benchmarkEffort = 'YOUR_REASONING_EFFORT'
$benchmarkPrompt = 'Using app, routes and tests, trace the production approval flow from request to worker execution. Cite file and line references. Keep the answer under 500 words. Do not modify files, start services, read secrets or contact SSH servers.'

codex exec --cd $baselineCopy --sandbox read-only --ephemeral --json --model $benchmarkModel -c "model_reasoning_effort=$benchmarkEffort" $benchmarkPrompt > before-1.jsonl 2> before-1.stderr.log
codex exec --cd $treatmentCopy --sandbox read-only --ephemeral --json --model $benchmarkModel -c "model_reasoning_effort=$benchmarkEffort" $benchmarkPrompt > after-1.jsonl 2> after-1.stderr.log
```

Repeat with new numbered filenames, alternating the order. Record duration separately, for example with a stopwatch around each command. Save results outside the tested source tree so one group cannot read the other group's answers.

Do not use `--ignore-rules` to disable `AGENTS.md`: this CLI flag refers to execpolicy `.rules` files. Keep security policy unchanged between groups.

## Analyze captured usage

The offline PowerShell helper performs no model calls and changes no rules:

```powershell
# Show current rule text size.
./scripts/compare-rule-tokens.ps1

# Compare matched fresh single-turn JSONL runs for one task.
./scripts/compare-rule-tokens.ps1 -BeforeLogs 'C:\results\before-*.jsonl' -AfterLogs 'C:\results\after-*.jsonl' -OutputCsv 'C:\results\usage.csv'
```

It validates completed usage records, preserves unavailable counters as unknown, requires equal run counts, reports medians and optional raw CSV output, and rejects a log included in both groups. Total uses a reported total when present, otherwise reported input plus output. Cached input and reasoning detail are not added a second time. The observed-tool count is limited to the event types recognized by the helper, so inspect JSONL traces for other tools.

`ChangePercent = 100 × (with-rules median − without-rules median) / without-rules median`.

A negative result means fewer reported tokens for that metric. Cached input and output have different cost treatment; a raw-token reduction is not automatically the same percentage cost reduction. Missing usage, failed runs and quality differences prevent a reliable conclusion.

## Current conclusion

**Instruction overhead increased by approximately 1.4k–3.6k tokens of loaded text, depending on task scope. Net task usage could increase or decrease; there is no matched experiment establishing either outcome yet.** Keep conditional loading and preserve the small entry point. The first candidates for later refinement are overly broad mandatory runtime reads or repeated verification instructions, but measure them before weakening safety constraints.

The helper was checked using synthetic JSONL counters. No synthetic result is evidence about this project's actual model consumption, and no additional model trials were launched during this assessment.
