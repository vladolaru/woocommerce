---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 17:48
target: A4as native admin readiness decision
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4aq-final-accumulated-gate.md
  - analysis-a4ar-a4aq-incomplete-coverage.md
  - review-a4ar-harness-coverage.md
  - review-a4as-admin-readiness-decision.md
  - review-a4as-code-review.md
  - staging-log.md
last_updated: 2026-06-20 18:00
status: final
---

# A4as Admin Readiness Decision Analysis

> **Prompt:** "Continue working toward the active thread goal."

## Current State

A4aq created the accumulated A4/N12 admin and checkout gate but left it incomplete. A4ar closed those two incompletes in the ignored local harness: optional protected admin routes are now driven under a temporary target account-state scenario, and perf creates real process/refund/capture fixtures for both stores. A4ar's final evidence is `data/a4ar-autoloadfix-full-1/a4aq-accumulated-gate.json`, status `pass`, with no failures and no incomplete checks. The review gate approved after fixing masking risks around optional-route over-clearance, restore exactness, WP eval-file timeouts, provider IDs, severe reference diagnostics, and account-option autoload preservation.

`WooPaymentsCutoverController::get_preflight_failures()` still defaults the native admin surfaces readiness filter to `false`, producing `native_admin_surfaces_unavailable` unless test/harness code explicitly adds `woocommerce_woopayments_native_admin_surfaces_ready => true`. This fail-closed default was correct before A4aq/A4ar. It is now the next explicit product decision after the widened N12 gate passed.

## Source Findings

`WooPaymentsCutoverController.php` applies `self::FILTER_NATIVE_ADMIN_SURFACES_READY` with default `false` and appends `native_admin_surfaces_unavailable` when the result is false. The filter remains a supported fail-closed override and should stay in place; A4as should only change the default from false to true after the stage evidence, not remove the safety valve.

`WooPaymentsCutoverControllerTest.php` has two relevant assertions. `test_preflight_blocks_when_native_admin_surfaces_are_unavailable()` explicitly adds the filter as false and should continue to pass after the default flip. `test_preflight_defaults_admin_surfaces_to_unavailable_until_n12_parity_gate_passes()` encodes the pre-A4ar state and should become the RED test for A4as: after A4ar, a preflight with native runtime, transport/platform readiness, no pending provider events, and no pending operational queue hooks should not include `native_admin_surfaces_unavailable` by default and should allow the soft notice unless another blocker exists.

Other preflight blockers remain independent. Platform connection readiness, provider event disposition, operational queue hooks, fee remediation scheduling, and legacy Stripe Billing markers still gate cutover. A4as should not claim full A5 cutover readiness unless the local preflight probe proves those are also clear in the target environment. The slice should record the target preflight state honestly.

## Decision Review

Parfit the 6th returned `STAND` in `review-a4as-admin-readiness-decision.md`: flipping the default is justified after A4ar, but only for native admin-surface readiness. The review independently called out the same boundaries: native runtime enablement, native transport/provider readiness, platform connection readiness, fee remediation scheduling, legacy Stripe Billing subscription markers, future pending event/queue filters, and mandatory auto-deactivation remain separate blockers or feature flags.

## Implementation Direction

A4as should be a small product/test/documentation slice: update the readiness default to true, update the cutover test that represented the old N12 fail-closed default, keep the explicit false filter test, run the focused PHP suite, run static checks for the touched PHP files, and run a local target preflight probe with `tools/woopayments-merge/a5-cutover-state.php`. If the target preflight still has blockers unrelated to admin surfaces, record them as remaining A5 blockers rather than suppressing them.

The decision must be recorded in `staging-log.md`, `spec-conformance-baseline.md`, `README.md`, and `implementation-log.md`. This is a readiness flag slice, not a WooCommerce product UI parity patch and not a WPCOM/platform change.

## Outcome

A4as is implemented and committed as `e500c60f96` (`fix(payments): mark native admin surfaces ready`). The changelog entry was already present in earlier commit `2555f71968`. Focused TDD proved the default-ready path and explicit false override, the full cutover controller suite passed with 37 tests and 91 assertions, static gates passed, and local target preflight evidence in `data/a4as-cutover-state.json` reports `ready: true` with no failures. The decision and code reviews both approved with no critical/high/medium findings.
