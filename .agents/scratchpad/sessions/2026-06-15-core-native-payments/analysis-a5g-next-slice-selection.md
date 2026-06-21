---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 18:38
target: A5 next slice after A5f
reconciles:
  - README.md
  - analysis-a5-next-slice-reground.md
  - plans/2026-06-20-core-native-payments-a5f-post-a4as-cutover-rehearsal.md
  - staging-log.md
  - spec-conformance-baseline.md
status: draft
last_updated: 2026-06-20 18:47
---

# A5g Next-Slice Selection

> **Prompt:** "Remember to constantly record your progress so it survives compactions and you don't redo your steps after a compaction."

## Current Baseline

A5f is green as the post-A4as local cutover handoff rehearsal. The evidence rollup is `data/a5f-post-a4as-cutover/a5f-cutover-rehearsal.json`; it passed the current native baseline, soft one-click disable, opt-in mandatory auto-deactivation, activation guard, synthetic blocked mandatory path, local WPCOM readiness, owner user-token readiness, WCPay V1 transport continuity, cleanup, and target debug-log scan. A5f changed only ignored local harness and scratchpad evidence, not WooCommerce product code.

The canonical A5 contract is broader than A5f. The implementation plan's A5 stage requires soft notice, mandatory auto-deactivation gated on `WC_VERSION >= X` or a default-on flag, activation guard, webhook and Action Scheduler continuity, account/connection owner-token and registry checks, financial migration ensure-applied, multisite per-site plus network semantics, plugin reactivation returning to plugin-wins before mandatory rollout, and canary parity/error/perf before flipping the mandatory default.

Current source aligns with the safe parts but not with a rollout/default-on decision. `WooPaymentsCutoverController::FILTER_MANDATORY_CUTOVER_ENABLED` still defaults false, `maybe_auto_deactivate_plugin()` only runs when that filter is enabled, and the activation guard only blocks WooPayments reactivation when the same mandatory filter is true and preflight is clean. `NativePaymentsRuntimeArbiter` comments still describe eventual default-on at the cutover release, while `Packages.php` remains the generic merged-package precedent rather than a WooPayments-specific rollout implementation.

## Candidate Next Slice

The likely next slice is A5g: a cutover rollout/default-on and multisite gate. It should remain fail-closed: first source-verify the current product/test coverage for single-site, multisite per-site, and network-active deactivation; then add or widen tests/harness gates for the uncovered states; finally decide whether a product rollout seam is needed beyond the existing filter. If a product seam is needed, it should be version/default-flag gated, still bypassable for developer parallel testing through `WC_ALLOW_MERGED_FEATURE_PLUGINS`, and still blocked by all existing preflight failures.

This is more load-bearing than small A5f harness hygiene. A5f reviewer caveats about top-level rollup keys and stale `a5e` labels are real but not the next product risk. They can be folded into the A5g harness cleanup if the same files are touched.

This also means A6 should not start yet. The old A6 plan removes transition harness scaffolding, but the harness is currently intentionally restored and gitignored for local verification. Canonical A6 cleanup depends on a true A5 exit and Bucket-D drop readiness, not merely on A5f passing.

## Immediate Checks

Before planning implementation, source-check `WooPaymentsCutoverControllerTest` and current ignored A5 harness for multisite/network coverage, then ask a decision reviewer to challenge the slice selection against the canonical A5/A6 docs and current source. The reviewer returned `STAND`: A5g is the right next slice over A6 cleanup or A5f-only hygiene, scoped as rollout/default-on gate plus multisite runtime proof, not complete production default flip.

## Source Check

`WooPaymentsCutoverControllerTest` already has focused PHP coverage for the core single-site and network-active mechanics: soft disable deactivates the per-site plugin with `network_wide=false`, mandatory auto-deactivation is capability-independent when the mandatory filter is enabled, the activation guard stays default-off, the developer bypass through `WC_ALLOW_MERGED_FEATURE_PLUGINS` is honored, and `test_network_active_woopayments_deactivates_network_wide()` verifies a network-active WooPayments plugin is deactivated with the network-wide flag.

That PHP coverage is not the same as the A5 multisite runtime gate. The A5f orchestrator only targets the local single-site `store8889.localhost:8889` state and records no multisite or network-active browser/runtime proof. `a5-cutover-state.php` includes network-active plugin slugs when `is_multisite()` is true, but A5f does not run that state. This keeps A5f honest as a single target-store handoff baseline, not the canonical A5 T5 multisite gate.

The product rollout seam is still default-off. `WooPaymentsCutoverController::is_mandatory_cutover_enabled()` returns `apply_filters( FILTER_MANDATORY_CUTOVER_ENABLED, false )`, and `NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED` also defaults false with comments saying native becomes default-on at the cutover release. There is no source-backed `WC_VERSION >= X` check or release/default-on option yet. This means A5 cannot be called exited until the rollout decision is either implemented behind an explicit gate or recorded as intentionally deferred with fail-closed behavior.

## Reviewer Disposition

Decision reviewer `Sartre the 6th` confirmed the source/spec read. The important nuance is that A5g should not pretend to flip the final production switch before the full canary/perf/error and stage-boundary gates are done. It should either introduce the explicit release/default-flag seam needed for the later switch, or record an intentional fail-closed deferral; preserve all existing preflight blockers and `WC_ALLOW_MERGED_FEATURE_PLUGINS`; add multisite per-site and network-active runtime/harness evidence; and keep A5f harness hygiene incidental.

Explorer `Wegener the 6th` confirmed the target `:8889` wp-env is single-site by configuration and should not be converted for this proof. The A5g multisite runtime gate should run in a disposable local wp-env, prove only runtime ownership across a real multisite, and avoid WPCOM/account/platform probes. The minimal runtime matrix is: multisite on; native owns both sites with WooPayments inactive; per-site WooPayments activation makes only that site plugin-owned; network activation makes all sites plugin-owned; network deactivation returns all sites to native-owned. Recommended files are `tools/woopayments-merge/a5g-multisite-runtime-state.php` and `tools/woopayments-merge/a5g-multisite-runtime-gate.py`.
