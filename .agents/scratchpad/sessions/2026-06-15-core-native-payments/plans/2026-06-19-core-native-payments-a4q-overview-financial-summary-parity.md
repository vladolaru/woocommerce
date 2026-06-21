---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 08:44
target: A4q native WooPayments Overview financial summary parity
reconciles:
  - analysis-a4q-overview-shell-parity.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - plans/2026-06-19-core-native-payments-a4q-overview-shell-parity.md
status: draft
---

# A4q Overview Financial Summary Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore reference-level WooPayments Overview balance and payout summary behavior in the native admin route using the existing Core-owned deposits overview/list APIs.

**Architecture:** Keep route ownership under Settings > Payments `/woopayments/overview` and keep the existing deposits REST seams. Add the missing frontend projection and component behavior in the WooPayments admin chunk only: derive currencies from the deposits overview payload, keep selected currency local to the Overview page, reload recent payouts for that selected currency, and render reference copy/actions/styles without plugin globals or plugin data stores.

**Tech Stack:** React/TypeScript, WordPress i18n/components, WooCommerce admin URL helpers, Jest/RTL, SCSS, Playwriter browser verification, ignored local A4 admin gate.

---

## File Map

- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/types.ts`: extend the existing deposits overview types only for fields already returned by the deposits payload, if needed.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/utils.ts`: add helpers for currency options, selected-currency fallback, monthly schedule labels, payout status class names, and instant-balance lookup.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/page.tsx`: own `selectedCurrency`, pass it into the balance and payout cards, and reload recent payouts when the selected currency changes after overview data exists.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/account-balances-card.tsx`: add the currency selector, `Total balance` / `Available funds` labels, accessible help copy, and instant-payout notice/action.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/payouts-overview-card.tsx`: add reference payout schedule copy/help, reference notice copy/links, recent-payout detail links with status chips, and both footer actions.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`: scoped styles for the selector row, balance blocks, notices, status chips, and payout footer.
- Modify tests under `plugins/woocommerce/client/admin/client/woopayments/admin/test/`: `account-balances-card.test.tsx`, `payouts-overview-card.test.tsx`, `overview-page.test.tsx`, and `utils.test.ts`.
- Modify ignored harness if needed: `tools/woopayments-merge/a4-admin-surface-gate.py` and fixture tests for source-level Overview financial-card signals.
- Add changelog `plugins/woocommerce/changelog/fix-native-payments-a4q-overview-financial-summary-parity`.
- Update `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md` after verification.

## Task 1: RED Balance Card Tests

- [ ] Add an `AccountBalancesCard` test that renders USD and EUR available/pending balances, asserts the `Balance currency` selector defaults to the account currency, changes to EUR with `userEvent.selectOptions`, and observes `Total balance` / `Available funds` amounts update.
- [ ] Add an `AccountBalancesCard` test that asserts the reference help text for `Total balance` and `Available funds` is present in accessible disclosure/help regions.
- [ ] Add an `AccountBalancesCard` test that renders a positive instant balance and asserts `Get $9.00 via instant payout. Funds are typically in your bank account within 30 mins. Fee: 1.5%.` plus a `Request an instant payout` action.
- [ ] Run `pnpm --filter='@woocommerce/admin-library' test:js -- client/woopayments/admin/test/account-balances-card.test.tsx --runInBand` and confirm RED failures for missing selector/reference labels/instant-payout UI.

## Task 2: GREEN Balance Card Implementation

- [ ] Add utility helpers for currency options, selected-currency fallback, amount lookup, and instant-balance lookup without adding a backend projection.
- [ ] Update `AccountBalancesCard` to accept `selectedCurrency` and `onCurrencyChange`, render a native labeled `<select>` when more than one currency exists, render reference balance labels/help copy, and render the instant-payout notice/action when the selected currency has a positive instant balance.
- [ ] Keep loading/error status regions stable and preserve null rendering when no overview exists.
- [ ] Run the focused balance-card Jest command and keep it green.

## Task 3: RED Payouts Card Tests

- [ ] Add schedule-copy tests for daily, weekly, monthly, and month-end schedules using reference wording: `Available funds are automatically dispatched every day.`, `every Monday.`, `on the 15th of every month.`, and `on the last day of every month.`
- [ ] Add a schedule-help test for `The timing and amount of your payouts may vary due to several factors. Check out our payout schedule guide for details.`
- [ ] Replace reduced notice expectations with reference copy for suspended, waiting period, negative balance, below minimum, no available funds, and failed payout recovery links.
- [ ] Add a recent-payout test that requires each payout ID to link to `/woopayments/payouts/details&id=po_test`, renders a status chip, and keeps `View full payout history`.
- [ ] Add a footer-action test that requires `Change payout schedule` when payouts are not blocked and the waiting period is complete, and tracks `wcpay_overview_deposits_change_schedule_click`.
- [ ] Run `pnpm --filter='@woocommerce/admin-library' test:js -- client/woopayments/admin/test/payouts-overview-card.test.tsx --runInBand` and confirm RED failures for the missing reference copy/actions/links.

## Task 4: GREEN Payouts Card Implementation

- [ ] Update payout schedule helpers to match the reference wording and monthly ordinal handling.
- [ ] Render schedule help with a normal accessible link to the payout schedule guide.
- [ ] Update payout notices to preserve the reference informational content and links while using native Core translation domain and native route/account-link sources.
- [ ] Link recent payout rows to the native payout details provider route, render status chips with deterministic class names, and retain the accessible table structure.
- [ ] Render `Change payout schedule` as a native Settings > Payments provider-route link to the payout schedule section, track the reference event, and hide it when payouts are blocked or the waiting period is incomplete.
- [ ] Run the focused payouts-card Jest command and keep it green.

## Task 5: RED/GREEN Page and Utility Tests

- [ ] Add utility tests for currency option extraction, selected-currency fallback, monthly ordinal labels, and status class names.
- [ ] Add an Overview page test that loads recent payouts for the default selected currency after overview data loads, changes the balance currency selector, and asserts a second recent-payout call for the new selected currency.
- [ ] Run the focused combined Jest command and confirm RED before implementation where behavior is missing.
- [ ] Implement page state wiring and utilities until the combined Jest command passes:

```bash
pnpm --filter='@woocommerce/admin-library' test:js -- client/woopayments/admin/test/account-balances-card.test.tsx client/woopayments/admin/test/payouts-overview-card.test.tsx client/woopayments/admin/test/overview-page.test.tsx client/woopayments/admin/test/utils.test.ts --runInBand
```

## Task 6: Styling, Harness, Browser, and Commit Gates

- [ ] Add scoped SCSS for the new financial-card UI and run targeted style checks.
- [ ] Update ignored A4 admin-surface harness only for source-level signals that prove this card parity slice; do not weaken or mask regressions.
- [ ] Run Playwriter target/reference checks for the Overview route with matching account currency/instant-payout/payout-schedule state where locally available; record any state limitation honestly.
- [ ] Run targeted ESLint, Stylelint, `ts:check`, admin build, changelog validation, `git diff --check -- . ':!.agents'`, branch lint, and relevant harness gates.
- [ ] Dispatch review subagents for frontend parity/accessibility and source-contract regression review, validate findings against source, then commit source/changelog changes locally without pushing.

## Boundaries

A4q does not complete N12. It does not port the embedded Stripe notification banner, task list, account details shell, dispute readiness card, inbox notifications, active loan summary, Reports/Documents, or money-movement list/detail parity. Native admin readiness remains fail-closed after this slice.
