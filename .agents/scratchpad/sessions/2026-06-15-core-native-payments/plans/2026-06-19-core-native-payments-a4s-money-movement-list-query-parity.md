---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 10:45 EEST
last_updated: 2026-06-19 11:00 EEST
target: A4s native WooPayments money movement list and query parity
reconciles:
  - ../analysis-a4s-admin-surfaces-next-slice.md
  - ../supervisor-prompt-2026-06-18-2344-N12.md
  - ../staging-log.md
  - 2026-06-18-core-native-payments-a4d-money-movement.md
status: draft
---

# A4s Money Movement List And Query Parity Plan

> **For agentic workers:** REQUIRED SUB-SKILL: use `$subagent-driven-development` for implementation tasks and `$dispatching-parallel-agents` only where write sets are disjoint. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the simplified native WooPayments Payouts, Transactions, and Disputes list surfaces with reference-like Settings > Payments provider subroute shells that honor URL query state, filters/search/sort/pagination, summaries, exports, persisted table preferences, and the visual/copy/accessibility contracts expected from WooPayments money movement pages.

**Architecture:** Keep WooPayments-specific money movement frontend isolated in the existing lazy `settings-payments-woopayments-payouts` and `settings-payments-woopayments-money-movement` chunks. Routes remain native provider subroutes under Settings > Payments (`/woopayments/*`); plugin-era `/payments/*` remains redirect-only compatibility. REST calls continue to use the existing native `/wc/v3/payments/*` compatibility contract. Native admin readiness remains fail-closed until the widened A4/N12 gate passes.

**Scope boundary:** This slice owns list/query parity and only the detail behavior required by those lists to avoid dead navigation: payout detail embedded transactions, dispute details redirect loading/error behavior, and existing transaction detail links. Full payment-detail action parity and full dispute challenge reference stepper parity stay as tracked A4 follow-ups unless the existing native endpoint contract supports a clean implementation without frontend masking.

## File Structure

- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts`: enrich transaction, dispute, summary, export, table-column, query, and status types.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/data.ts`: add URL-query-compatible request helpers, summary/export helpers, and fraud-outcome helpers already backed by native REST.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/query.ts`: shared query parsing, serialization, route navigation, and defaults for provider subroutes.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dataviews.tsx` or `table.tsx`: shared list shell. Prefer `@wordpress/dataviews/wp` if the RED tests prove it naturally supports the WooPayments query/toolbar/pagination/hidden-column behavior; fall back to a scoped table shell only if DataViews forces awkward styling, cannot support summaries/exports cleanly around the table, or cannot preserve the provider-route query contract.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/table-preferences.ts`: persisted column visibility wrapper using existing WooCommerce/WP user preference patterns or a source-verified local fallback if no user preference API is available in this bundle.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/list-controls.tsx`: shared filter/search/export/table-control primitives with accessible labels and predictable focus behavior for controls not owned by DataViews.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transactions-page.tsx`: implement URL-driven Transactions/Uncaptured/Blocked list shells, rich columns, search, summary, export, and persisted preferences where backend support exists.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/disputes-page.tsx`: implement URL-driven disputes list shell with filters, summary, export, status/due-date/action rendering, and row links that match reference routing.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/disputes-details.tsx`: add visible loading/error redirect behavior and preserve canonical navigation to transaction details.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/payouts.tsx`: implement reference-like payouts list shell with filters, summary, export, schedule/failure/test-mode notices where data exists, persisted columns, sort, pagination, and row navigation.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/payout-details.tsx`: add failed-payout banner/copyable bank reference and embedded transaction list filtered by payout when native APIs support the required query.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/data.ts` only if payout export/download URL helpers must live with deposits data rather than money movement data.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss` and/or scoped money movement styles: port only necessary styling for list shells, controls, status chips, table density, responsive behavior, and focus states.
- Modify or add Jest/RTL tests under `plugins/woocommerce/client/admin/client/woopayments/admin/test/` for query/data/table/list behavior.
- Modify `tools/woopayments-merge/a4-admin-surface-gate.py` only to widen real A4s coverage, never to mask a native bug.
- Add one WooCommerce changelog entry.

## Subagent Strategy

- Worker 1: owns shared query/data/table infrastructure and tests under `money-movement/query.ts`, `money-movement/table-preferences.ts`, `money-movement/list-controls.tsx`, `money-movement/table.tsx`, `money-movement/data.ts`, and matching unit tests.
- Worker 2: owns transactions/disputes list pages and tests under `money-movement/transactions-page.tsx`, `money-movement/disputes-page.tsx`, `money-movement/disputes-details.tsx`, and matching page tests.
- Worker 3: owns payouts list/detail and tests under `payouts.tsx`, `payout-details.tsx`, deposit data helper changes, and matching payout tests.
- Main agent: owns source verification, worker integration, conflict resolution, styling/copy/accessibility review, harness/Playwriter/browser verification, staging/implementation logs, changelog, and commit.
- Reviewers after implementation: API contract reviewer, accessibility reviewer, architecture/integration reviewer, reliability reviewer, and code-quality reviewer. Any source-backed blocker is fixed before commit.

## Task 1: Source-Verify Local UI Building Blocks

- [ ] Confirm which Core admin components are already available in this bundle for `TableCard`, `Search`, `SummaryList`, `TabPanel`, notices, chips, and user preferences. Prefer existing WooCommerce/Core components over recreating plugin-local abstractions.
- [ ] Source-verify `@wordpress/dataviews/wp` against existing WooCommerce usage in settings email and the experimental products app. Use DataViews for table/list shells when it can own search, filters, sorting, pagination, and field hiding without fighting the reference money-movement UX. Do not force DataViews to mimic `TableCard` pixel-for-pixel.
- [ ] Confirm the reference hidden-column preference keys and URL query names for payouts, transactions, blocked transactions, uncaptured transactions, and disputes.
- [ ] Confirm backend support for `loan_id_is`, `deposit_id`, `currency_is`, date filters, search params, fraud outcomes, summaries, and export/download flows. Any missing endpoint becomes a tracked A4 follow-up rather than a fake UI affordance.
- [ ] Confirm route navigation semantics inside the Settings > Payments provider router so query updates do not clobber `page=wc-settings&tab=checkout&path=/woopayments/...`.

## Task 2: Shared List Infrastructure

- [ ] Add RED tests for query parsing/serialization that preserve provider subroute paths, default `page`, `pagesize`, `sort`, and `direction`, and supported filter params such as `loan_id_is`, `deposit_id`, `currency_is`, `type_is`, `status_is`, `created_after`, and `created_before`.
- [ ] Add RED tests for data helpers that assert native `/wc/v3/payments/*` list, summary, export, and download paths are called with the exact query names native REST supports.
- [ ] Implement query/data helpers and table preference persistence without importing WooPayments extension stores or `window.wcpaySettings`.
- [ ] Upgrade the shared table/control components with source-verified DataViews usage where natural, otherwise semantic table markup. Cover accessible search/filter/export controls, loading/error/empty states, sortable headers, pagination, hidden-column controls, and visible focus states.
- [ ] GREEN focused infrastructure tests.

## Task 3: Transactions And Disputes Lists

- [ ] Add RED page tests for transaction query-driven fetching, list summary, search, sort, pagination, column persistence, export trigger, tab visibility, `loan_id_is` filtering, and row links to native transaction details.
- [ ] Add RED page tests for dispute query-driven fetching, list summary, filters, due-date copy, status chips, export trigger, row/action links, and visible redirect loading/error behavior.
- [ ] Implement the Transactions list with reference-like columns that can be populated from the native REST payload, including date/time, type, sales channel, paid amount/currency where available, payout amount/currency where available, fees, net, order/subscription references, payment method, customer/email/country, risk where available, payout ID/date/status, and details links.
- [ ] Implement the Blocked tab only against source-verified fraud outcome endpoints. Implement Uncaptured only if native authorizations endpoints are already present; otherwise record a blocker/follow-up and do not present an inert tab.
- [ ] Implement the Disputes list with reference-like columns, status/due-date/action rendering, summary totals, and native challenge/detail routing that does not bypass transaction detail where the reference routes through it.
- [ ] GREEN focused transactions/disputes Jest tests.

## Task 4: Payouts List And Payout Detail

- [ ] Add RED page tests for payout query-driven fetching, list summary, filters, sorting, pagination, column persistence, export trigger, notices, status chips, and row links to native payout details.
- [ ] Add RED page tests for payout detail failed-payout banner, copyable bank reference, payout/withdrawal overview, and embedded transactions filtered by payout/deposit ID when supported by native REST.
- [ ] Implement the Payouts list with reference-like columns and controls using the deposits REST contract: details, date, type, amount, status, bank account, and bank reference ID.
- [ ] Implement payout notices only from source-backed native data. Do not invent schedule/failure account state if it is not present in the native account/deposit contract.
- [ ] Implement payout detail enrichment and embedded transactions through the shared list infrastructure, preserving copyable values and accessible button/link names.
- [ ] GREEN focused payout Jest tests.

## Task 5: Harness And Browser Gate Widening

- [ ] Extend `tools/woopayments-merge/a4-admin-surface-gate.py` to assert the A4s route/query contract, lazy chunk boundaries, no plugin-era route resurrection, Reports/Documents absence, and basic list-control selectors for Payouts, Transactions, and Disputes. Keep progress output and do not suppress WP notices/warnings.
- [ ] Use Playwriter to verify target native routes on `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/...` and reference routes on `http://localhost:8082/wp-admin/admin.php?page=wc-admin&path=/payments/...` where data is present.
- [ ] Verify route chrome, filters/search/sort/pagination, export buttons, row links, payout detail, dispute redirect behavior, loading/error states, and visual parity against reference screenshots. Record any unavailable data honestly.
- [ ] Inspect browser console/network failures and target/reference logs for PHP/WP notices, warnings, deprecations, fatals, uncaught errors, and failed REST calls during the verification window.

## Task 6: Review, Docs, And Commit

- [ ] Run focused Jest tests, changed-file ESLint/TypeScript checks, frontend build for affected chunks, PHP tests only if backend/harness code changes require them, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, and `git diff --check`. Do not lint `.agents`.
- [ ] Dispatch review subagents over the integrated diff: API contract, accessibility, architecture/integration, reliability, and code quality. Fix source-backed blockers before committing.
- [ ] Update `implementation-log.md`, `staging-log.md`, and if needed `spec-conformance-baseline.md` with A4s evidence and follow-ups.
- [ ] Add a WooCommerce changelog entry, stage only the logical A4s diff, commit with a Conventional Commit message, and do not push.
- [ ] Keep the standing task: after A5c is fully done, re-open A4 against N12 for native admin feature parity.

## Exit Criteria

- Payouts, Transactions, and Disputes lists are merchant-reachable under native Settings > Payments provider routes and no longer render fixed first-page simple tables.
- Query state, supported filters, sort, pagination, summaries, exports, hidden-column preferences, row links, notices, and status/due-date copy behave as close to reference as the native REST contract allows.
- Unsupported money/dispute actions are not hidden by fake frontend success paths; they are either implemented with tests or tracked as A4 follow-ups.
- WooPayments-specific money movement JS/CSS remains in WooPayments lazy chunks and does not inflate always-loaded Settings Payments code.
- Native admin readiness remains fail-closed, Reports/Documents remain absent until a real port, and the A4/N12 staging log records the remaining blockers honestly.
