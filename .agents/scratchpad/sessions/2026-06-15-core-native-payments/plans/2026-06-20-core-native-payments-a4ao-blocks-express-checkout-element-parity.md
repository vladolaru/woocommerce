---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 10:38
target: A4ao Blocks Express Checkout Element parity
reconciles:
  - ../analysis-a4ao-blocks-express-checkout-element-parity.md
  - ../staging-log.md
  - ../implementation-log.md
  - ../supervisor-prompt-2026-06-18-2344-N12.md
status: draft
---

# A4ao Blocks Express Checkout Element Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore native WooPayments Blocks Express Checkout Element parity with the reference WooPayments implementation across cart-aware method availability, Stripe Elements options, wallet sheet cart/shipping data, shipping lifecycle updates, checkout confirmation handling, and scoped iframe/focus styling.

**Architecture:** Add a provider-owned native WooPayments Store API cart extension under `extensions.wcpay.express_checkout_methods`, then make the existing Blocks ECE bundle consume that runtime cart contract while keeping localized settings as an allowlist. Keep all WooPayments-specific frontend logic and styling bundled with the existing Blocks ECE payment method asset.

**Tech Stack:** WooCommerce Core PHP service registration, WooCommerce Store API extension data, React/JavaScript Blocks payment method registration, Stripe Express Checkout Element, Jest/React Testing Library, SCSS, Playwriter, WooCommerce local wp-env.

---

## File Map

- Add: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutStoreApiExtension.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
- Add/modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutStoreApiExtensionTest.php`
- Modify: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/express-checkout/index.js`
- Modify: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/express-checkout/style.scss`
- Modify: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/express-checkout/test/index.js`
- Modify: session `analysis`, `implementation-log.md`, `staging-log.md`, README, and add a WooCommerce changelog entry after product gates pass.

## Task 1: Native Store API Cart Extension

- [ ] **Step 1: Write RED PHP tests.**

Add focused tests for a new native Store API extension class:

- `register()` adds the `woocommerce_blocks_loaded` callback only when `NativePaymentsRuntimeArbiter::should_native_register()` returns true.
- `get_cart_extension_data()` returns `express_checkout_methods` from `WooPaymentsExpressCheckoutService::get_enabled_methods_for_context( 'cart', get_woocommerce_currency() )`.
- `get_cart_extension_schema()` returns an array-of-strings schema compatible with the reference `extensions.wcpay.express_checkout_methods` contract.

- [ ] **Step 2: Implement the extension.**

Create `WooPaymentsExpressCheckoutStoreApiExtension` as a provider-owned `RegisterHooksInterface` class. It should inject `NativePaymentsRuntimeArbiter` and `WooPaymentsExpressCheckoutService`, fail closed when native WooPayments should not register, and call `woocommerce_store_api_register_endpoint_data()` for `CartSchema::IDENTIFIER` inside `woocommerce_blocks_loaded` when the Store API function and schema class are available.

- [ ] **Step 3: Register the extension in WooCommerce init hooks.**

Add the new class to `plugins/woocommerce/includes/class-woocommerce.php` near the other native WooPayments runtime hook classes. Keep the legacy-file edit minimal and avoid unrelated initialization changes.

## Task 2: Blocks ECE Runtime Parity

- [ ] **Step 1: Write RED JS tests.**

Expand the focused Blocks ECE tests to cover:

- cart extension methods gating availability and method registration;
- mounted Elements options containing `loader: 'never'`, manual capture, subscription setup-future-usage, locale, appearance, amount, currency, and cart-aware method types;
- wallet click resolution with line items and shipping rates;
- `shippingaddresschange` calling `/wc/store/v1/cart/update-customer`, updating Elements amount, and resolving updated line items/rates;
- `shippingratechange` calling `/wc/store/v1/cart/select-shipping-rate`, updating Elements amount, and resolving updated line items/rates;
- checkout confirmation preserving native payment-data keys while handling unsuccessful payment status and redirect fallback.

- [ ] **Step 2: Implement cart-aware helpers.**

Add small local helpers in the ECE bundle for `getEnabledMethodsForCart()`, `getPaymentMethodTypesForMethods()`, `getCartLineItems()`, `getCartShippingRates()`, `getCartElementsOptions()`, and error normalization. Keep them colocated with the ECE module unless the existing codebase already provides a natural shared helper.

- [ ] **Step 3: Implement Stripe Elements option parity.**

Use the cart-aware method types for availability probes and mounted Elements. Preserve `loader: 'never'` and add manual capture, subscription setup-future-usage, locale, and Blocks checkout appearance through the existing UPE styles helper. Keep this card/express checkout styling independent from WooPay-specific code.

- [ ] **Step 4: Implement wallet lifecycle parity.**

Wire click, shipping address change, shipping rate change, and confirm handlers so the wallet sheet reflects current cart line items, shipping options, totals, and Store API checkout result. Fail visibly on Store API failure, currency drift, or unsuccessful payment status.

## Task 3: Scoped Styling And Browser Proof

- [ ] **Step 1: Add scoped Blocks ECE styles.**

Port the reference overflow-visible and padding rules to the native ECE SCSS for WooCommerce Blocks cart/checkout express payment containers, scoped to the WooPayments ECE surface so unrelated checkout methods are not affected.

- [ ] **Step 2: Run focused local gates.**

Run focused PHP tests, focused Blocks ECE Jest, exact-file JS lint, targeted SCSS Stylelint, PHP syntax/PHPCS/PHPStan for touched PHP, and the relevant Blocks/admin build commands. Do not lint `.agents/scratchpad`.

- [ ] **Step 3: Run review agents.**

Use subagents for at least reliability/API-contract, accessibility/frontend, and test-quality review. Source-verify findings before acting and write durable findings to the session docs before presenting them.

- [ ] **Step 4: Run Playwriter and log gates.**

Use Playwriter to compare target and reference Blocks checkout/cart ECE behavior where local wallet prerequisites allow it. Capture failed responses/page errors/console issues, take screenshots when useful, and scan target logs after proof. Record any deterministic limits honestly.

- [ ] **Step 5: Close the slice.**

Add a WooCommerce changelog entry after product verification, update analysis/plan/status logs/staging log/README, run changelog validation, `git diff --check -- . ':!.agents'`, branch lint, and local commits only after all relevant gates pass. Do not push.
