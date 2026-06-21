---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 20:33
target: A4av payment-method availability guidance parity
reconciles:
  - analysis-a4av-payment-method-guidance-parity.md
  - review-a4au-settings-inventory.md
last_updated: 2026-06-20 21:01
status: final
---

# A4av Payment-Method Guidance Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore native WooPayments payment-method availability guidance parity for delayed approval, pending verification, rejected applications, and missing-currency warnings.

**Architecture:** Keep the behavior in the shared native WooPayments payment-method list. `settings-page.tsx` passes store state into the list, `payment-methods-list.tsx` computes row availability and accessible notices, and `payment-method-definitions.ts` carries provider currency support data sourced from the reference payment method definitions.

**Tech Stack:** React, TypeScript, WordPress components, Jest/React Testing Library, WooCommerce admin client build tooling.

---

### Task 1: Add RED Availability Guidance Tests

**Files:**

- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`

- [x] **Step 1: Add failing page-level tests near existing payment-method availability tests**

Add tests for:

- Alipay `pending` shows delayed approval copy and a `Learn more` link to `https://woocommerce.com/document/woopayments/payment-methods/local-payment-methods/#approval-delays`.
- Klarna or Affirm `pending` keeps the generic pending approval notice.
- SEPA `pending_verification` shows `Payments overview` as a native provider route link containing `path=%2Fwoopayments%2Foverview`.
- Affirm `rejected` shows an error notice and `Contact support` external link to `https://woocommerce.com/my-account/contact-support/`.
- Bancontact enabled with `store_currency: 'USD'` and `is_multi_currency_enabled: false` stays actionable and shows the `EUR` missing-currency warning.
- Bancontact not enabled under the same currency state does not show the missing-currency warning.

- [x] **Step 2: Run the focused Jest command and confirm RED**

Run:

```bash
cd plugins/woocommerce/client/admin && pnpm test:js -- settings-page.test.tsx --runInBand
```

Expected: the new tests fail on missing links/copy/currency warning, while the existing suite still executes.

### Task 2: Implement Native Availability Parity

**Files:**

- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/payment-method-definitions.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/payment-methods-list.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`

- [x] **Step 1: Add supported currency data to native payment method definitions**

Add `currencies: string[]` to `WooPaymentsPaymentMethodDefinition`. Use `[]` for card/all-currency methods, fixed arrays from reference definitions for BECS, Bancontact, EPS, giropay, GrabPay, iDEAL, Multibanco, P24, SEPA, Sofort, Affirm, Klarna, and Afterpay/Clearpay, and account-country-aware helpers for Alipay and WeChat Pay.

- [x] **Step 2: Extend availability options**

Change `getPaymentMethodAvailability()` to accept an optional options object with `enabledMethodIds`, `storeCurrency`, `isMultiCurrencyEnabled`, and `overviewUrl`. Preserve old callers by defaulting every option.

- [x] **Step 3: Restore rich reference guidance**

Implement:

- BNPL-specific inactive docs URL.
- Delayed-approval copy/link for Alipay and WeChat Pay.
- Native `Payments overview` link for pending verification.
- External `Contact support` link for rejected applications.
- Missing-currency warning when multi-currency is disabled and the enabled method does not support the store currency.

- [x] **Step 4: Include availability notices in accessible descriptions**

Give each row availability notice a stable id and include it in `aria-describedby` whenever a notice is rendered.

- [x] **Step 5: Pass store settings from settings sections**

In the standard and BNPL sections, read `settings.store_currency` and `settings.is_multi_currency_enabled`, then pass them into `WooPaymentsPaymentMethodsList`. For the Amazon Pay express overview helper call, pass no currency options unless the method definition gains currencies later.

- [x] **Step 6: Run focused Jest and confirm GREEN**

Run:

```bash
cd plugins/woocommerce/client/admin && pnpm test:js -- settings-page.test.tsx --runInBand
```

Expected: all settings-page tests pass.

### Task 3: Verify And Review A4av

**Files:**

- Review: `plugins/woocommerce/client/admin/client/woopayments/settings/payment-method-definitions.ts`
- Review: `plugins/woocommerce/client/admin/client/woopayments/settings/payment-methods-list.tsx`
- Review: `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`
- Review: `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`

- [x] **Step 1: Run focused static/frontend gates**

Run:

```bash
cd plugins/woocommerce/client/admin && pnpm lint:js -- client/woopayments/settings/payment-method-definitions.ts client/woopayments/settings/payment-methods-list.tsx client/woopayments/settings/settings-page.tsx client/woopayments/settings/test/settings-page.test.tsx
cd plugins/woocommerce/client/admin && pnpm lint:lang:types
cd plugins/woocommerce/client/admin && pnpm build:project:bundle
git diff --check -- . ':!.agents'
```

- [x] **Step 2: Run browser smoke**

Use Playwriter against `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings%23payment-methods` to confirm the native settings page still renders the payment-method list without console PHP/WP diagnostics or obvious runtime failures.

- [x] **Step 3: Run review subagents**

Run focused accessibility, JS-test-quality, and code review agents on the A4av diff. Fix any source-backed critical/high/medium findings before closeout.

- [x] **Step 4: Record closeout**

Update `analysis-a4av-payment-method-guidance-parity.md`, `implementation-log.md`, `staging-log.md`, and `README.md` with the final status, evidence paths, limitations, and constraints. Add a WooCommerce changelog entry if product code changed, then commit only after all gates pass.
