---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 14:11
last_updated: 2026-06-18 14:12
target: A4d native WooPayments money movement admin surfaces
reconciles:
  - ../analysis-a4d-money-movement.md
  - ../analysis-a4-native-woopayments-admin.md
  - ../analysis-a4c-overview-payouts.md
  - ../spec-conformance-baseline.md
  - ../staging-log.md
  - ../supervisor-prompt-2026-06-17-1311.md
status: draft
---

# A4d Native WooPayments Money Movement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development for implementation tasks and $dispatching-parallel-agents only where write sets are explicitly disjoint. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the native WooPayments money-movement admin surface under WooCommerce > Settings > Payments provider routes, preserving the reference transactions, payment-details, disputes, and dispute-challenge contracts without flipping the native admin readiness guard.

**Architecture:** WooPayments money movement is a provider-owned native admin surface. Browser routes live as WooPayments provider subroutes under the Settings Payments shell (`/woopayments/*`), while compatibility REST routes remain `/wc/v3/payments/*` because the existing WooPayments client, mobile flows, and platform contracts already speak those paths. Backend controllers are provider-owned and gated by `NativePaymentsRuntimeArbiter::should_native_register()`. Frontend code is a separate lazy money-movement chunk so merchants that do not use WooPayments do not pay for it in always-loaded Settings Payments bundles.

**Tech Stack:** WooCommerce Core PHP DI services, `WooPaymentsApiClient`, native WooPayments REST controllers, React/TypeScript in `client/admin/client/woopayments/admin`, WC Admin provider-route lazy chunks, Jest/RTL, PHPUnit, PHPStan, ESLint, Playwriter browser checks, restored `tools/woopayments-merge` harness.

## Files and Responsibilities

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`: add transaction/dispute list, summary, export, search, detail, update, and close methods needed by native admin routes.
- Add `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsTransactionsRestController.php`: preserve `/wc/v3/payments/transactions*` admin routes with native gating and permissions.
- Add `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputesRestController.php`: preserve `/wc/v3/payments/disputes*` admin routes with native gating and permissions.
- Add detail-support REST coverage as required by the implemented UI for `/wc/v3/payments/charges*`, `/wc/v3/payments/payment_intents*`, `/wc/v3/payments/timeline*`, `/wc/v3/payments/authorizations*`, `/wc/v3/payments/refund`, `/wc/v3/payments/file*`, and card-reader fee detail endpoints.
- Add request-filter helpers modeled after `WooPaymentsDepositsListRequest` for `wcpay_list_transactions_request` and `wcpay_list_disputes_request`.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`: register the new native REST controllers near the existing WooPayments runtime controllers.
- Add/update PHP tests under `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/`.
- Add `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/`: typed data helpers, list/detail/challenge route components, tables, filters, notices, empty/loading/error states, styles, and tests.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`: register `/woopayments/transactions`, `/woopayments/transactions/details`, `/woopayments/disputes`, `/woopayments/disputes/details`, and `/woopayments/disputes/challenge` as one dedicated lazy money-movement route group.
- Add one WooCommerce changelog entry.

## Subagent Strategy

- Backend implementor: owns only PHP API-client/REST/request-helper/tests and `class-woocommerce.php` registration.
- Frontend implementor: owns only `client/admin/client/woopayments/admin/money-movement/**`, route registration, styles, and JS tests.
- Controller stays local for architecture decisions, test orchestration, patch reconciliation, browser/harness gates, and final docs/commit.
- Review subagents run after implementation: API contract, architecture/integration, accessibility, reliability, and code quality. Any source-backed blocker is fixed before commit.

## Task 1: Backend Money-Movement REST Contracts

- [ ] **Step 1: Add RED API-client tests.** Prove transaction methods hit `transactions`, `transactions/summary`, `transactions/download`, `transactions/download/{id}`, `transactions/search`, and `transactions/{id}` while preserving query params and rejecting invalid route IDs. Prove dispute methods hit `disputes`, `disputes/summary`, `disputes/download`, `disputes/download/{id}`, `disputes/{id}`, and `disputes/{id}/close`, and that update sends evidence, submit, and metadata without dropping fields.
- [ ] **Step 2: Add RED REST-controller tests.** Assert route registration is native-gated, non-managers are rejected before API calls, list request filters preserve `wcpay_list_transactions_request` and `wcpay_list_disputes_request`, date/timezone and filter mappings match reference behavior, export/search/detail/update/close methods call the exact API client method, fraud-outcome endpoints remain explicitly dispositioned, and API exceptions produce visible REST errors rather than silent fall-through.
- [ ] **Step 3: Implement API-client methods.** Add platform-path constants/methods using the existing `request()` pipeline, raw arrays, route ID validation, and no WPCOM changes.
- [ ] **Step 4: Implement request helpers.** Add small native list request classes that preserve default sort/page params and expose `get_params()` for existing filters, without importing WooPayments extension classes.
- [ ] **Step 5: Implement and register controllers.** Add transactions/disputes controllers with `manage_woocommerce` permissions, native runtime gating, filtered query extraction, raw response envelopes, and local API-exception mapping. Register from `class-woocommerce.php`.
- [ ] **Step 6: GREEN focused PHP.** Run focused API-client/controller PHPUnit, PHP syntax on touched PHP, PHPStan on production PHP, and changed-line PHPCS. Treat warnings/notices as defects.

## Task 2: Frontend Money-Movement Data And Routes

- [ ] **Step 1: Add RED route/data tests.** Assert all five native provider subroutes register under Settings Payments, no plugin-era `/payments/*` route is introduced, the route group lazy-loads as a WooPayments money-movement chunk, data helpers use `/wc/v3/payments/*` paths, and API failures render merchant-visible errors.
- [ ] **Step 2: Build typed data helpers.** Add helpers for transactions list/summary/search/export/detail, disputes list/summary/export/detail/update/close, payment intent, charge, timeline, authorization, refund, file/evidence, and card-reader fee detail fetches as required by the UI. Keep helpers local to the money-movement route group and use `apiFetch` directly with preserved query names.
- [ ] **Step 3: Implement route composition.** Add transactions list, transaction detail, disputes list, dispute detail, and dispute challenge route components with shared loading/error/empty states, accessible headings, tables, row links, status badges, and scoped styles.
- [ ] **Step 4: Preserve navigation contracts.** Link transaction rows to `/woopayments/transactions/details`, dispute rows/details to the native details/challenge routes, and redirect dispute details consistently with reference behavior where the source route is only an alias to transaction details.
- [ ] **Step 5: Preserve state, preferences, and Tracks contracts.** Preserve relevant store/query contracts and user preference keys such as `wc_payments_transactions_hidden_columns`, `wc_payments_transactions_blocked_hidden_columns`, `wc_payments_transactions_uncaptured_hidden_columns`, and `wc_payments_disputes_hidden_columns` where the native UI implements equivalent tables. Keep reference event names for surviving actions in this slice, including page-view paths, CSV exports, fraud/dispute row actions, dispute evidence events, and transaction-detail modal/action events where those actions are present. If a destructive action is deferred, record that as an explicit A4 follow-up and do not emit misleading completion events.
- [ ] **Step 6: GREEN focused JS.** Run focused Jest tests and changed-file ESLint/TypeScript checks for the money-movement frontend.

## Task 3: Money-Safety And Dispute-Safety Detail Actions

- [ ] **Step 1: Decide action scope from source, not convenience.** Verify whether refund/capture/cancel/dispute-challenge actions can be completed cleanly in this slice with existing native REST support. If yes, include their route/API/frontend tests. If no, fail closed with explicit UI/state and record a canonical A4 follow-up before proceeding.
- [ ] **Step 2: Preserve payment-detail dependencies.** Add native REST coverage for `/wc/v3/payments/payment_intents/{id}`, `/wc/v3/payments/charges/{id}`, `/wc/v3/payments/charges/order/{order_id}`, `/wc/v3/payments/timeline/{id}`, authorization capture/cancel routes, refund routes, and card-reader fee detail routes if the implemented detail UI needs those contracts.
- [ ] **Step 3: Preserve dispute evidence dependencies.** Add native REST coverage for `/wc/v3/payments/file` and `/wc/v3/payments/file/{id}/details` if the challenge route implements evidence upload/submitted-evidence details in this slice.
- [ ] **Step 4: Prove no silent money/dispute gaps.** Destructive or evidence-submission paths must be either implemented and tested against reference-compatible payloads or visibly unavailable with the admin readiness guard still false. No route should present as complete while silently dropping money/dispute actions.

## Task 4: Browser, Harness, And Parity Gates

- [ ] **Step 1: Static/unit gates.** Run focused PHP tests, focused JS tests, PHP syntax, PHPStan, changed-file PHPCS/ESLint/TS checks, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, and `git diff --check`. Do not lint scratchpad files.
- [ ] **Step 2: Harness gate.** Run the restored `tools/woopayments-merge` verification with progress output and record any WP notices/warnings as defects, even if the harness itself exits green.
- [ ] **Step 3: Playwriter browser gate.** Use Playwriter to load target money-movement routes on `store8889.localhost:8889`, compare reference routes on `localhost:8082` where account data exists, inspect failed network responses, verify route links from overview/payouts where relevant, and check visual styling against reference rather than settling for functional tables.
- [ ] **Step 4: Log gate.** Inspect target/reference Docker and debug logs for new PHP/WP notices, warnings, deprecations, fatals, uncaught errors, stack traces, and database errors during the verification window.
- [ ] **Step 5: Review gates.** Dispatch API contract, architecture/integration, accessibility, reliability, and code-quality reviewers against the source diff. Record findings before acting and fix source-backed blockers.

## Task 5: Docs, Stage State, And Commit

- [ ] **Step 1: Update session docs.** Append A4d evidence to `implementation-log.md` and `staging-log.md` at their markers. Update the spec baseline only if this slice changes A4 disposition or reveals a new blocker.
- [ ] **Step 2: Changelog and commit.** Add a WooCommerce changelog entry, stage only the logical A4d source/tests/changelog diff, commit with a Conventional Commit message, and do not push.
- [ ] **Step 3: Guard re-check.** Confirm `woocommerce_woopayments_native_admin_surfaces_ready` remains false and N8 remains satisfied because A4 is still in progress until the complete admin surface set passes the stage gate.

## Self-Review

Spec coverage: this plan advances A4 by moving the reference money-movement admin cluster into Core-owned native provider routes while preserving the existing `wc/v3/payments/*` compatibility contract. It explicitly keeps WooPayments-specific frontend code out of always-loaded Settings Payments bundles and keeps the native admin readiness guard false until full A4 exits. Placeholder scan: no unresolved TBDs. Risk: payment detail and dispute challenge can expand quickly; fail closed rather than shipping a convincing but incomplete money/dispute UI.
