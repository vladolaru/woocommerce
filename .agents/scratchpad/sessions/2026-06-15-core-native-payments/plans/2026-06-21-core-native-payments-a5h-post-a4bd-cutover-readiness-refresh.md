---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-21 02:38
target: A5h post-A4bd cutover readiness refresh
reconciles:
  - ../analysis-a5h-post-a4bd-cutover-readiness-refresh.md
  - ../review-a5h-next-slice-scan.md
  - ../review-a5h-cutover-readiness-refresh.md
  - 2026-06-20-core-native-payments-a5f-post-a4as-cutover-rehearsal.md
  - 2026-06-20-core-native-payments-a5g-rollout-multisite-gate.md
  - 2026-06-21-core-native-payments-a4bd-post-a4bc-accumulated-gate-refresh.md
last_updated: 2026-06-21 02:50
status: complete
---

# A5h Post-A4bd Cutover Readiness Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refresh A5 cutover readiness after the reopened A4/N12 settings parity work and record a precise A5 exit line without flipping production rollout defaults or starting A6 cleanup.

**Architecture:** A5h is a gate and documentation slice. It reuses the ignored local A5f/A5g harnesses as oracles, fixes only source-backed regressions surfaced by those gates, and reconciles the result through a reviewer before updating the staging/spec baseline. Production mandatory/default-on remains fail-closed unless a future release/default-on slice explicitly changes it.

**Tech Stack:** WooCommerce Core PHP internals, local Docker/wp-env WP-CLI, Playwriter through the existing harness, ignored `tools/woopayments-merge` gate scripts, JSON scratchpad evidence.

---

## Evidence Directory

`.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5h-post-a4bd-cutover-readiness`

## Task 1: Preflight And Checkpoint

- [x] Verify the Git-visible worktree is clean except branch-ahead status with `git status --short --branch --untracked-files=all`.
- [x] Verify the target CLI container name and target URL. Expected target URL is `http://store8889.localhost:8889`; use `docker ps` to confirm the current CLI container before running WP-CLI.
- [x] Verify the Playwriter session id to pass to A5f. If existing session `5` is not usable by the harness, create or select another session without forcing direct Chrome DevTools access.
- [x] Append an A5h checkpoint to `implementation-log.md` before long gate execution, including the evidence directory and the decision that A6 cleanup remains out of scope.

## Task 2: Refresh A5f Connected Cutover Rehearsal

- [x] Run the current A5f cutover rehearsal against the target store:

```bash
python3 tools/woopayments-merge/a5f-cutover-rehearsal.py \
  --target-wp "docker exec -i <target-cli-container> wp --allow-root --user=1" \
  --target-url http://store8889.localhost:8889 \
  --playwriter-session <session-id> \
  --out-dir .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5h-post-a4bd-cutover-readiness/a5f
```

- [x] Read `data/a5h-post-a4bd-cutover-readiness/a5f/a5f-cutover-rehearsal.json` with `jq` before drawing conclusions. Record `status`, `pass`, phase statuses, failures, target restore state, and log results.
- [x] If A5f fails, inspect the failing phase evidence first. Classify it as product regression, stale harness gap, or local environment issue only after source/browser/log verification. Do not change the harness to hide a product bug.

Result: the first A5f run failed only at the final debug-log scan on one fresh `_load_textdomain_just_in_time` notice for the standalone `woocommerce-payments` plugin domain. Focused reproduction did not reproduce it, and the full rerun in `data/a5h-post-a4bd-cutover-readiness/a5f-rerun-1/a5f-cutover-rehearsal.json` passed with 27 phases, no failures, native ownership restored, WooPayments plugin inactive, and target `debug.log` at 0 bytes.

## Task 3: Refresh A5g Multisite Runtime Gate

- [x] Run the A5g multisite gate in `existing-tests` mode unless source inspection shows the disposable mode is safer for the current env. Use an isolated evidence directory:

```bash
python3 tools/woopayments-merge/a5g-multisite-runtime-gate.py \
  --repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 \
  --wcpay-repo /Users/vladolaru/Work/a8c/woocommerce-payments \
  --runtime-mode existing-tests \
  --existing-wp-env-dir /Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce \
  --existing-tests-url http://store8889.localhost:8087 \
  --out-dir .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5h-post-a4bd-cutover-readiness/a5g
```

- [x] Read `data/a5h-post-a4bd-cutover-readiness/a5g/a5g-multisite-runtime-gate.json` with `jq` before drawing conclusions. Record `status`, `pass`, runtime mode, phase statuses, failures, and cleanup state.
- [x] If A5g fails, verify whether the failure is a product runtime-ownership regression, a wp-env/container substrate problem, or a stale harness expectation. Fix only source-backed issues, then rerun into a suffixed evidence directory.

Result: `data/a5h-post-a4bd-cutover-readiness/a5g/a5g-multisite-runtime-gate.json` passed with 28 phases, no failures, `runtime_mode=existing-tests`, per-site and network-active ownership proof, and cleanup back to single-site tests wp-env. The command intentionally used `http://store8889.localhost:8087`, the tests environment URL, not the connected `:8889` target store.

## Task 4: Reconcile A5 Exit Framing

- [x] Re-read current `NativePaymentsRuntimeArbiter.php` and `WooPaymentsCutoverController.php` rollout-default code and focused tests to verify the fail-closed defaults remain intentional.
- [x] Reconcile A5f, A5g, and the latest A4bd rollup into one A5h verdict. The verdict must distinguish local cutover readiness from production mandatory/default-on readiness.
- [x] Dispatch one read-only review/reconciliation subagent over the A5h evidence and exit framing. The reviewer should confirm whether any pass claim is overstated and whether A6 remains blocked.
- [x] Fix only source-backed findings. If the review only narrows wording/limitations, update docs rather than product code.

Result: source defaults remain fail-closed (`DEFAULT_NATIVE_RUNTIME_ENABLED=false`, `DEFAULT_MANDATORY_CUTOVER_ENABLED=false`) and focused tests assert both defaults plus filter override seams. Review `review-a5h-cutover-readiness-refresh.md` returned `PASS_WITH_LIMITATIONS`, no source-backed product blocker, and required only limitation wording: A5h is local cutover readiness, not production/default-on rollout readiness, and A6 remains deferred.

## Task 5: Documentation And Hygiene

- [x] Update this plan, `analysis-a5h-post-a4bd-cutover-readiness-refresh.md`, `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md` with final A5h evidence paths, gate outcomes, and limitations.
- [x] Run final hygiene:

```bash
git status --short --branch --untracked-files=all
git diff --check -- . ':!.agents'
```

- [x] If no tracked product code changed, do not create an empty commit. If a product fix was required, run the affected focused tests/static gates, add the appropriate changelog, and commit only the logical tracked product change.

Result: `git status --short --branch --untracked-files=all` showed only `exp/core-native-payments...trunk [ahead 341]`, and `git diff --check -- . ':!.agents'` produced no output. No tracked product code changed, so no commit was created.

## Boundaries

No WPCOM sandbox access. No WPCOM code changes. No Stripe CLI use is expected. No push. No trunk work. Do not delete `tools/woopayments-merge`. Do not start A6 cleanup. Do not lint `.agents`.

## Self-Review

This plan covers the source-backed next action from `review-a5h-next-slice-scan.md`: an A5-specific gate refresh after A4bd, not another speculative product patch and not A6 cleanup. It explicitly preserves fail-closed runtime and mandatory cutover defaults, keeps the ignored harness available, records progress before long-running gates, and includes a review gate before updating the stage baseline.
