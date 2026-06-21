---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 00:15
target: H20/A3 product-page WooPayments express checkout parity
reconciles:
  - analysis-h20-product-page-express-checkout.md
  - analysis-h19-remaining-express-checkout.md
status: final
last_updated: 2026-06-18 02:10
---

# H20 Product-Page Express Checkout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add native WooPayments product-page Express Checkout Element parity for provider-owned platform payment methods without mutating the shopper's real cart.

**Architecture:** Keep product-page ECE in the native WooPayments provider boundary. A new tokenized cart session service/handler owns the WooPayments-specific Store API headers and isolated cart lifecycle; `WooPaymentsExpressCheckoutService` owns product eligibility/config; `WooPaymentsExpressCheckoutController` owns product-page hook/rendering; the existing split classic ECE bundle owns product add-item, variation/quantity collection, and cancel/error cleanup. Bundle and conditional-loading evidence are hard gates; local timing/query numbers are advisory smoke unless repeatable.

**Tech Stack:** WooCommerce Core PHP services/tests, WooCommerce Store API, Core `JsonWebToken`, classic WooCommerce frontend JS/Jest, existing `@woocommerce/classic-assets` build, local target/reference browser testing, restored WooPayments merge harness.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsTokenizedCartSessionController.php`: register tokenized cart session hooks only when native owns runtime, validate WooPayments session nonce/header, switch Store API session handler, emit session headers, mark order-received URLs, clear ephemeral isolated carts, and protect the shopper's real cart on order-received.
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsTokenizedCartSessionHandler.php`: provider-owned Store API session handler for WooPayments product-page ECE, loading/saving data by tokenized session id without writing browser cookies and without merging persistent cart data.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutService.php`: add product-page support checks and product config modeled on the reference, including simple/variable product data, zero-price/tax/shipping guardrails, unsupported product type filters, and an extension filter for product data.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutController.php`: register/render/enqueue product-page ECE on `woocommerce_after_add_to_cart_form` while preserving cart/checkout/order-pay behavior and skipping unsupported block cart/checkout surfaces.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProvider.php` or the existing provider bootstrap: initialize/register the new tokenized cart session controller under native ownership.
- Modify `plugins/woocommerce/client/legacy/js/frontend/woopayments-express-checkout.js`: add product-page isolated cart API behavior, product add-item, selected variation/quantity collection, add-to-cart blocking, cart-session header carry-forward, ephemeral cleanup, product refresh on variation/quantity changes, and product context click/confirm/cancel handling.
- Modify focused PHP and JS tests alongside the touched code.

## Task 1: Native Tokenized Cart Session Runtime

**Files:**
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsTokenizedCartSessionController.php`
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsTokenizedCartSessionHandler.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProvider.php` or the native WooPayments service bootstrap file that currently registers provider controllers.
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsTokenizedCartSessionControllerTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsTokenizedCartSessionHandlerTest.php`

- [x] **Step 1: Add RED tests for session activation and invalid-header fail-closed behavior**

Assert that the controller adds `woocommerce_session_handler` with priority after Store API authentication only when native owns runtime. A Store API request with a valid `X-WooPayments-Tokenized-Cart-Session-Nonce` and no session token should switch to `WooPaymentsTokenizedCartSessionHandler`; invalid nonce, non-Store API request, or invalid `X-WooPayments-Tokenized-Cart-Session` should keep the default handler and emit no WooPayments session header.

- [x] **Step 2: Add RED tests for emitted session headers, ephemeral cleanup, and order-received cart protection**

Assert that valid tokenized Store API responses get `X-WooPayments-Tokenized-Cart-Session`, that `X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart: 1` deletes only the isolated session and empties the isolated cart without clearing cookies, and that an order-received URL containing the WooPayments custom-session marker disables persistent cart/session initialization and restores a cloned real cart after WooCommerce empties it.

- [x] **Step 3: Implement the controller/handler with Core-owned JWT utilities**

Use `Automattic\WooCommerce\StoreApi\Utilities\JsonWebToken` and `@` plus `wp_salt()` for reference-compatible token validation. Keep the header names reference-compatible: `X-WooPayments-Tokenized-Cart-Session-Nonce`, `X-WooPayments-Tokenized-Cart-Session`, and `X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart`. The handler should load an existing tokenized session when the token is valid, generate a `t_` guest session id when empty, save dirty data at shutdown unless the request is ephemeral, and override `get( 'cart' )` to return an empty array when absent.

- [x] **Step 4: Run focused PHP session tests**

Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsTokenizedCartSessionControllerTest|WooPaymentsTokenizedCartSessionHandlerTest'`. Expected: all focused tests pass.

## Task 2: Product-Page Server Eligibility and Config

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutService.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutController.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutServiceTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutControllerTest.php`

- [x] **Step 1: Add RED service tests for product eligibility**

Assert product-page ECE shows only when provider readiness, `express_checkout_product_methods`, non-zero product price, supported product type, and tax/shipping compatibility pass. Cover simple products, variable products with default or query-selected attributes, unsupported products, zero-price products, non-shipping products with billing-address tax and prices excluding tax, and filters `woocommerce_native_woopayments_express_checkout_product_supported` plus `woocommerce_native_woopayments_express_checkout_product_data`.

- [x] **Step 2: Add RED service tests for product config shape**

Assert `get_express_checkout_params( 'product' )` contains reference-shaped `product` data: `displayItems`, `total`, `needs_shipping`, `currency`, `country_code`, and `product_type`, with prepared minor-unit amounts and pending shipping when shipping is needed. Assert variable product defaults/query attributes resolve to a matching variation where possible.

- [x] **Step 3: Add RED controller tests for product-page hook/render/enqueue behavior**

Assert `woocommerce_after_add_to_cart_form` is registered under native ownership, product pages enqueue the existing `wc-woopayments-express-checkout` script/style and localize `button_context: product`, product pages render the wrapper and ECE element once, unsupported products render nothing, and checkout/cart/order-pay behavior remains unchanged.

- [x] **Step 4: Implement service/controller product support**

Add product detection for `is_product()` and `[product_page]` shortcode pages, product retrieval, product support checks, product data generation, tax helpers, and hook/context routing. Keep product support fail-closed for complex product extensions unless the reference logic can be ported with source-backed confidence; preserve extension filters so follow-up slices can add richer compatibility without editing core control flow.

- [x] **Step 5: Run focused PHP product tests**

Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsExpressCheckoutServiceTest|WooPaymentsExpressCheckoutControllerTest|WooPaymentsTokenizedCartSessionControllerTest|WooPaymentsTokenizedCartSessionHandlerTest'`. Expected: all focused tests pass.

## Task 3: Classic Product-Page ECE Frontend Flow

**Files:**
- Modify: `plugins/woocommerce/client/legacy/js/frontend/woopayments-express-checkout.js`
- Test: `plugins/woocommerce/client/legacy/js/frontend/test/woopayments-express-checkout.js`

- [x] **Step 1: Add RED Jest coverage for isolated product cart lifecycle**

Assert product context starts by using a separate tokenized cart, sends `X-WooPayments-Tokenized-Cart-Session-Nonce`, carries forward `X-WooPayments-Tokenized-Cart-Session` response headers, sends `/wc/store/v1/cart/add-item` with product id, selected quantity, and variation attributes, waits for add-item before resolving shipping address changes, and sends `X-WooPayments-Tokenized-Cart: true` on checkout.

- [x] **Step 2: Add RED Jest coverage for product UI guardrails**

Assert product context blocks payment when a variable product selection is incomplete/unavailable, refreshes button data on `woocommerce_variation_has_changed` and quantity input changes, hides/unmounts the button on add-item errors, and empties only the isolated cart on cancel/error with `X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart: 1`.

- [x] **Step 3: Implement the product frontend path inside the existing split classic ECE bundle**

Add small helpers for product id, quantity, variation attributes, product add-to-cart blocking, tokenized cart headers, and cart cleanup. Keep checkout/cart/order-pay behavior stable. Do not add WooPay coupling. Preserve the existing `wcpay-express-payment-method-types` and `wcpay-express-checkout-context` payment data. Use the current classic bundle and CSS only.

- [x] **Step 4: Run focused classic JS gate**

Run `pnpm --filter='@woocommerce/classic-assets' test:js -- frontend/test/woopayments-express-checkout.js`. Expected: focused suite passes.

## Task 4: Integrated Verification, Review, and Commit

**Files:**
- Source/tests touched by Tasks 1-3.
- Scratchpad: `analysis-h20-product-page-express-checkout.md`, `staging-log.md`, `implementation-log.md`
- Changelog: WooCommerce Core changelog entry if source changes commit.

- [x] **Step 1: Run static/build gates**

Run PHP syntax for touched PHP, production PHPStan for touched production PHP, `pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes`, targeted classic ESLint for the changed ECE file, `pnpm --filter='@woocommerce/classic-assets' build`, `pnpm --filter='@woocommerce/block-library' build` if Blocks ECE touched, and `git diff --check`. Do not lint `.agents/scratchpad`.

- [x] **Step 2: Run browser/reference verification**

Use Chrome DevTools MCP first; if it is unavailable or times out, use Playwright. On reference and target, verify a simple product product-page ECE button renders with split assets and no PHP/WP notices. On target, click the product-page ECE enough to prove `/cart/add-item` uses tokenized session headers and the shopper's visible real cart is not mutated after cancel/error. Verify a variable product updates data after selecting attributes. Record screenshots/network evidence under the session `data/` folder.

- [x] **Step 3: Run harness gates that apply**

Run `tools/woopayments-merge/verify.sh` for checkout regression and the financial reconciler for any product-page order that can be deterministically placed. If Apple/Google/Amazon wallet runtime cannot be completed locally, record the limitation and treat source/Jest/browser-network evidence as the deterministic H20 pass/fail signal, not as money-flow completion.

- [x] **Step 4: Capture honest performance/bundle evidence**

Capture bundle-size deltas for the classic ECE asset and any touched split asset. Treat conditional-loading and bundle delta as hard evidence. If local browser timing/query probes are run, label them advisory smoke for large deltas unless repeated runs are stable; do not use noisy runtime numbers as a precise pass claim.

- [x] **Step 5: Review with subagents**

Dispatch at least architecture, reference-integrity/API-contract, reliability, performance, and JS-test reviewers over the final diff. Ask the performance reviewer to focus on session table writes, cart cleanup, bundle boundaries, and conditional loading, with runtime timing treated as advisory unless repeatable.

- [x] **Step 6: Commit logical changes**

Commit source/tests separately from changelog using Conventional Commits on `exp/core-native-payments`. Do not push. Update `staging-log.md` and `implementation-log.md` with final evidence, limitations, reviewer verdicts, and git range.

## Self-Review

- Spec coverage: This closes the H19 product-page ECE fail-closed gap at the native WooPayments provider level while preserving cart/checkout/order-pay ECE surfaces.
- Placeholder scan: The plan names concrete files, behaviors, commands, and fail-closed dispositions; no task relies on "TBD" or vague later work.
- Type consistency: The plan consistently uses product context `product`, gateway id `woocommerce_payments`, Store API cart routes, and reference-compatible WooPayments tokenized cart headers.
