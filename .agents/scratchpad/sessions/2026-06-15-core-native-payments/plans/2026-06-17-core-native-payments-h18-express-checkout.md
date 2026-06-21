---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 21:07
tool: writing-plans
target: H18/A3 native WooPayments non-WooPay express checkout
reconciles:
  - ../analysis-h18-express-checkout.md
  - ../spec-conformance-baseline.md
  - ../staging-log.md
status: draft
---

# H18 Native WooPayments Express Checkout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore native Core ownership of WooPayments-provided non-WooPay express checkout for Apple Pay and Google Pay without coupling it to card checkout or WooPay.

**Architecture:** Add a provider-level express checkout service/controller parallel to the existing card checkout bridge and WooPay session controller. The service owns Apple/Google `payment_request` eligibility, localized config, Store API/tokenized-cart metadata, and button appearance; the controller owns native hook registration, classic asset enqueue, placeholder rendering, and Tracks all-store event properties. Blocks and classic assets stay split so merchants who do not enable WooPayments express checkout do not pay for the runtime.

**Tech Stack:** WooCommerce Core PHP services/controllers, WooCommerce Blocks express payment method registry, Stripe Express Checkout Element, WooCommerce Store API checkout, Grunt classic assets, Blocks webpack payment entries, PHPUnit, Jest, browser verification, restored local harness.

---

## Scope Boundaries

- Implement in this slice: Apple Pay / Google Pay payment-request/ECE surfaces for Blocks checkout and classic/product/cart/pay-for-order placeholders, separate Core build entries, localized config, basic Store API order placement payloads, product-page add-to-cart isolation using existing native WooPay add-to-cart support where applicable, shopper Tracks load/click continuity, and CSS parity for the wrapper/ready state.
- Track but do not expose in this slice: Amazon Pay. The reference supports it through Stripe ECE with `amazon_pay`, but native Core does not yet have a verified backend processing contract or account feature gate for it. It stays a baseline blocker until source-backed processing is implemented.
- Keep unchanged: card PaymentElement remains card-only, WooPay remains WooPay-specific, WPCOM remains read-only/off limits, and no WPCOM sandbox access is allowed.

## Files

- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutService.php`: provider-level payment-request eligibility, button settings, config, Store API/tokenized-cart nonces, Stripe/account data, product/cart context helpers, and all-store Tracks predicate.
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutController.php`: hook registration, classic asset enqueue/localization, placeholder rendering, Store API address normalization hook, and `wcpay_tracks_event_properties` preservation for Apple/Google events.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`: register the new controller next to the existing native WooPayments checkout/WooPay/tracking controllers.
- Modify `plugins/woocommerce/includes/class-wc-frontend-scripts.php`: register `wc-woopayments-express-checkout` script and style through the normal classic asset registry.
- Modify `plugins/woocommerce/src/Blocks/Payments/Integrations/WooPayments.php`: inject the express service and conditionally register `wc-payment-method-woopayments-express-checkout` script/style when the current Blocks cart/checkout context can show payment request.
- Modify `plugins/woocommerce/client/blocks/bin/webpack-entries.js`: add split Blocks payment and style entries for `wc-payment-method-woopayments-express-checkout`.
- Create `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/express-checkout/index.js`: register Apple Pay and Google Pay Blocks express methods through `registerExpressPaymentMethod()`.
- Create `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/express-checkout/style.scss`: Blocks ECE wrapper/spacing/focus/overflow fixes.
- Create `plugins/woocommerce/client/legacy/js/frontend/woopayments-express-checkout.js`: classic Stripe Express Checkout Element mount, ready/click/cancel/confirm handling, basic product-page add-to-cart, Store API checkout payload, and Apple/Google Tracks.
- Create `plugins/woocommerce/client/legacy/css/woopayments-express-checkout.scss`: classic wrapper readiness, separator, iframe width, spacing, and forced-colors-safe focus handling.
- Create `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutServiceTest.php`.
- Create `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutControllerTest.php`.
- Create `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/RecordingExpressCheckoutService.php` for controller tests.
- Modify `plugins/woocommerce/tests/php/src/Blocks/Payments/Integrations/WooPaymentsTest.php`.
- Create `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/express-checkout/test/index.js`.
- Create `plugins/woocommerce/client/legacy/js/frontend/test/woopayments-express-checkout.js`.
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md` after verification.

## Tasks

### Task 1: RED Server-Side Express Checkout Contracts

- [ ] Add `WooPaymentsExpressCheckoutServiceTest` that expects payment request to be unavailable when the gateway cannot process payments, when no current context has `payment_request` in `express_checkout_{context}_methods`, and when SSL is required but unavailable in live mode.
- [ ] Add service tests that expect `payment_request` to be available for `product`, `cart`, and `checkout` contexts when native account readiness is true and the matching `express_checkout_{context}_methods` setting contains `payment_request`.
- [ ] Add service tests that expect `get_express_checkout_params( 'checkout' )` to include `ajax_url`, `wc_ajax_url`, `nonce.payment_request`, `nonce.platform_tracker`, `nonce.store_api_nonce`, `nonce.tokenized_cart_nonce`, `nonce.tokenized_cart_session_nonce`, checkout currency/country/shipping fields, `button`, `button_context`, `enabled_methods => array( 'payment_request' )`, Stripe publishable key/account/locale, and `flags.isEceUsingConfirmationTokens`.
- [ ] Add controller tests that expect native runtime ownership plus service availability to register `wp_enqueue_scripts`, checkout/cart/product/pay-order render hooks, Store API normalization hooks, and `wcpay_tracks_event_properties`.
- [ ] Add Blocks integration tests that expect a separate `wc-payment-method-woopayments-express-checkout` handle only when the express service says payment request is available for the Blocks context.
- [ ] Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsExpressCheckoutServiceTest|WooPaymentsExpressCheckoutControllerTest|Automattic\\\\WooCommerce\\\\Tests\\\\Blocks\\\\Payments\\\\Integrations\\\\WooPaymentsTest'` and confirm the failure is missing classes/handles/contracts.

### Task 2: GREEN PHP Service, Controller, And Registration

- [ ] Implement `WooPaymentsExpressCheckoutService` with injected `WooPaymentsAccountService`, `WooPaymentsProvider`, and `WooPaymentsFrontendTrackingController`. Keep it independent from `WooPaymentsWooPaySessionService` except where both read the same preserved gateway settings.
- [ ] Normalize contexts to `product`, `cart`, and `checkout`; treat pay-for-order as checkout for settings.
- [ ] Read `express_checkout_{context}_methods` arrays and return `payment_request` only when configured, account-ready, and current surface is supported.
- [ ] Build button settings from `payment_request_button_type`, `payment_request_button_theme`, `payment_request_button_size`, and `payment_request_button_border_radius`, using the reference size-to-height mapping of `small => 40`, `medium => 48`, `large => 55`.
- [ ] Build `wcpayExpressCheckoutParams` with reference-compatible keys while omitting Amazon until its backend contract exists.
- [ ] Implement `WooPaymentsExpressCheckoutController::register()` with the native arbiter guard and no WooPay eligibility guard.
- [ ] Render `wcpay-express-checkout-wrapper`, `#wcpay-express-checkout-element`, order-attribution inputs through WooCommerce's existing helper if available, and `#wcpay-express-checkout-button-separator`.
- [ ] Enqueue/localize `wc-woopayments-express-checkout` and `wcpayExpressCheckoutParams` only on eligible shopper surfaces.
- [ ] Add the controller to `class-woocommerce.php` next to the existing native WooPayments controllers.
- [ ] Re-run the RED PHP command until it passes.

### Task 3: RED/GREEN Blocks Express Checkout Asset

- [ ] Add Jest tests for the new Blocks entry that assert it registers two express payment methods named for Apple Pay and Google Pay, both with `gatewayId: 'woocommerce_payments'`, card-backed `paymentMethodId`, and method-specific `canMakePayment()` gates based on `enabled_methods`.
- [ ] Add tests that assert no express method registers when `isCoreNativeCheckoutAvailable` is false or `enabled_methods` does not include `payment_request`.
- [ ] Add tests that assert Apple/Google load and click events are sent through `recordWooPaymentsUserEvent()` with `applepay_button_load`, `gpay_button_load`, `applepay_button_click`, and `gpay_button_click`.
- [ ] Implement the Blocks entry using `registerExpressPaymentMethod()` and a small React wrapper around Stripe's Express Checkout Element. Use semantic buttons/labels only where Core renders native controls; Stripe's iframe owns its own button semantics.
- [ ] Keep the existing card and WooPay entries unchanged except for shared helpers if a helper is genuinely reused.
- [ ] Add `style.scss` for the new Blocks entry and register it in `webpack-entries.js`.
- [ ] Run `pnpm --filter='@woocommerce/block-library' test:js --runTestsByPath assets/js/extensions/payment-methods/woopayments/express-checkout/test/index.js` until green.

### Task 4: RED/GREEN Classic Express Checkout Asset

- [ ] Add Jest tests for classic `woopayments-express-checkout.js` that assert it bails without `wcpayExpressCheckoutParams`, mounts Stripe `expressCheckout` into `#wcpay-express-checkout-element`, hides/shows the wrapper and separator on `ready`, and records Apple/Google load/click events.
- [ ] Add tests that assert product-page context posts selected product form data to `/?wc-ajax=wcpay_add_to_cart` before confirmation and does not depend on WooPay being enabled.
- [ ] Add tests that assert the Store API checkout payload contains `payment_method=woocommerce_payments`, `payment_data` entries for `payment_method=card`, `wcpay-confirmation-token` or `wcpay-payment-method`, `express_payment_type`, and `wcpay-express-payment-method-types`.
- [ ] Implement the smallest classic ECE runtime that can mount, become ready, track click/load, handle confirm, build Store API checkout payloads, and surface errors without changing card checkout or WooPay code.
- [ ] Add classic SCSS and register `wc-woopayments-express-checkout` script/style in `class-wc-frontend-scripts.php`.
- [ ] Run `pnpm --filter='@woocommerce/classic-assets' test:js -- --runTestsByPath js/frontend/test/woopayments-express-checkout.js` until green.

### Task 5: Build, Browser, Harness, And Logs

- [ ] Run focused PHP tests for `WooPaymentsExpressCheckoutServiceTest`, `WooPaymentsExpressCheckoutControllerTest`, `WooPaymentsWooPaySessionControllerTest`, `WooPaymentsCheckoutBridgeTest`, `WooPaymentsFrontendTrackingControllerTest`, and `WooPaymentsTest`.
- [ ] Run focused JS tests for the new Blocks and classic express assets plus existing card/WooPay JS tests to catch accidental coupling.
- [ ] Run production PHPStan for the new native WooPayments PHP files and any touched integration files.
- [ ] Run explicit PHPCS for touched PHP source/tests, direct changed-file ESLint for touched JS files, and stylelint or the classic build CSS gate for the new SCSS.
- [ ] Run `pnpm --filter='@woocommerce/classic-assets' build` and the Blocks payment-method build so generated assets are produced through the same workflows as the rest of WooCommerce.
- [ ] Run `git diff --check -- . ':(exclude).agents/scratchpad/sessions/2026-06-15-core-native-payments'`.
- [ ] Use Chrome DevTools MCP or Playwright to compare target and reference checkout/cart/product surfaces. Verify that card and WooPay styling remain intact, Apple/Google express placeholders load only when enabled, no WooPayments asset loads when disabled, and no visible overlap/unstyled controls appear.
- [ ] Run the restored local harness unchanged. Do not adjust harness behavior to hide product regressions.
- [ ] Scan target and reference logs for fresh PHP/WP notices, warnings, deprecations, fatals, uncaught exceptions, stack traces, and database errors.

### Task 6: Reviews, Documentation, And Commit

- [ ] Dispatch focused subagent reviews after implementation: architecture/provider boundary, frontend parity/styling, accessibility, performance/asset loading, and API contract/order payload.
- [ ] Fix Critical/High findings and source-verify any disputed review claim before acting.
- [ ] Update `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md` with the H18 result, explicitly noting whether Amazon remains open and whether performance numbers were deterministic, noisy, or incomplete.
- [ ] Add a WooCommerce changelog entry if tracked WooCommerce source changes are ready to commit.
- [ ] Commit one logical source/tests change and one changelog change if required by the repository convention. Never commit or push to trunk.

## Self-Review

- Spec coverage: The plan directly addresses the A3 non-WooPay express checkout blocker, N7c bundle gap for missing express JS/CSS, H16 Tracks exclusion for express Apple/Google events, and the user's frontend-styling and asset-splitting constraints.
- Placeholder scan: No TODO/TBD placeholders remain.
- Type consistency: The new PHP owner is named `WooPaymentsExpressCheckout*` throughout, the script handle is consistently `wc-woopayments-express-checkout`, and the Blocks handle is consistently `wc-payment-method-woopayments-express-checkout`.
- Scope honesty: Apple/Google are in-scope for implementation. Amazon Pay remains an explicitly tracked parity gap until a native backend contract is verified.
