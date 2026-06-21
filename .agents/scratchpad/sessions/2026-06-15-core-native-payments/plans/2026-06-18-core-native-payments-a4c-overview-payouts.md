---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 12:47
target: A4c native WooPayments overview and payouts
reconciles:
  - ../analysis-a4c-overview-payouts.md
  - ../analysis-a4-native-woopayments-admin.md
  - ../analysis-a4b-admin-dashboard-surface.md
  - ../spec-conformance-baseline.md
  - ../supervisor-prompt-2026-06-17-1311.md
status: draft
---

# A4c Native WooPayments Overview And Payouts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the native `/woopayments/overview` provider subroute from a route shell into the first real Core-owned WooPayments merchant dashboard surface by adding balance+payout overview data, preserved deposits REST endpoints, and a real `/woopayments/payouts` route under WooCommerce > Settings > Payments.

**Architecture:** The merchant-facing route remains a Settings Payments provider subroute, because WooPayments is now a native provider and Core owns provider settings/admin orchestration. The compatibility API remains `/wc/v3/payments/deposits*`, because the platform and existing WooPayments client contract use deposits-named endpoints for payouts. Native runtime registration stays fail-closed through `NativePaymentsRuntimeArbiter::should_native_register()`, and `woocommerce_woopayments_native_admin_surfaces_ready` remains false because A4 is not complete.

**Tech Stack:** WooCommerce Core PHP DI services, provider-owned native WooPayments REST controllers, `WooPaymentsApiClient`, React/TypeScript under `client/admin/client/woopayments/admin`, WC Admin provider-route lazy chunks, Jest/RTL, PHPUnit, PHPStan, ESLint, local target/reference browser checks.

## Files and Responsibilities

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`: add deposits API methods that preserve platform paths and query names.
- Add `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDepositsRestController.php`: register native `/wc/v3/payments/deposits*` read endpoints only when native owns runtime.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`: register the deposits controller near existing native WooPayments runtime controllers.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`: cover deposits path/query/detail validation.
- Add `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsDepositsRestControllerTest.php`: cover route registration, permissions, parameter allow-listing, and API error propagation.
- Replace `plugins/woocommerce/client/admin/client/woopayments/admin/overview.tsx` with a real overview route shell that composes account settings, balance, and payout overview cards.
- Add `plugins/woocommerce/client/admin/client/woopayments/admin/overview/`: typed data helpers, balance/payout components, notices, recent payout list, page tests, and scoped styles.
- Add `plugins/woocommerce/client/admin/client/woopayments/admin/payouts.tsx` or `admin/payouts/`: a basic real payout-history route using the same preserved list endpoint, not a dead-end placeholder.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`: register `/woopayments/payouts` as a separate lazy chunk next to `/woopayments/overview`.
- Add/update JS tests under `plugins/woocommerce/client/admin/client/woopayments/admin/test/`.
- Add one WooCommerce changelog entry.

## Task 1: Native Deposits API And REST Contract

- [ ] **Step 1: Add RED API-client tests.** Add tests proving `get_deposits_overview()` calls `deposits/overview-all`, `get_deposits()` preserves `page`, `pagesize`, `sort`, `direction`, `store_currency_is`, and filters in the query string, `get_deposits_summary()` uses `deposits/summary`, and `get_deposit()` rejects invalid resource IDs while preserving valid detail paths.
- [ ] **Step 2: Add RED REST-controller tests.** Add a controller test with a recording API client proving routes register only when native owns runtime, non-managers are rejected before API calls, list/summary filter allow-lists match the reference contract, overview sends no filters, detail calls the exact deposit ID, and `WooPaymentsApiException` codes/statuses survive as `WP_Error` responses.
- [ ] **Step 3: Implement API-client methods.** Add a deposits API constant and the four read methods in `WooPaymentsApiClient`, using the existing site-scoped request pipeline and route-resource validation.
- [ ] **Step 4: Implement and register the REST controller.** Add `WooPaymentsDepositsRestController` in the provider namespace, gated by `NativePaymentsRuntimeArbiter`, with `manage_woocommerce` parity permissions, raw response envelopes, and local API-exception mapping. Register it from `class-woocommerce.php`.
- [ ] **Step 5: GREEN focused PHP.** Run the focused API-client and deposits-controller PHPUnit filters, PHP syntax on new/changed PHP, and PHPStan on production PHP.

## Task 2: Overview Balance And Payout Cards

- [ ] **Step 1: Add RED frontend data/component tests.** Cover preserved API paths, loading state, hidden empty state for a new account with no available/pending funds, pending funds waiting-period notice, suspended payouts notice, minimum-payout and negative-balance notices, recent payout rows with dispatch date/status/amount, and a history action routed through `admin.php?page=wc-settings&tab=checkout&path=/woopayments/payouts`.
- [ ] **Step 2: Build typed data helpers.** Add overview data helpers that use `apiFetch` against `/wc/v3/payments/deposits/overview-all`, `/wc/v3/payments/deposits`, `/wc/v3/payments/deposits/summary`, and `/wc/v3/payments/deposits/{id}` without plugin globals or plugin route helpers.
- [ ] **Step 3: Implement the overview page composition.** Keep `WooPaymentsAccountSettings` visible, add a balance card from overview balances, add a payouts card from overview + recent payout data, preserve reference Tracks event names for equivalent actions, and keep all copy/styles locally scoped to native WooPayments admin.
- [ ] **Step 4: Preserve defensive UI states.** Implement loading, API error, no-funds hidden, blocked payouts, waiting period, minimum payout, negative balance, and empty recent-payout states so merchant-facing behavior does not collapse into generic errors.
- [ ] **Step 5: GREEN focused JS.** Run focused Jest tests for overview data/components and routes.

## Task 3: Native Payouts Route

- [ ] **Step 1: Add RED route tests.** Assert `/woopayments/overview` and `/woopayments/payouts` are both registered as provider routes, both remain under Settings Payments, no `/payments/*` plugin-era route is registered, and each route has a separate lazy container.
- [ ] **Step 2: Implement a real payouts page.** Render a basic payout-history table from the preserved list endpoint with dispatch date, status, amount, and empty/error/loading states. Do not expose export, instant payout, or filters until their backend routes are implemented.
- [ ] **Step 3: Wire overview history action.** Make the overview action navigate to `/woopayments/payouts` only after the real route exists, and preserve the reference history-click Tracks event name.
- [ ] **Step 4: Keep bundles feature-scoped.** Ensure the overview and payouts routes remain separate lazy chunks and no WooPayments-specific frontend code lands in always-loaded generic Settings Payments code beyond route registration.

## Task 4: Slice Verification, Browser Proof, And Review Gates

- [ ] **Step 1: Static and unit gates.** Run focused PHP tests, focused JS tests, PHP syntax, PHPStan on production PHP, changed-file ESLint/TypeScript checks as applicable, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, and `git diff --check`. Do not lint scratchpad files.
- [ ] **Step 2: Harness gate.** Run the relevant `tools/woopayments-merge` verification gate with progress output, and treat any WP notices/warnings as defects to investigate instead of noise.
- [ ] **Step 3: Browser gate.** Use Chrome DevTools MCP first, with Playwright fallback if needed, to load target overview and payouts routes in `store8889.localhost:8889`, compare against the reference store for the same account state where practical, check network failures, PHP notices, route links, and visible balance/payout styling.
- [ ] **Step 4: Review gates.** Dispatch architecture/integration, API contract, frontend accessibility, and reliability/code-review subagents against the diff. Record findings before acting, fix source-backed blockers, and do not let review-only claims become undocumented context.
- [ ] **Step 5: Logs and commit.** Update `implementation-log.md`, `staging-log.md`, and the spec baseline if this slice changes any A4 disposition; add a changelog entry; commit one logical A4c change; do not push.

## Task 5: N8 Advisory Re-Check

- [ ] **Step 1: Re-read N8 after A4c closeout.** Confirm the slice did not advance A5, did not flip the native admin readiness guard, and did not leave a fake dashboard parity claim behind. Record the result in `staging-log.md` before selecting the next A4 slice.

## Self-Review

Spec coverage: this plan advances A4 by moving real overview money data and payout history into Core-owned native provider routes while preserving the existing WooPayments REST contract. It does not port transactions, disputes, reports, card readers, full payout export, instant payout mutation, or plugin-era top-level routes. It does not touch WPCOM code or remote sandbox state. Placeholder scan: no unresolved TBDs. Bundle check: overview and payouts remain lazy and WooPayments-specific.
