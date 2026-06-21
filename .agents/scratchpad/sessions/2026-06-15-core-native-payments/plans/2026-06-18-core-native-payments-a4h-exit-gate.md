---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 21:04 EEST
status: active
---

# Core Native Payments A4h Exit Gate Plan

> **For agentic workers:** Use `$subagent-driven-development` or `$executing-plans`. Keep edits scoped and do not touch WPCOM code or scratchpad linting.

**Goal:** Close A4 through the N10 exit gate by fixing native admin route drift, proving the Settings > Payments provider-route architecture, measuring native admin bundle/chunk separation, checking the single WordPress data registry claim, and writing the route/bundle decision back into the baseline before A5.

## Scope

- [ ] Fix native merchant/admin URL drift so native runtime fallbacks and order-note links point to `admin.php?page=wc-settings&tab=checkout&path=/woopayments/...` rather than plugin-era `wc-admin&path=/payments/...`.
- [ ] Refresh stale JS route bootstrap coverage so it asserts the accumulated WooPayments provider route set, deterministic route ownership, and no `/payments/*` registered paths.
- [ ] Add or extend a lightweight harness/source gate for native WooPayments admin chunks and single-registry assertions. This should be a measurement/proof helper, not a broad product refactor.
- [ ] Run focused PHP/JS tests, source lint/static checks, admin build or relevant bundle generation, the harness bundle/admin gate, browser smoke for the main A4 routes, and log scans.
- [ ] Update `spec-conformance-baseline.md`, `staging-log.md`, and implementation notes with the route architecture decision, measured evidence, remaining A4 dispositions, and whether the admin readiness cutover filter can be safely changed.
- [ ] Review N8 after A4h is cleanly closed, per supervisor instructions.

## Boundaries

Do not port Reports, Documents, fraud-protection advanced editor, or order-admin adjuncts in A4h unless source verification shows they are already mandatory for the A4 exit contract. If they remain conditional or separate canonical work, record them as dispositions rather than hiding them inside a green A4 gate. Do not flip `woocommerce_woopayments_native_admin_surfaces_ready` unless the exit evidence supports it and a focused preflight regression is added.

## Delegation

Use one worker for the harness/source gate only, with ownership of `tools/woopayments-merge/*` and optional focused docs under the A4h analysis if needed. Keep PHP/JS product-code fixes local to avoid conflicts.

## Verification

Targeted verification must include `WooPaymentsOnboardingAdapterTest`, `WooPaymentsEventIngestorTest` or narrower dispute URL coverage, affected gateway/AJAX URL tests if added, `register-provider-routes.test.tsx`, `woopayments/admin/test/routes.test.tsx`, relevant ESLint/PHPStan/lint checks, `pnpm build:project:bundle` or the minimum build required for admin chunks, the new admin surface gate, existing bundle gate capture/compare where useful, Playwriter route smoke on the target store, and debug log scans.
