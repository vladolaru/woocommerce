---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 09:23
reconciles:
  - analysis-a4am-classic-card-checkout-parity.md
  - analysis-b3ai-woopay-frontend-assets.md
  - supervisor-prompt-2026-06-18-2344-N12.md
last_updated: 2026-06-20 10:03
status: final
---

# A4am Classic Card Checkout Parity Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development or $executing-plans when implementing this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore card-owned classic checkout visual/runtime parity for the native WooPayments card gateway without coupling card rendering to WooPay.

**Architecture:** Keep classic card, WooPay express, and Apple/Google Pay express bundles separate. The original target was to consume the shared Blocks appearance helper, but the legacy classic checkout asset is not module-based, so the final implementation keeps a card-owned copy of the appearance/font/cache behavior inside `woopayments-checkout.js` rather than coupling the legacy checkout bundle to Blocks or WooPay runtime code.

**Tech Stack:** WooCommerce legacy checkout JavaScript/SCSS, WooCommerce backend PHP, Jest fixed-jsdom, PHPUnit via wp-env, Playwriter local browser verification, normal WooCommerce classic asset build.

## File Map

- Modify: `plugins/woocommerce/client/legacy/js/frontend/test/woopayments-checkout.js` for RED/GREEN coverage of classic Elements options and card logo popover.
- Modify: `plugins/woocommerce/client/legacy/js/frontend/woopayments-checkout.js` to pass classic appearance/fonts/loader and initialize the card logo popover from config.
- Modify: `plugins/woocommerce/client/legacy/css/woopayments-checkout.scss` to add the missing classic logo popover CSS and align the copy icon implementation with the existing Blocks card styling.
- Not modified: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/upe-styles.js`; the legacy checkout bundle owns the classic appearance path inline because it is not module-based.
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridge.php` to include `storeCountry` and FR Cartes Bancaires in `cardBrandIcons`.
- Modify: `plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php` to include FR Cartes Bancaires in classic gateway icon output when the connected/store country is FR.
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridgeTest.php` and `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php` for Cartes Bancaires RED/GREEN coverage.
- Add: WooCommerce changelog entry for `@woocommerce/plugin-woocommerce`.

## Task 1: RED Coverage

- [x] Add a failing legacy checkout JS test asserting `stripe.elements()` receives `loader: 'never'`, a cached `classic_checkout` appearance from `localStorage`, and Google/font CSS rules from allowed font stylesheets.
- [x] Add a failing legacy checkout JS test asserting the classic card bundle replaces static card icons with a `.payment-methods--logos` container, renders visible logos plus `+ N`, opens a `Supported credit card brands` dialog by keyboard/click, and closes on Escape.
- [x] Add a failing PHP checkout bridge test asserting an FR account exposes `storeCountry: FR` and includes a `cartes_bancaires` card brand icon with `Cartes Bancaires` alt text.
- [x] Add a failing native gateway icon test asserting an FR account/store shows Cartes Bancaires and updates the hidden count consistently.
- [x] Run the focused RED tests and capture expected failures:

```bash
pnpm --dir plugins/woocommerce/client/legacy test:js -- woopayments-checkout.js
pnpm test:php:env -- --filter WooPaymentsCheckoutBridgeTest
pnpm test:php:env -- --filter NativeWooPaymentsGatewayTest
```

## Task 2: Classic Appearance And Fonts

- [x] Keep Blocks selectors unchanged and implement the reference-compatible `classicCheckout` selectors inline in the legacy classic card bundle.
- [x] Add `getClassicCheckoutAppearance()`-equivalent normalize/cache/event behavior with cache key `classic_checkout`.
- [x] Call the classic appearance path from `getStripeElementsOptions()`, pass `loader: 'never'`, attach appearance when valid, and attach font rules only when present.
- [x] Ensure add-payment-method setup mode still omits amount and that saved-card/setup-intent paths continue to use the same Elements instance.
- [x] Run the focused legacy checkout JS test and keep existing tests green.

## Task 3: Classic Card Logos And FR Brand Data

- [x] Add FR-aware card brand icon construction in `WooPaymentsCheckoutBridge` and `NativeWooPaymentsGateway` without hardcoding WooPay dependencies.
- [x] Localize `storeCountry` in the classic checkout config so JS can match the reference behavior and future card-owned UI can use the same signal.
- [x] Implement classic logo replacement in `woopayments-checkout.js` using `cardBrandIcons` from config: visible icons, `+ N` count, click/keyboard popover, Escape/outside-click close, resize handling, and no duplicate initialization after checkout updates.
- [x] Add the missing `.logo-popover` classic CSS and keep CSS in the classic card bundle.
- [x] Run focused JS/PHP tests until green.

## Task 4: Verification And Closeout

- [x] Run exact-file JS lint for modified legacy JS files.
- [x] Run exact PHP tests and PHP lint/PHPStan if PHP changed.
- [x] Run the normal classic asset build so generated WooCommerce assets reflect the source changes.
- [x] Use Playwriter on the target and reference classic checkout pages to verify the card row: test badge, test card copy styling, visible card logos, popover, Payment Element styling, and clean browser console.
- [x] Check target/reference active debug logs after browser proof and fix any notices or warnings caused by this slice.
- [x] Record that checkout is outside the A4 admin/source gate and covered by focused tests plus Playwriter proof.
- [x] Update `staging-log.md`, `implementation-log.md`, README status, and this plan statuses.
- [x] Commit locally only after verification passes. Do not push.
