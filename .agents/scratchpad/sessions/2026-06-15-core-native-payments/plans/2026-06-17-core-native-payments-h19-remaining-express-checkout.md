---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 23:04
target: H19/A3 remaining WooPayments express checkout parity
reconciles:
  - analysis-h19-remaining-express-checkout.md
  - analysis-h18-express-checkout.md
status: draft
last_updated: 2026-06-18 00:04
---

# H19 Remaining Express Checkout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add native WooPayments Amazon Pay support for existing cart/checkout express checkout surfaces and add classic pay-for-order express checkout, while keeping product-page ECE fail-closed for its own dedicated port.

**Execution update 2026-06-18 00:04:** Tasks 1-4 are complete. The first review gate found context/currency/tax/Amazon-only startup gaps; those are fixed with focused regressions. Build/lint/browser/harness gates passed after the fixes, re-review approved, and source/tests plus changelog were committed as `562717194b` and `e41e1d6509`. Product-page ECE remains fail-closed as planned.

**Architecture:** Keep all behavior in the provider-owned WooPayments native layer. `WooPaymentsExpressCheckoutService` owns method eligibility and allowed Stripe payment method types, `NativeWooPaymentsGateway` carries validated checkout provider data into `PaymentContext`, and `WooPaymentsProviderGatewayAdapter` maps that provider data to WCPay V1 request payloads. Classic and Blocks ECE assets remain separate and conditionally loaded; Amazon Pay is exposed only when server eligibility and frontend Stripe availability agree.

**Tech Stack:** WooCommerce Core PHP services/tests, WooCommerce Store API order-pay route, Stripe Express Checkout Element, classic frontend Jest, Blocks React/Jest, existing wp-env/browser/harness gates.

---

## File Structure

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutService.php`: add `amazon_pay` method constants, context normalization for `pay_for_order`, server-side Amazon eligibility, allowed Stripe payment method type mapping, and pay-for-order config helpers.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutController.php`: add `woocommerce_pay_order_before_payment` rendering, order-pay surface detection, asset enqueue support, and keep product-page unsupported.
- Modify `plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php`: parse `wcpay-express-payment-method-types` from Store API/POST provider data and pass only validated provider data forward.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php`: use provider-supplied express payment method types when building charge/setup intent requests, defaulting to `card`.
- Modify `plugins/woocommerce/client/legacy/js/frontend/woopayments-express-checkout.js`: map enabled methods to Stripe `paymentMethodTypes`, include `wcpay-express-payment-method-types` in payment data, route pay-for-order through `/wc/store/v1/order/{id}` and `/wc/store/v1/checkout/{id}`, and disable shipping address changes for pay-for-order.
- Modify `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/express-checkout/index.js`: add Amazon registration for Blocks cart/checkout, method-specific availability probing, and payment data method types.
- Modify PHP and JS tests alongside the touched code.

## Task 1: Server-Authoritative Method Types

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutService.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutServiceTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`

- [ ] **Step 1: Add failing service tests for Amazon eligibility and allowed types**

Add tests proving `amazon_pay` is ignored unless it is configured for the current context, listed in `upe_available_payment_methods` and `upe_enabled_payment_method_ids`, ECE confirmation tokens are not disabled in account data, and billing-address taxes do not block the surface. Also assert `payment_request` maps to `card` and `amazon_pay` maps to `amazon_pay`.

- [ ] **Step 2: Add failing gateway/adapter tests for submitted method types**

Add a gateway test that POST data with `wcpay-express-payment-method-types` becomes provider data only after sanitization, and an adapter test that provider data `array( 'express_payment_method_types' => array( 'card', 'amazon_pay' ) )` produces request payload `payment_method_types => array( 'card', 'amazon_pay' )`. Add negative tests for invalid JSON/non-lists/unknown types falling back to `array( 'card' )`.

- [ ] **Step 3: Implement minimal provider-owned method-type contract**

Expose a service method that returns allowed Stripe payment method types for a context from server state. Parse checkout provider data in `NativeWooPaymentsGateway`, normalize it to known allowed types, and let `WooPaymentsProviderGatewayAdapter` apply the provider data to charge/setup requests. Do not make a frontend filter sufficient to authorize Amazon.

- [ ] **Step 4: Run focused PHP gate**

Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsExpressCheckoutServiceTest|NativeWooPaymentsGatewayTest|WooPaymentsProviderGatewayAdapterTest'`. Expected: all focused tests pass.

## Task 2: Amazon Pay on Existing ECE Surfaces

**Files:**
- Modify: `plugins/woocommerce/client/legacy/js/frontend/woopayments-express-checkout.js`
- Modify: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/express-checkout/index.js`
- Test: `plugins/woocommerce/client/legacy/js/frontend/test/woopayments-express-checkout.js`
- Test: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/express-checkout/test/index.js`

- [ ] **Step 1: Add failing frontend tests for Amazon mapping**

Classic tests should assert enabled methods `array( 'payment_request', 'amazon_pay' )` produce Stripe Elements `paymentMethodTypes: [ 'card', 'amazon_pay' ]`, ECE button config has `amazonPay: 'auto'` or equivalent Stripe-supported enabled value, and Store API payment data includes `wcpay-express-payment-method-types` with both types. Blocks tests should assert Amazon registers as a separate express payment method only when `amazon_pay` is enabled, probes `availablePaymentMethods.amazonPay`, and sends the same payment data list.

- [ ] **Step 2: Implement Amazon frontend config without merging bundles**

Update the existing split ECE bundles only. Add method metadata for Amazon in Blocks, include Amazon in the hidden availability probe, and keep Apple/Google behavior unchanged. Do not load ECE code outside WooPayments ECE surfaces.

- [ ] **Step 3: Run focused JS gate**

Run `pnpm --filter='@woocommerce/classic-assets' test:js -- frontend/test/woopayments-express-checkout.js` and `pnpm --filter='@woocommerce/block-library' test:js -- assets/js/extensions/payment-methods/woopayments/express-checkout/test/index.js`. Expected: both focused suites pass.

## Task 3: Classic Pay-For-Order ECE

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutService.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutController.php`
- Modify: `plugins/woocommerce/client/legacy/js/frontend/woopayments-express-checkout.js`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutServiceTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutControllerTest.php`
- Test: `plugins/woocommerce/client/legacy/js/frontend/test/woopayments-express-checkout.js`

- [ ] **Step 1: Add failing PHP tests for order-pay guardrails**

Assert the controller registers `woocommerce_pay_order_before_payment`, enqueues/renders on classic order-pay only when the order needs payment and has an order key plus billing email, localizes `button_context: pay_for_order`, and keeps product-page unsupported. Assert missing billing email or non-paying order fails closed.

- [ ] **Step 2: Add failing classic JS tests for order-pay routing**

Assert `button_context: pay_for_order` fetches `/wc/store/v1/order/{order_id}?key=...&billing_email=...`, posts `/wc/store/v1/checkout/{order_id}` with the same auth fields, preserves order billing/shipping addresses, sends confirmation-token payment data with method types, and does not use the current cart checkout endpoint.

- [ ] **Step 3: Implement pay-for-order service/controller/frontend path**

Add order-pay context support and config fields to the service, hook/render in the controller, and add a small classic frontend order API path. Disable shipping address requirement/changes for pay-for-order, matching the reference behavior. Keep Blocks order-pay out of scope unless Core exposes a matching Blocks surface during verification.

- [ ] **Step 4: Run focused order-pay gate**

Run the same focused PHP service/controller tests and classic JS suite. Expected: pay-for-order tests pass, product-page remains unsupported.

## Task 4: Integrated Verification and Review

**Files:**
- Source and tests touched by Tasks 1-3.
- Scratchpad: `analysis-h19-remaining-express-checkout.md`, `staging-log.md`, `implementation-log.md`
- Changelog: new WooCommerce Core changelog entry if source changes are committed.

- [ ] **Step 1: Run build/lint gates**

Run focused PHP syntax/PHPStan for touched production PHP, `pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes`, focused JS lint for touched ECE files, `pnpm --filter='@woocommerce/block-library' build`, `pnpm --filter='@woocommerce/classic-assets' build`, and `git diff --check`. Do not lint `.agents/scratchpad`.

- [ ] **Step 2: Run browser/local verification**

Use Chrome DevTools MCP or Playwright against target `store8889.localhost:8889` and reference `localhost:8082` where useful. Verify cart/checkout asset boundaries, Amazon config availability if the local account/browser supports it, and classic order-pay routing. If Amazon wallet runtime cannot be made deterministic locally, record the exact limitation and keep deterministic gates as the pass/fail evidence.

- [ ] **Step 3: Run harness gates that apply**

Run the restored `tools/woopayments-merge` harness for affected checkout money paths. If no deterministic Amazon Pay flow is available, run card/Apple/Google regression flows and record Amazon as source/config covered but runtime-limited.

- [ ] **Step 4: Review with subagents**

Dispatch at least architecture, API-contract/reference-integrity, and performance reviewers over the final diff. Performance review should focus on bundle boundaries and conditional loading first, with timing treated as advisory smoke unless repeatable.

- [ ] **Step 5: Commit logical changes**

Commit source/tests separately from changelog using Conventional Commits on `exp/core-native-payments`. Do not push. Update `staging-log.md` and `implementation-log.md` with final evidence and git range.

## Self-Review

- Spec coverage: The plan covers Amazon Pay on current cart/checkout ECE, server-side payment method type validation, and classic pay-for-order ECE. Product-page ECE is explicitly dispositioned as a later fail-closed slice because the source map shows it needs isolated cart/session/product compatibility work.
- Placeholder scan: No task says "TBD", "TODO", or "add tests" without naming the exact behavior to assert.
- Type consistency: The plan consistently uses `payment_request`, `amazon_pay`, Stripe types `card` and `amazon_pay`, checkout field `wcpay-express-payment-method-types`, and context `pay_for_order`.
