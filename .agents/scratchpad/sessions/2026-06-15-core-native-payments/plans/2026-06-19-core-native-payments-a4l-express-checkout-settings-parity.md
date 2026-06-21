---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 03:02
last_updated: 2026-06-19 03:26
target: exp/core-native-payments — A4l express checkout settings parity
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4l-express-checkout-settings-parity.md
  - 2026-06-19-core-native-payments-a4k-settings-row-payload-parity.md
status: draft
---

# A4l Express Checkout Settings Parity Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development for implementation tasks where write scopes can be kept disjoint, and use $test-driven-development before production changes. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the reference WooPayments express checkout settings subpages and Customize flow in native WooCommerce Settings > Payments, without widening into unrelated settings parity items.

**Architecture:** Keep route ownership under the existing WC Settings > Payments provider-route registry. Extract a native WooPayments express checkout settings module that reuses the existing `wc/payments/settings` store and adapts the reference component tree, copy, state, and styling at the native edges. Keep WooPayments-specific detail UI and assets in WooPayments settings/admin chunks, not the generic payment providers list.

**Tech Stack:** React, TypeScript, WooCommerce Admin settings route registry, `@wordpress/data`, `@wordpress/components`, Jest/RTL, Playwriter browser parity checks.

---

## Scope

This slice covers the express checkout settings list-to-detail flow only: WooPay, Apple Pay / Google Pay (`payment_request`), Amazon Pay, shared appearance controls, location override behavior, save/busy state, core-owned provider subroutes, and scoped styling/assets. It does not close payout bank-account display, fraud Basic/Advanced parity, sandbox switch-to-live notice, dashboard parity, or the widened A4 exit gate.

## Task 1: Provider Route And URL Contract

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`
- Modify or create: `plugins/woocommerce/client/admin/client/woopayments/admin/utils.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/utils.ts`
- Modify current imports of `getSettingsPaymentsProviderRouteUrl` under `plugins/woocommerce/client/admin/client/woopayments/admin/**`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx`
- Modify or create: `plugins/woocommerce/client/admin/client/woopayments/admin/test/utils.test.ts`
- Modify: `plugins/woocommerce/client/admin/client/settings-payments/test/register-provider-routes.test.tsx`
- Modify: `plugins/woocommerce/client/admin/client/settings-payments/components/buttons/test/settings-button.test.tsx`

- [ ] **Step 1: Add RED route registration tests.**

Update `routes.test.tsx` so the route list includes a native express checkout detail route, preferably one dynamic route with path `/woopayments/settings/express-checkout/:methodId` and an order immediately after `/woopayments/settings`. Keep the assertion that no route starts with `/payments/` and no route references `wc-pay-welcome-page`.

- [ ] **Step 2: Add RED bootstrap route tests.**

Update `register-provider-routes.test.tsx` to expect the same express detail route after importing `~/woopayments/admin/routes`. This pins the route under the core Payments settings orchestration rather than a plugin-era page.

- [ ] **Step 3: Add RED native URL helper tests.**

Move the generic URL helper out of `admin/overview/utils.ts` into `admin/utils.ts`, or create a shared wrapper there and update the overview test into `admin/test/utils.test.ts`. Assert that `/woopayments/settings/express-checkout/payment_request` becomes `admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings%2Fexpress-checkout%2Fpayment_request`, with additional query args preserved after the encoded `path`.

- [ ] **Step 4: Add RED SettingsButton coverage for WooPayments paths.**

Extend `settings-button.test.tsx` with a URL shaped like `https://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings%2Fexpress-checkout%2Fwoopay&from=settings-payments`. Assert `SettingsButton` calls React Router `navigate()` with the origin-stripped URL and does not call full-page navigation.

- [ ] **Step 5: Implement route and URL registration.**

Register a lazy WooPayments express checkout settings route in `routes.tsx` with a dedicated webpack chunk name such as `settings-payments-woopayments-express-checkout-settings`. Keep the existing `/woopayments/settings` route exact. Export `getSettingsPaymentsProviderRouteUrl()` from `admin/utils.ts` and update existing overview, payouts, money movement, capital, and new express settings imports to use the shared module.

- [ ] **Step 6: Run route tests.**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test -- --runTestsByPath plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx plugins/woocommerce/client/admin/client/woopayments/admin/test/utils.test.ts plugins/woocommerce/client/admin/client/settings-payments/test/register-provider-routes.test.tsx plugins/woocommerce/client/admin/client/settings-payments/components/buttons/test/settings-button.test.tsx
```

Expected: route and URL tests fail before implementation, then pass after implementation.

## Task 2: Native Express Checkout Detail Surface

**Files:**
- Create: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/index.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/methods.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/payment-request-settings.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/amazon-pay-settings.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/woopay-settings.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/appearance-settings.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/notices.tsx`
- Create if needed: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/preview.tsx`
- Create or modify: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/style.scss`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/index.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`
- Add: `plugins/woocommerce/client/admin/client/woopayments/settings/test/express-checkout-settings.test.tsx`

- [ ] **Step 1: Add RED detail-page tests for valid and invalid method IDs.**

Create `express-checkout-settings.test.tsx` with RTL tests that render the route component for `payment_request`, `woopay`, `amazon_pay`, and an invalid method. Assert the valid pages render method headings and reference enable labels, while invalid input renders `Invalid express checkout method ID specified.` without crashing.

- [ ] **Step 2: Add RED dynamic checkout override tests.**

Port the reference behavior for Apple/Google and Amazon Pay: when `is_express_checkout_in_payment_methods_enabled` is true, product/cart location checkboxes are disabled and unchecked, checkout is disabled and checked; when false, all enabled-method locations stay interactive and call the location updater with the correct method location and boolean.

- [ ] **Step 3: Add RED appearance tests.**

Assert the shared appearance section renders `Call to action`, the `Only icon`, `Buy with`, `Donate with`, and `Book with` options, size radios with pixel copy, theme radios with descriptions, border radius number plus slider controls, and cross-method notices when another express method is enabled. Use role/label/text queries, not snapshots.

- [ ] **Step 4: Add RED WooPay-specific tests.**

Assert WooPay disables its enable checkbox and shows `To enable WooPay, you must first disable Link by Stripe.` when Link is enabled. Assert WooPay renders the Terms of Service, Privacy Policy, usage-tracking guidance, checkout policies field, logo upload/remove controls, global theme support only when native bootstrap data says it is eligible, and a preview or preview fallback region.

- [ ] **Step 5: Extract shared section and save/busy primitives.**

Refactor `SettingsSection`, `FieldGroup`, `LocationCheckboxes`, the save bar, and `WooPayLogoUpload` out of `settings-page.tsx` only as needed for reuse. Keep extracted files small and local to `woopayments/settings`; do not import the full settings page from the express subpage. Add a `FormBusyState`-equivalent wrapper that uses a visible disabled overlay or `aria-busy` on the form while `useSettings().isSaving` is true, without trapping focus or hiding controls from assistive technology.

- [ ] **Step 6: Implement method detail components.**

Adapt the reference component tree into native TypeScript using the existing native hooks. Preserve method IDs, enable semantics, location arrays, copy/content, disabled state behavior, shared appearance controls, notices, invalid-method guard, and `saveSettings()` footer. Use native `@wordpress/components` controls with labels and help text. Avoid plugin-only imports such as `wcpay/...`, `fraud-scripts`, and plugin globals; read native bootstrap through `getWooPaymentsSettingsBootstrap()` when eligibility or preview appearance data exists.

- [ ] **Step 7: Keep preview parity honest.**

If a Stripe-backed Apple/Google preview can be wired from native-owned bootstrap data without pulling checkout runtime into the settings bundle, implement it. If not, render the reference informational preview notice text and record preview implementation as a follow-up gap in the plan closeout. Do not fake a working preview with stale or hard-coded Stripe config.

- [ ] **Step 8: Run express detail tests.**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test -- --runTestsByPath plugins/woocommerce/client/admin/client/woopayments/settings/test/express-checkout-settings.test.tsx plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx
```

Expected: new tests fail before extraction/implementation, then pass.

## Task 3: Main Settings Express Section And Scoped Styling

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/payment-method-definitions.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`

- [ ] **Step 1: Add RED main-section Customize tests.**

Extend `settings-page.test.tsx` to assert the main `Express checkouts` section renders WooPay, Apple Pay and Google Pay, Link by Stripe when available, and Amazon Pay when available with reference-like descriptions and per-method `Customize` links. Assert the links target encoded Settings > Payments paths for `woopay`, `payment_request`, and `amazon_pay`.

- [ ] **Step 2: Add RED Link/WooPay conflict tests on the main list.**

Assert Link is disabled when WooPay is enabled, WooPay is disabled when Link is enabled, and the warning copy mirrors the reference where applicable. Keep the behavior consistent with the current native `useLinkEnabledSettings()` guard.

- [ ] **Step 3: Replace inline detail controls with list rows plus Customize.**

Change the main `ExpressCheckoutSettingsSection` from a flat inline detail editor into reference-style method rows that toggle each express method and send detailed controls to the new subpages. Keep lightweight enable toggles available on the main page, but move location, appearance, WooPay logo/policies, and Amazon size-only settings to the detail pages.

- [ ] **Step 4: Apply scoped SCSS.**

Bring in WooPayments-scoped styling for express rows and detail pages. Use local class names under `.woopayments-settings-...` and `.woopayments-express-checkout-settings...`. Do not add global `.components-*` overrides unless unavoidable; if unavoidable, scope them under the WooPayments settings container. Keep WooPay preview and express styling bundled with the WooPayments settings/admin chunks.

- [ ] **Step 5: Run settings-page tests.**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test -- --runTestsByPath plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx plugins/woocommerce/client/admin/client/woopayments/settings/test/express-checkout-settings.test.tsx
```

Expected: main settings tests fail before rows/links are implemented, then pass.

## Task 4: Verification, Browser Diff, Reviews, And Docs

**Files:**
- Add: `plugins/woocommerce/changelog/*`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`
- Update if findings arise: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/review-agent-findings.md`

- [ ] **Step 1: Run focused automated gates.**

Run the focused Jest tests above, targeted ESLint for touched TS/TSX, targeted Stylelint for touched SCSS, `pnpm --filter=@woocommerce/admin-library ts:check`, admin bundle build, `pnpm --filter=@woocommerce/plugin-woocommerce changelog validate`, `git diff --check`, and branch `pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch`. Do not lint scratchpad files.

- [ ] **Step 2: Browser parity with Playwriter.**

Compare reference `http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments` and target `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings`. Verify Customize links navigate to each express subpage, each subpage is reachable through the WC Settings > Payments path, dynamic checkout disabled-location behavior matches reference, save dirty/busy behavior works, no console errors or failed local assets appear, and no fresh PHP notices/warnings/fatals appear.

- [ ] **Step 3: Bundle and styling evidence.**

Record the lazy chunks loaded by the target main settings page and each express detail subpage, plus rough payload deltas from the admin build output. Treat these as big-delta indicators, not precise performance claims. Capture screenshots for the main express section and each detail page; use them as large visual-regression evidence rather than pixel-perfect guarantees.

- [ ] **Step 4: Dispatch review agents.**

Use accessibility review for labels, disabled discoverability, save busy state, focus behavior, and notices; architecture review for route ownership, component extraction, store registration, and bundle splitting; and parity/API contract review for copy, settings keys, method IDs, and URL contract. Validate every finding against source before acting.

- [ ] **Step 5: Update docs and commit.**

Record A4l scope, any consciously deferred preview or plugin-runtime adaptations, verification evidence, and remaining A4 settings blockers in the staging log, implementation log, and spec-conformance baseline. Add a WooCommerce Core changelog entry and commit source/changelog as one logical change. Keep A5 admin readiness fail-closed.

## Task 5: After A5c, Reopen A4 For N12 Admin Feature Parity

- [ ] **Step 1: Add the A4 reopen to the post-A5c task queue.**

Once A5c is fully complete, reopen A4 against `supervisor-prompt-2026-06-18-2344-N12.md` and treat native admin merchant-reachability, per-surface functional/visual parity, and copy/content parity as required A4 exit criteria before any A5 cutover readiness can be considered complete.

- [ ] **Step 2: Keep readiness fail-closed until the widened gate passes.**

Do not default `WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY` to true on route architecture alone. The default stays fail-closed until the widened A4 exit gate has browser-backed reference-vs-target evidence for the native WooPayments admin surfaces.

- [ ] **Step 3: Work A4 parity in coherent slices.**

Use N12 as the checklist, but group the work into larger slices where it preserves throughput: admin navigation and badges, settings sections and subpages, dashboard surfaces, styling/SCSS parity, and the widened A4 exit gate. Verify any N12 finding against source and the reference store before acting on it.

## Closeout Criteria

A4l is complete only when native merchants can reach WooPay, Apple Pay / Google Pay, and Amazon Pay express checkout settings from the WooPayments settings page; each detail page preserves the reference control grouping, copy, state, and save behavior for the fields in scope; dynamic checkout location override behavior is test-backed; WooPayments-specific styles/assets remain scoped to WooPayments chunks; focused tests, lint/type/build gates, Playwriter parity checks, and review gates have passed; and all deferred parity gaps are explicitly recorded.
