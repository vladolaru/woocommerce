---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-21 02:46
tool: woocommerce-code-review
target: A5h post-A4bd cutover readiness refresh
reconciles:
  - analysis-a5h-post-a4bd-cutover-readiness-refresh.md
  - plans/2026-06-21-core-native-payments-a5h-post-a4bd-cutover-readiness-refresh.md
  - review-a5h-next-slice-scan.md
  - data/a5h-post-a4bd-cutover-readiness/a5f/a5f-cutover-rehearsal.json
  - data/a5h-post-a4bd-cutover-readiness/a5f-rerun-1/a5f-cutover-rehearsal.json
  - data/a5h-post-a4bd-cutover-readiness/a5g/a5g-multisite-runtime-gate.json
  - data/a4bd-post-a4bc-accumulated-gate/a4aq-accumulated-gate.json
last_updated: 2026-06-21 02:48
status: final
---

# A5h Cutover Readiness Refresh Review

> **Prompt:** "Read-only A5h evidence reconciliation for WooCommerce Core native WooPayments.
>
> Repo: /Users/vladolaru/Work/a8c/woocommerce-develop-2
> Branch: exp/core-native-payments
> Session folder: .agents/scratchpad/sessions/2026-06-15-core-native-payments
>
> Constraints:
> - Do not edit files.
> - Do not access WPCOM sandbox and do not modify WPCOM code.
> - Do not push, do not touch trunk, do not lint .agents.
> - Treat tools/woopayments-merge as ignored local harness evidence only.
>
> Context:
> A4bd closed with accumulated A4/N12 gate pass and review PASS_WITH_LIMITATIONS. A5h now refreshed A5 gates after A4bd.
>
> Evidence to read:
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a5h-post-a4bd-cutover-readiness-refresh.md
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-21-core-native-payments-a5h-post-a4bd-cutover-readiness-refresh.md
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a5h-next-slice-scan.md
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5h-post-a4bd-cutover-readiness/a5f/a5f-cutover-rehearsal.json (first failed diagnostic run)
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5h-post-a4bd-cutover-readiness/a5f-rerun-1/a5f-cutover-rehearsal.json (current A5f pass)
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5h-post-a4bd-cutover-readiness/a5g/a5g-multisite-runtime-gate.json
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate/a4aq-accumulated-gate.json
> - Source defaults/tests: plugins/woocommerce/src/Internal/Payments/NativePaymentsRuntimeArbiter.php, plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php, plugins/woocommerce/tests/php/src/Internal/Payments/NativePaymentsRuntimeArbiterTest.php, plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php
>
> Questions:
> 1. Is it fair to close A5h as a local A5 cutover readiness refresh if A5f rerun and A5g pass, while explicitly carrying the first non-reproduced A5f plugin-active PHP notice as diagnostic history?
> 2. Does the A5h exit wording overclaim production mandatory/default-on, canary, error-rate, or perf readiness?
> 3. Is A6 still blocked/deferred given the old A6 harness-deletion plan is stale and current harness is ignored local verification infra?
> 4. Any source-backed blocker or wording/coverage limitation that must be recorded before staging/spec-baseline updates?
>
> Write your report to .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a5h-cutover-readiness-refresh.md with required scratchpad YAML frontmatter. Use verdict: PASS / PASS_WITH_LIMITATIONS / BLOCKED. Keep concise and source-backed."

## Verdict

PASS_WITH_LIMITATIONS.

A5h is fair to close as a local A5 cutover readiness refresh after the current A5f rerun and A5g gates passed, provided the first A5f run remains recorded as non-reproduced diagnostic history and the closeout does not claim production rollout readiness. I found no source-backed product blocker that must be fixed before staging/spec-baseline updates; the required follow-up is wording discipline and explicit limitation carry-forward.

## Evidence Reviewed

- `analysis-a5h-post-a4bd-cutover-readiness-refresh.md`: frames A5h as a post-A4bd gate refresh, not a production default flip or A6 cleanup slice.
- `plans/2026-06-21-core-native-payments-a5h-post-a4bd-cutover-readiness-refresh.md`: records the first A5f diagnostic fail, focused clean reproduction, A5f rerun pass, and A5g pass.
- `review-a5h-next-slice-scan.md`: recommends a gate refresh and explicitly defers A6 cleanup because the old plan assumes a tracked harness while the current harness is ignored local verification infra.
- `data/a5h-post-a4bd-cutover-readiness/a5f/a5f-cutover-rehearsal.json`: first run `status=fail`, `pass=false`, one failure at `scan-target-debug-log` with `php_notice: 1`; all functional cutover phases before the log scan passed.
- `data/a5h-post-a4bd-cutover-readiness/a5f-rerun-1/a5f-cutover-rehearsal.json`: rerun `status=pass`, `pass=true`, 27 phases, no failures, final target `debug.log` 0 bytes, restored native ownership, standalone WooPayments plugin inactive.
- `data/a5h-post-a4bd-cutover-readiness/a5g/a5g-multisite-runtime-gate.json`: `status=pass`, `pass=true`, 28 phases in `existing-tests` mode; native owns both sites when the plugin is inactive, the plugin owns only relevant sites when active per-site or network-wide, and cleanup restores the tests env.
- `data/a4bd-post-a4bc-accumulated-gate/a4aq-accumulated-gate.json`: `status=pass`, 13/13 checks, zero failures, zero incomplete checks; it still carries the A4bd limitations from `review-a4bd-accumulated-gate.md`.
- `plugins/woocommerce/src/Internal/Payments/NativePaymentsRuntimeArbiter.php`: `DEFAULT_NATIVE_RUNTIME_ENABLED = false` and `is_native_runtime_enabled()` applies the filter from that default.
- `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`: `DEFAULT_MANDATORY_CUTOVER_ENABLED = false`; activation blocking and auto-deactivation require mandatory cutover, cutover readiness, plugin ownership, and no `WC_ALLOW_MERGED_FEATURE_PLUGINS` bypass.
- Focused tests: `NativePaymentsRuntimeArbiterTest` asserts native rollout default is fail-closed and filter-overridable; `WooPaymentsCutoverControllerTest` asserts mandatory cutover is default-off, filter-overridable, and fail-closed across preflight blockers.

## Answers

### 1. A5h closeout fairness

Yes, it is fair to close A5h as a local A5 cutover readiness refresh. The current A5f rerun and A5g rollups pass, and A4bd remains the latest passing accumulated A4/N12 gate. The first A5f failure must be carried exactly as diagnostic history: a plugin-active `_load_textdomain_just_in_time` notice for the standalone `woocommerce-payments` text domain during the first run, not reproduced by focused probes, and cleared by the full rerun.

Do not rewrite the first failed run as passed. The honest formulation is: "first A5f diagnostic run failed at the final log scan; focused reproduction did not reproduce; full rerun passed cleanly."

### 2. Exit wording overclaim check

The A5h wording is safe only if it says "local cutover readiness refresh" or "local A5 gate refresh." It overclaims if it says or implies production mandatory/default-on readiness, release rollout readiness, canary/error-rate readiness, WPCOM production readiness, or exact perf readiness.

Source backs this limit. `NativePaymentsRuntimeArbiter::DEFAULT_NATIVE_RUNTIME_ENABLED` is still false, and `WooPaymentsCutoverController::DEFAULT_MANDATORY_CUTOVER_ENABLED` is still false. The tests assert those defaults remain fail-closed and require explicit rollout filters. I found no source-backed production canary/error-rate rollout implementation in the reviewed paths; the only nearby shadow-mode surface is still the A1 projection-baseline shadow logger, not a final canary gate. A4bd perf evidence remains a local large-delta smoke gate, not production latency proof.

### 3. A6 status

A6 remains deferred/blocked for now. The old A6 plan is stale because it assumes `tools/woopayments-merge/` is tracked transition scaffolding removable with `git rm -r`, references `tools/woopayments-merge/a6-cleanup-audit.sh`, and includes scratchpad markdown linting. Current repo state contradicts those assumptions: `git ls-files tools/woopayments-merge` returns zero tracked files, `.git/info/exclude` ignores `/tools/woopayments-merge/`, and no `a6*` harness file exists at the harness root.

Deleting the harness now would remove ignored local verification infrastructure, not clean committed product code. A6 needs a fresh plan after a true A5 production/default-on decision and after the current harness lifecycle is re-scoped.

### 4. Blockers and limitations before staging/spec-baseline updates

No source-backed blocker found.

Limitations that must be recorded:

1. A5h closes local A5 cutover readiness only: A5f connected target-store rehearsal, A5g multisite runtime ownership, and A4bd accumulated A4/N12 freshness under their stated limits.
2. The first A5f run remains a failed diagnostic run, not a hidden pass; it found one non-reproduced plugin-active PHP notice before the rerun passed cleanly.
3. Native runtime and mandatory cutover remain default-off/fail-closed in source and tests.
4. Production default-on, mandatory cutover rollout, canary/error-rate monitoring, WPCOM production readiness, and release sequencing remain deferred.
5. Perf evidence remains coarse local smoke coverage for large deltas, query growth, and missing coverage, not exact production performance proof.
6. A4bd limitations still carry forward: optional admin routes are not full reference content parity, checkout coverage has stated caveats, settings tokens are representative rather than exhaustive, and reference-side diagnostics are recorded separately from target evidence.
7. A6 cleanup/removal must not start from the stale old plan; the current ignored harness should be preserved as local verification infrastructure until a new A6 plan supersedes it.
