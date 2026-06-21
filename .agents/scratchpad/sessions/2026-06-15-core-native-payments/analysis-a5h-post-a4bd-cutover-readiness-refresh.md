---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-21 02:38
target: A5h post-A4bd cutover readiness refresh
reconciles:
  - review-a5h-next-slice-scan.md
  - review-a5h-cutover-readiness-refresh.md
  - plans/2026-06-20-core-native-payments-a5f-post-a4as-cutover-rehearsal.md
  - plans/2026-06-20-core-native-payments-a5g-rollout-multisite-gate.md
  - plans/2026-06-21-core-native-payments-a4bd-post-a4bc-accumulated-gate-refresh.md
  - spec-conformance-baseline.md
  - staging-log.md
last_updated: 2026-06-21 02:49
status: final
---

# A5h Post-A4bd Cutover Readiness Refresh

## Prompt Trail

> **Prompt:** "Remember to constantly record your progress so it survives compactions and you don't redo your steps after a compaction."

## Current Line

A4bd is closed as the latest post-A4bc accumulated A4/N12 gate refresh. Its rollup `data/a4bd-post-a4bc-accumulated-gate/a4aq-accumulated-gate.json` passed with zero failures and zero incomplete checks, and its review closed as `PASS_WITH_LIMITATIONS` without a source-backed product blocker. The branch has no Git-visible product changes after A4bd, and `git diff --check -- . ':!.agents'` passed at A4bd closeout.

The A5 residual scan in `review-a5h-next-slice-scan.md` recommends `RECOMMEND_GATE_REFRESH`. The scan found no concrete local product implementation slice that should precede an A5 exit framing, and it explicitly says A6 should not start now because the old A6 harness-deletion plan is stale, the harness is ignored/local and intentionally preserved for verification, and the baseline still does not claim A6 cleanup readiness.

## Source-Backed Decision

The next coherent slice is A5h: rerun and reconcile the A5 cutover readiness gates against the current post-A4bd code. This should refresh A5f's connected target-store cutover rehearsal and A5g's multisite runtime ownership proof, then record an A5 exit verdict that is precise about scope.

A5h should not flip product defaults. `NativePaymentsRuntimeArbiter::DEFAULT_NATIVE_RUNTIME_ENABLED` remains false and `WooPaymentsCutoverController::DEFAULT_MANDATORY_CUTOVER_ENABLED` remains false by design. A5h can close the local cutover readiness baseline only if the refreshed gates pass and the closeout states that production mandatory/default-on, canary/error-rate/perf rollout, WPCOM readiness, and release sequencing remain deferred fail-closed decisions.

A5h should not start A6 cleanup. The current harness is ignored local verification infrastructure, not a tracked transition payload to delete. Removing it now would reduce the local gate surface the user asked to keep using. A6 only becomes viable after a true A5 production/default-on exit decision and an updated cleanup plan that reflects the current harness lifecycle.

## Planned Evidence

- A5f refresh rollup under `data/a5h-post-a4bd-cutover-readiness/a5f/a5f-cutover-rehearsal.json`.
- A5g refresh rollup under `data/a5h-post-a4bd-cutover-readiness/a5g/a5g-multisite-runtime-gate.json`.
- A read-only review artifact over the refreshed A5h evidence and A5 exit framing.
- Updates to `implementation-log.md`, `staging-log.md`, `spec-conformance-baseline.md`, and the A5h plan with exact pass/fail and limitations.

## Result

A5h can close as a local A5 cutover readiness refresh, with limitations. The first A5f run at `data/a5h-post-a4bd-cutover-readiness/a5f/a5f-cutover-rehearsal.json` failed only at the final log scan on one fresh plugin-active `_load_textdomain_just_in_time` notice for the standalone `woocommerce-payments` text domain; focused reproduction did not reproduce it. The preserved current A5f rerun at `data/a5h-post-a4bd-cutover-readiness/a5f-rerun-1/a5f-cutover-rehearsal.json` passed with 27 phases, no failures, target native-owned, WooPayments inactive, and target `debug.log` at 0 bytes.

A5g refresh at `data/a5h-post-a4bd-cutover-readiness/a5g/a5g-multisite-runtime-gate.json` passed with 28 phases and no failures in `existing-tests` mode. It proved per-site and network-active runtime ownership in the isolated WooCommerce tests wp-env and restored that tests env to single-site with no active plugins.

Review `review-a5h-cutover-readiness-refresh.md` returned `PASS_WITH_LIMITATIONS` with no source-backed product blocker. The closeout wording must remain local-scope only: A5h does not claim production default-on, mandatory rollout, canary/error-rate, WPCOM production readiness, exact perf readiness, or A6 cleanup readiness.

## Boundaries

Do not access or mutate any WPCOM sandbox or WPCOM repo code. Do not push. Do not touch trunk. Do not weaken harness assertions to mask product regressions. Do not lint `.agents`. Do not start A6 cleanup or delete the ignored harness. Product code changes are only allowed if a refreshed gate surfaces a source-backed regression.
