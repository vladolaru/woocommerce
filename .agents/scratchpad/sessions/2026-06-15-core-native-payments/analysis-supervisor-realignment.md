---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-17 15:44
target: supervisor-prompt-2026-06-17-1311.md
reconciles:
  - supervisor-prompt-2026-06-17-1311.md
  - staging-log.md
  - implementation-log.md
status: draft
---

# Supervisor Realignment Analysis

## Purpose

This artifact verifies the supervisor findings against the current source after B3ai landed and records the course correction before the next implementation slice. It is not a substitute for the stage-boundary spec gate; it is the bridge that makes the next slices coherent.

## Verified Findings

- N1 is valid. `WooPaymentsCutoverController::get_preflight_failures()` currently checks native runtime enabled and `FILTER_NATIVE_TRANSPORT_READY` / provider `can_process_payments()`, then exposes `FILTER_PREFLIGHT_FAILURES`. It does not have a native admin-surface readiness check. Current Core admin source contains Settings Payments onboarding under `client/admin/client/settings-payments/onboarding/providers/woopayments/` and only a thin `client/admin/client/woopayments/onboarding/index.ts` re-export; it does not contain native overview, transactions, disputes, deposits, reports, payment details, or card-reader app surfaces. Therefore mandatory cutover can outrun A4 merchant dashboard parity unless we add an explicit fail-closed preflight.
- N2 is valid. `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` still contains account, dispute, refund, invoice, and `wcpay.notification` event types, and `build_lifecycle_event()` throws when one of those types arrives. Failing closed remains correct while the plugin owns webhook processing, but those event types cannot silently remain known-unhandled at mandatory cutover. They need explicit disposition and a cutover gate, then implementation slices can port or intentionally defer each group with evidence.
- N3 is valid. Native Core express registration beyond card currently centers on WooPay. Reference WooPayments has distinct `includes/express-checkout/` and `client/express-checkout` paths for Apple Pay, Google Pay, and Amazon Pay, plus settings and domain-registration behavior. The native grep finds WooPay express registration and only wallet label helpers for Apple/Google in provider notes, not a native Apple/Google/Amazon express frontend/runtime counterpart. This must be tracked as a separate A3-family shopper-facing package, not treated as covered by B3ai.
- N4 is valid. The `B3x` through `B3ai` labels in the current staging log are payments-runtime/admin/checkout backfill work. Canonical B3 is multi-currency cleanup. The true status of canonical multi-currency B3 cleanup is not started: B2 delivered many native multi-currency runtime/frontend/admin/compatibility slices, but cleanup still depends on confirming no WooPayments-provider-scoped multi-currency runtime/compat duplication remains and on running the B3 cleanup gates. New payments backfill slices should use a neutral harness-backfill sequence and explicit canonical stage tags rather than extending `B3*`.
- N5 is valid as a process gap. Per-package code review has been effective for local defects, but it does not prove accumulated A3/A4/A5 stage conformance against `design-spec.md`, `bc-manifest.md`, and the implementation plan. A standalone stage-boundary spec-conformance gate is now required before any stage is declared exited, especially before A4/A5 cutover readiness.

## Course Correction

- Stop creating new payments work packages with `B3*` labels. Use `H##` for harness/backfill packages or true `A3*` / `A4*` / `A5*` labels when the slice belongs cleanly to one canonical stage. Each staging-log entry must include a `Canonical stage:` line.
- Add a stage-mapping index to `staging-log.md` so existing `B3x` through `B3ai` entries are auditable as payments backfill rather than multi-currency B3 cleanup.
- Treat canonical multi-currency B3 cleanup as open until explicitly planned, implemented, verified, reviewed, and logged.
- Next implementation package should be an A5 cutover guardrail slice, tentatively `H13 / A5 cutover interlock`, that makes cutover preflight fail closed while native admin surfaces are incomplete and while known provider event types remain undispositioned. This should not try to port every admin screen or every event in one commit; it should make the unsafe cutover impossible and create explicit, test-covered readiness seams for the subsequent A4/A5 work.
- Non-WooPay express checkout should be a subsequent A3-family package after the cutover guardrails are in place, because it is shopper-facing and large enough to deserve its own reference comparison, frontend assets, settings parity, and browser gates.
- Before declaring any stage complete, run a fresh stage-boundary spec-conformance review agent against accumulated source plus `design-spec.md`, `bc-manifest.md`, implementation plan stage gates, and staging-log evidence. Record the result in `staging-log.md`.

## Immediate Next Slice

The next slice should not be dashboard porting yet; that would be too broad and would still leave mandatory cutover unsafe while it is in progress. The high-leverage next slice is the fail-closed cutover interlock: add native admin-surface readiness and provider-event disposition readiness to `WooPaymentsCutoverController` preflight, with default-off/false readiness until the corresponding A4/A5 packages complete. This directly addresses N1 and N2, keeps the current branch from pretending A5 is ready, and gives later admin/event packages concrete green gates to satisfy.
