---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 01:55
last_updated: 2026-06-19 02:53
target: exp/core-native-payments — A4k settings row and payload parity
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4k-settings-row-payload-parity.md
  - 2026-06-19-core-native-payments-a4j-settings-payment-methods-parity.md
status: draft
---

# A4k Settings Row and Payload Parity Plan

> **Prompt:** "Add a task at the bottom of your current task list that, once you are fully done with A5c, I want you to re-open A4 because there is work to be done for feature parity. Read and follow this .agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-18-2344-N12.md"

## Goal

Close the next reopened-A4 Settings page parity slice by completing the A4j payment-method and BNPL row foundation with the reference-backed fee, discount, and duplicate-notice payloads and UI. Keep the slice bounded: no express customize subpage, payout bank-account block, sandbox-live modal, or fraud advanced subpage unless later source work proves they are small enough for their own focused slices.

## Task 1: Backend Settings Payload

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php`

- [x] **Step 1: Add RED payload tests.**

Assert `/wc/v3/payments/settings` returns `account_fees` from cached account data, only for supported payment methods, with non-array/unsupported fee entries removed. Assert it returns `dismissed_duplicate_payment_method_notices` from `wcpay_duplicate_payment_method_notices_dismissed`.

- [x] **Step 2: Add RED duplicate-detection tests.**

Mock enabled WooCommerce gateways and assert native returns duplicate card clusters only when WooPayments/native card and another enabled card-like gateway are both present. Assert the detector fails closed to an empty array when gateway inspection is unavailable.

- [x] **Step 3: Implement the payload.**

Add helper methods for `get_account_fees()`, `get_dismissed_duplicate_payment_method_notices()`, and `get_duplicated_payment_method_ids()`. Keep duplicate detection backend-owned, conservative, and reference-shaped. Do not add a PM promotions payload unless a native source-backed provider is identified.

- [x] **Step 4: Run focused PHP tests.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsSettingsServiceTest
```

## Task 2: Settings Store and Row UI

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/data/selectors.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/data/hooks.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/data/actions.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/payment-methods-list.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`
- Modify as needed: `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-data.test.ts`

- [x] **Step 1: Add RED frontend tests.**

Assert the card row renders the reference-style fee pill, discount badge, and tooltip-accessible fee detail text when `account_fees.card` includes base/additional/fx/discount data. Assert duplicate notices render under affected rows, link to WooCommerce > Settings > Payments, and persist dismissal through `wcpay_duplicate_payment_method_notices_dismissed`.

- [x] **Step 2: Add selectors/hooks/actions.**

Expose account fees and dismissed duplicate notices from the existing `wc/payments/settings` store. Add a small action that updates dismissed duplicate notice state locally and calls the existing allowlisted `saveOption()` path.

- [x] **Step 3: Render fee and duplicate row extras.**

Adapt the reference account-fee formatting locally in the WooPayments settings bundle. Render discount badges from `account_fees[id].discount[0]` and duplicate notices from `duplicated_payment_method_ids[id]`, suppressing notices that were dismissed for every gateway in the duplicate cluster.

- [x] **Step 4: Apply bounded row SCSS.**

Add WooPayments-scoped classes for fee pills, discount badges, tooltip content, and duplicate notices without leaking global styles or forcing assets into non-WooPayments bundles.

## Task 3: Bounded Settings Shell Fix

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`

- [x] **Step 1: Preserve the Book express button value.**

Fix the native `payment_request_button_type` normalizer so the existing `Book` option does not collapse to `Default`. Add a focused test.

## Task 4: Verification, Browser Diff, and Reviews

- [x] **Step 1: Run focused automated gates.**

Run the focused PHP and Jest tests, targeted ESLint for touched TypeScript/TSX, targeted Stylelint for the settings SCSS, admin-library `ts:check`, admin-library bundle build, `pnpm --filter=@woocommerce/plugin-woocommerce changelog validate`, `git diff --check`, and branch `pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch`.

- [x] **Step 2: Browser parity smoke with Playwriter.**

Load reference `http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments` and target `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings`. Confirm the card and BNPL rows show fee/discount extras when data exists, duplicate notices when local gateway fixtures expose duplicates, no native console errors, no failed local assets, and no fresh target PHP notices/warnings/fatals.

- [x] **Step 3: Review gate.**

Use focused review agents for reference parity, accessibility, and backend reliability. Validate each finding against source before acting.

- [x] **Step 4: Update docs and commit.**

Record A4k scope, deferred parity items, gates, browser evidence, and constraints in `staging-log.md`, `implementation-log.md`, and the A4/N12 baseline docs. Commit source and changelog in logical commits after gates pass. Keep A5 admin readiness fail-closed.

## Closeout Notes

A4k closes the settings row payload/extras slice only. Native now exposes sanitized `account_fees`, duplicate clusters, dismissed duplicate notice state, and a deliberately empty `pm_promotions` payload until a source-backed native promotions provider exists. The settings row renders fee pills/tooltips, discount badges, accessible duplicate notices, and preserves the `book` express checkout button value. Browser verification showed the target lazy WooPayments settings chunk `chunks/5777.js?ver=53394dcee144c00d08cc` and scoped style chunk `chunks/4151.style.css?ver=8afad0b4564f0ec493f6`, with reference-matching `$0.30` fee text and Escape-dismissable fee details. Remaining A4 settings parity blockers stay open: express checkout customize subpages/live preview, payout bank-account display, fraud Basic/Advanced UI and advanced subpage, sandbox switch-to-live notice, save busy-state overlay, PM promotions provider, broader copy/content parity, dashboard parity, Reports/Documents disposition, and the widened A4 exit gate. A5 admin readiness remains fail-closed.
