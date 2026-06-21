---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 02:03
last_updated: 2026-06-20 02:08
target: A4/N12 reopen after A5e
reconciles:
  - supervisor-prompt-2026-06-17-1311.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - spec-conformance-baseline.md
  - staging-log.md
status: final
---

# A4ac Admin Readiness Reground Analysis

## Trigger

After A5e closed, the next queued task was to re-read N8 and N12, reopen A4 for feature parity, and avoid treating the existing A4ab smoke/runtime gate as the full N12 parity bar.

## Source-Verified Findings

N8 is an explicit sequencing nudge: H14-H31 made the N7 baseline sufficient enough to stop widening N7 indefinitely and enter A4. It does not say A4 feature parity is complete; it says A4 admin and provider surfaces are the next canonical blockers and that later stages should be gated by N5.

N12 is stricter than the A4ab/A5d outcome now recorded in the branch. It says native admin readiness must fail closed until merchant reachability plus per-surface functional/visual/copy parity are verified, and that only when those pass plus existing architecture gates may `FILTER_NATIVE_ADMIN_SURFACES_READY` default true.

The recorded A4ab baseline does not satisfy that N12 bar. `spec-conformance-baseline.md` and `staging-log.md` both say A4ab is a route/chunk/browser/log smoke/runtime baseline, does not claim full pixel/copy parity, exhaustive account-state coverage, or disabled-feature no-REST/no-chunk coverage, and does not flip native admin readiness.

The current source does flip native admin readiness. `WooPaymentsCutoverController::get_preflight_failures()` calls `apply_filters( self::FILTER_NATIVE_ADMIN_SURFACES_READY, true )`, so an otherwise-clean preflight no longer reports `native_admin_surfaces_unavailable`. `WooPaymentsCutoverControllerTest::test_preflight_defaults_to_ready_after_native_admin_surfaces_are_verified()` asserts that default-ready behavior.

This means A5d is ahead of the documented N12/A4 parity contract. Because A4ab explicitly says the parity bar is not complete and because A5e proves the plugin-active handoff path, the next clean slice should first restore fail-closed native admin readiness, then continue A4 parity work against the remaining N12 residuals.

## Decision

A4ac should be a corrective reground slice, not a new feature slice: restore the native admin readiness default to fail-closed, keep the explicit readiness filter override as the only way local gates can prove handoff in controlled tests, update the tests and docs so A4ab remains a baseline rather than an authorization to cut over, and then select the next source-backed A4 parity chunk.

This decision was implemented and committed as `4d1d643d05` plus changelog `39cd54073d`.

## Candidate Implementation Scope

Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php` so `FILTER_NATIVE_ADMIN_SURFACES_READY` defaults to `false` again.

Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php` by replacing the A5d default-ready test with a RED/GREEN default-blocking test and keeping explicit `__return_true` filters in the existing tests that need to exercise other blockers or the handoff path.

Add a WooCommerce changelog entry that describes the readiness correction.

Update `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md` to record the correction and preserve A4ab as smoke/runtime evidence, not a full N12 parity authorization.

## Verification Required

Run focused `WooPaymentsCutoverControllerTest`, the related readiness suite, PHP syntax for the controller/test, PHPStan for the controller, changed-file PHPCS, changelog validation, `git diff --check -- . ':!.agents'`, branch lint, and a target preflight/state probe or browser-safe evidence showing the admin readiness blocker is present unless the readiness filter is explicitly true.

No WPCOM sandbox access, no WPCOM code changes, no push, and no scratchpad linting.
