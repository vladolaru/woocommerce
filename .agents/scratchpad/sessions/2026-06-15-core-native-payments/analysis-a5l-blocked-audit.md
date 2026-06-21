---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-21 03:36
target: A5l blocked audit
reconciles:
  - analysis-a5i-local-readiness-decision-rollup.md
  - analysis-a5j-release-default-on-readiness.md
  - analysis-a5k-next-local-slice-after-a5j.md
  - review-a5l-blocker-audit.md
  - review-a5k-release-sequencing-next-move.md
  - review-a5k-n12-admin-parity-residuals.md
  - review-a5k-a6-cleanup-boundary.md
status: final
last_updated: 2026-06-21 03:42
---

# A5l Blocked Audit

## Prompt Trail

> **Prompt:** "Continue working toward the active thread goal."

## Purpose

A5l audits whether the active long-running goal can still make meaningful local progress after A5i, A5j, and A5k all converged on the same production/default-on stop line. This is not a completion claim. The full objective remains broader than the local evidence: native runtime ownership, clean plugin supersession, zero merchant-facing regression, deprecated/duplicated code dropped, multi-currency domain separation, and 3PD API sequencing. The question is whether the current session can advance those requirements further without production/release-owner evidence or an external-state change.

## Repeated Stop-Line History

A5i recorded the first local stop line after A5h: `review-post-a5h-next-slice-scan.md` recommended a decision rollup because no concrete source-backed local product or gate slice remained before production/default-on. A5i wrote that local A4/A5 readiness was green under limitations while production rollout/default-on, mandatory rollout, canary/error-rate, WPCOM production readiness, exact production perf, release sequencing, and A6 cleanup remained deferred and fail-closed.

A5j rechecked the release/default-on packet instead of relying on A5i. It refreshed focused guard PHPUnit, target runtime probe, A5f cutover rehearsal, and A5g multisite runtime evidence. `review-a5j-release-default-on-requirements.md` returned `BLOCKED_ON_PRODUCTION_DECISION`, and `review-a5j-local-gate-opportunities.md` returned `RERUN_A5_ONLY`; after the reruns passed, A5j still classified the remaining blocker as production/release-only.

A5k then tested whether A5j had missed a local product or cleanup slice. `review-a5k-release-sequencing-next-move.md` returned `PRODUCTION_DECISION_ONLY`, `review-a5k-n12-admin-parity-residuals.md` returned `NO_LOCAL_A4_BLOCKER`, and `review-a5k-a6-cleanup-boundary.md` returned `HOLD_A6_FOR_RELEASE`. A5k concluded that current source and evidence do not expose a local A4/A5/A6 product or harness slice to run before the production/default-on decision. A6a remains a valid future cleanup slice, but is held because removing the deprecated bundled-subscriptions settings contract now would remove Core's mirror of the standalone extension's disable-only `_wcpay_feature_subscriptions` rollback/coexistence path while plugin-wins remains part of the fail-closed safety model.

This is the third consecutive goal-continuation pass over the same stop line: A5i established it, A5j reran useful local evidence and preserved it, and A5k challenged the remaining local-slice options and preserved it.

## Current Source Readback

The branch remains fail-closed for production rollout. `NativePaymentsRuntimeArbiter::DEFAULT_NATIVE_RUNTIME_ENABLED` is `false`, and the rollout filter still receives that default. `WooPaymentsCutoverController::DEFAULT_MANDATORY_CUTOVER_ENABLED` is `false`, and the mandatory filter still receives that default. `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` is an empty array. `WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY` defaults ready only for the already-gated admin-surface readiness decision; an explicit false filter still blocks preflight. `WooPaymentsSettingsService` still exposes `_wcpay_feature_subscriptions`, `is_wcpay_subscriptions_enabled`, and the disable-only update path, which is why A6a remains release-sequenced instead of safe to execute before plugin rollback/coexistence ends.

Current Git-visible state remains clean outside ignored scratchpad docs: `git status --short --branch --untracked-files=all -- . ':!.agents'` shows only `exp/core-native-payments...trunk [ahead 341]`, `git diff -- . ':!.agents' --stat` produced no output, and `git diff --check -- . ':!.agents'` produced no output.

## Provisional Decision

Subject to the independent A5l decision review, the strict blocked threshold appears met. The blocker is not hard local work; it is missing production/release-owner evidence and an external rollout decision that cannot be produced from the local target/reference stores, local WPCOM clone, local harness, or read-only Stripe account inspection. Re-running local gates would only create recency evidence and would not satisfy canary parity/error-rate/perf, production WPCOM readiness, live queue/financial/Stripe Billing data safety, exact production perf, release sequencing, or the actual native runtime/default/mandatory flips. Starting A6a would regress the rollback/coexistence model before release/default-on sequencing clears it.

## Decision Review

`review-a5l-blocker-audit.md` returned `BLOCKED_THRESHOLD_MET`. It verifies that the same production/release-owner blocker repeats across A5i, A6, A5j, and A5k; that the remaining requirements are production/release facts rather than hard local work; and that local product, harness, docs, or evidence work would either add only recency timestamps or violate the held A6 boundary. The reviewer found no failed claims, while preserving that this is not a goal-completion or production/default-on readiness claim.

## Final Decision

The strict blocked threshold is met. The active objective cannot be completed locally from the current state because it requires production/release-owner evidence and decisions that are outside the allowed local environment: canary parity/error-rate/perf, production WPCOM readiness, live queue/financial/Stripe Billing data safety, exact production perf, release sequencing, and the actual native runtime/default/mandatory flips. A6a cleanup is also blocked until release/default-on sequencing clears the plugin-active rollback/coexistence window or an explicit release-owner decision changes that boundary. The branch remains locally green and fail-closed under the recorded limitations, but the full objective remains incomplete.
