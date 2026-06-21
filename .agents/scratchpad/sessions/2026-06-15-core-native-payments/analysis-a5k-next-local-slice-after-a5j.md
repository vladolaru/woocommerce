---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-21 03:28
target: A5k next local slice after A5j
reconciles:
  - analysis-a5j-release-default-on-readiness.md
  - analysis-a6-replanned-cleanup-readiness.md
  - supervisor-prompt-2026-06-17-1311.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - review-a5k-release-sequencing-next-move.md
  - review-a5k-n12-admin-parity-residuals.md
  - review-a5k-a6-cleanup-boundary.md
status: final
last_updated: 2026-06-21 03:33
---

# A5k Next Local Slice After A5j

## Prompt Trail

> **Prompt:** "Continue working toward the active thread goal."

## Starting Point

A5j refreshed the local release/default-on packet and left the branch fail-closed for production/default-on evidence. A6 replanning found a valid future cleanup candidate but held product cleanup for release/default-on sequencing. A5k exists to avoid stalling on that boundary without proof: re-check current source, canonical supervisor instructions, and accumulated evidence for the next source-backed local move that genuinely advances the final Option C objective.

## Questions

- Is there a local product or gate slice remaining after A5j that is not a production/release-only decision?
- Does N12 leave any A4 merchant-facing admin parity requirement unproven or regressed despite A4bd/A5j?
- Is the A6 cleanup hold too broad, or does the deprecated bundled WooPayments Subscriptions settings cleanup really need to wait for release/default-on sequencing?
- If no local source-backed slice remains, what exact release-only blocker should be recorded as the current impasse without marking the long-running goal complete?

## Initial Controller Read

N8 says N7 should not keep widening indefinitely once baseline-sufficient, and A4 should be entered. The current branch has since run and recorded many A4/N12 slices plus A4bd, A5h, A5i, and A5j. N9 is recorded as done in the supervisor prompt, with `KNOWN_UNHANDLED_EVENT_TYPES` now empty. N10 is an A4 exit obligation around aggregate dedupe/single-registry/bundle delta and route architecture write-back. N12 is the safety-critical admin parity advisory; it demands merchant reachability, functional/visual parity, copy/content parity, and a widened A4 exit gate before native admin surfaces can be treated as ready.

The first A5k action is a parallel read-only audit rather than product edits. This avoids both false blockage and premature A6 cleanup.

## Source Readback

Current source still has `WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY` defaulting ready through `apply_filters( ..., true )`, while runtime rollout and mandatory cutover remain separately fail-closed with `NativePaymentsRuntimeArbiter::DEFAULT_NATIVE_RUNTIME_ENABLED=false` and `WooPaymentsCutoverController::DEFAULT_MANDATORY_CUTOVER_ENABLED=false`. `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` is empty. This matches A5j: local admin/cutover readiness is green under limits, but production/default-on is not authorized.

Current source still exposes the deprecated bundled WooPayments Subscriptions settings surface: `WooPaymentsSettingsService` reads `_wcpay_feature_subscriptions`, returns `is_wcpay_subscriptions_enabled`, accepts disable-only updates to write the option to `0`, the REST controller allows the key, and the TS settings store/page expose `useWCPaySubscriptions()` plus the disabled/deprecated `Enable Subscriptions with WooPayments` control. The held A6a plan remains the right future cleanup shape, but the A5k A6 audit verified that this surface still mirrors the standalone extension's disable-only rollback/coexistence contract.

The admin navigation source no longer matches the older broad N12 warning. `WooPaymentsAdminNavigationController` registers persistent WooPayments submenus under the Core Payments parent when native owns runtime and the user can `manage_woocommerce`; routes deep-link into canonical Settings > Payments `/woopayments/*` provider paths; route availability follows account-state and optional-surface predicates; legacy `/payments/*` paths redirect rather than becoming native ownership paths. Later A4at/A4bc evidence intentionally split `gatewayEnabled` from route/menu reachability to avoid stranding a merchant when processing is disabled.

## Subagent Reconciliation

`review-a5k-release-sequencing-next-move.md` returned `PRODUCTION_DECISION_ONLY`. It found no source-backed local product, harness, or cleanup slice that should run before the production/default-on release decision. It classifies A4/A5 local readiness and A5j freshness as already-evidenced under recorded limits, and classifies canary parity/error-rate/perf, production WPCOM readiness, live queue/financial/Stripe Billing data safety, exact production perf, release sequencing, and the actual default/mandatory flip as production/release-only.

`review-a5k-n12-admin-parity-residuals.md` returned `NO_LOCAL_A4_BLOCKER`. It found no orphaned-route gap: native WooPayments admin surfaces are reachable through persistent Core Payments submenus and canonical Settings > Payments `/woopayments/*` provider routes with native runtime, capability, account-state, and optional-surface gating. It explicitly preserves the A4bd limitation that accumulated browser/token evidence is not unlimited visual proof, but found no concrete untracked local A4 parity slice. It considers `FILTER_NATIVE_ADMIN_SURFACES_READY` defaulting ready defensible after A4as/A4bd/A5j because explicit false still blocks preflight, while runtime and mandatory rollout remain independently fail-closed.

`review-a5k-a6-cleanup-boundary.md` returned `HOLD_A6_FOR_RELEASE`. It strengthens the A6 hold with a concrete A6a-specific reason: removing the deprecated bundled-subscriptions settings surface now would change merchant-facing and rollback/coexistence behavior while the branch remains fail-closed/default-off and the unmodified WooPayments extension can still own runtime. Core currently mirrors the standalone extension's disable-only `_wcpay_feature_subscriptions` contract; the standalone extension still uses that option to load `subscriptions-core` / bundled subscriptions behavior on rollback. A6a remains a valid future cleanup slice once release/default-on sequencing clears that plugin-active rollback window.

## Decision

A5k records a source-backed local stop line, not a product edit. There is no current local source-backed A4/A5/A6 slice to run before the production/default-on decision. The next meaningful movement is release-owner evidence and decisioning for canary parity, error-rate, production WPCOM readiness, live queue/financial/Stripe Billing data-safety, exact production perf, release sequencing, and the actual default/mandatory flips. A6a remains held, not abandoned, with the rollback/coexistence reason now recorded.
