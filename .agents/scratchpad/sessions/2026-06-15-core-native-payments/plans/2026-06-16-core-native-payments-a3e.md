---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 16:44
status: draft
---

# A3e Native WooPayments Checkout Callbacks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move WooPayments card checkout confirmation callbacks and zero-total card setup intent creation into Core-owned native WooPayments runtime code while preserving the existing plugin hash/action protocol.

**Architecture:** Add native WooPayments API client methods for SetupIntent creation/retrieval and PaymentIntent retrieval, teach the provider adapter to use SetupIntents for zero-total card checkouts when the provider declares saved-token/mandate capability and a credential exists, and add a native AJAX controller for `update_order_status` and `create_setup_intent` guarded by `NativePaymentsRuntimeArbiter::should_native_register()`. Update classic and Blocks checkout JS to parse the full legacy hash format `#wcpay-confirm-{pi|si}:{orderId}:{clientSecret}:{nonce}[:{confirmationToken}]` and post to the preserved `update_order_status` action with order ID, nonce, and intent ID. Express checkout, WooPay, token persistence for subscriptions, and widening full `can_process_payments()` readiness remain later packages because they need the native token service and account-key source.

**Tech Stack:** WooCommerce Core PHP DI classes under `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/`, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, Blocks Jest under `plugins/woocommerce/client/blocks`, and classic checkout JS under `plugins/woocommerce/client/legacy/js/frontend/`.

---

## File Structure

- Modify `plugins/woocommerce/src/Internal/Payments/CapabilityManifest.php` to add a `zero_amount_setup` provider capability.
- Modify `plugins/woocommerce/src/Internal/Payments/PaymentProcessingService.php` so zero-total checkout calls the provider only when the provider supports `zero_amount_setup` and the checkout context has a submitted payment credential; otherwise the existing no-external-payment behavior remains unchanged.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProvider.php` so WooPayments declares `zero_amount_setup`.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php` to add `create_and_confirm_setup_intention()`, `create_setup_intention()`, `get_payment_intention()`, and `get_setup_intention()`.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php` to create native SetupIntents for zero-total checkout and normalize SetupIntent responses to `PaymentOutcome`.
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutAjaxController.php` for native `update_order_status` and logged-in `create_setup_intent` AJAX callbacks.
- Modify `plugins/woocommerce/includes/class-woocommerce.php` to register `WooPaymentsCheckoutAjaxController`.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridge.php` so config reports native callback ownership and keeps the same nonce names.
- Modify `plugins/woocommerce/client/legacy/js/frontend/woopayments-checkout.js` to parse full `pi`/`si` hashes and call the preserved `update_order_status` action.
- Modify `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/index.js` and `test/index.js` to parse full hashes and post the full callback payload.
- Add/modify tests in `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentProcessingServiceTest.php`, `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`, `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`, `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridgeTest.php`, and a new `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutAjaxControllerTest.php`.
- Add `plugins/woocommerce/changelog/add-native-payments-a3e-checkout-callbacks`.

## Task 1: Prove zero-total setup intent routing

- [ ] **Step 1: Add failing tests for the generic zero-total seam and WooPayments adapter.**

Add `PaymentProcessingServiceTest::test_process_checkout_calls_provider_for_zero_total_setup_capable_provider_with_credential()` using a provider whose manifest supports `CapabilityManifest::CAPABILITY_ZERO_AMOUNT_SETUP` and a zero-total order with `PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_zero' )`. Expected red: provider is not called because zero-total checkout short-circuits. Add `WooPaymentsProviderGatewayAdapterTest::test_charge_prefers_native_setup_intent_for_zero_total_checkout()` with a fake API client returning a SetupIntent response `{ id: 'seti_native', status: 'succeeded', client_secret: 'seti_native_secret_abc', customer: 'cus_native', payment_method: 'pm_native' }`. Expected red: the adapter falls back to the legacy gateway for zero totals.

- [ ] **Step 2: Implement the capability and adapter setup path.**

Add `CapabilityManifest::CAPABILITY_ZERO_AMOUNT_SETUP = 'zero_amount_setup'` to known capabilities. In `PaymentProcessingService::process_checkout()`, call the provider when the amount is zero only if `get_capability_manifest()->supports( CapabilityManifest::CAPABILITY_ZERO_AMOUNT_SETUP )` and `PaymentContext::get_payment_method_id()` is not empty; leave the existing `STATUS_NO_EXTERNAL_PAYMENT` path for other providers. In `WooPaymentsProvider`, declare the capability. In `WooPaymentsApiClient`, implement `create_and_confirm_setup_intention()` and `create_setup_intention()` against the `setup_intents` endpoint with the same credential exclusivity as PaymentIntent creation. In `WooPaymentsProviderGatewayAdapter::charge()`, route zero-total card credentials to a new SetupIntent method that persists intent/customer/payment-method meta and returns either completed or customer-action outcomes with `#wcpay-confirm-si:{orderId}:{clientSecret}:{nonce}`.

- [ ] **Step 3: Run focused PHP red/green tests.**

Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'PaymentProcessingServiceTest|WooPaymentsProviderGatewayAdapterTest|WooPaymentsApiClientTest'`. Expected: the new tests and existing A3 charge/transport tests pass.

## Task 2: Add native checkout AJAX callbacks

- [ ] **Step 1: Add failing controller tests.**

Create `WooPaymentsCheckoutAjaxControllerTest` with tests for registration guarded by the native arbiter, update-order-status intent mismatch failure with an order note, successful positive-amount PaymentIntent confirmation applying completed lifecycle and returning `return_url`, successful zero-total SetupIntent confirmation applying completed lifecycle and storing `_payment_method_id`, and logged-in setup-intent creation returning the plugin-compatible `wp_send_json_success` data shape via a testable method that returns arrays before the handler echoes JSON. Expected red: class and API methods do not exist.

- [ ] **Step 2: Implement `WooPaymentsCheckoutAjaxController`.**

The controller gets `NativePaymentsRuntimeArbiter`, `WooPaymentsApiClient`, `WooPaymentsCustomerService`, and `OrderPaymentLifecycleService` via `init()`. `register()` adds `wp_ajax_update_order_status`, `wp_ajax_nopriv_update_order_status`, and logged-in `wp_ajax_create_setup_intent` only when native owns the runtime and the API client is available. `update_order_status_from_request()` validates `wcpay_update_order_status_nonce`, loads `order_id`, compares posted `intent_id` with order `_intent_id`, retrieves a PaymentIntent for paid orders or SetupIntent for zero-total orders, applies a lifecycle event, and returns `{ return_url: $gateway->get_return_url( $order ) }`. `create_setup_intent_from_request()` validates `wcpay_create_setup_intent_nonce`, rate-limits `add_payment_method_{user_id}`, creates or retrieves the WooPayments customer, calls native setup-intent creation, and returns `{ id, status, client_secret }` inside the existing success/error shape.

- [ ] **Step 3: Wire the controller into WooCommerce boot.**

Add `$container->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutAjaxController::class )->register();` next to the other native WooPayments registrations in `plugins/woocommerce/includes/class-woocommerce.php`. Keep the arbiter guard inside the controller so plugin-wins remains the only active state while the standalone plugin is active.

- [ ] **Step 4: Run focused PHP tests.**

Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCheckoutAjaxControllerTest|WooPaymentsCheckoutBridgeTest|PaymentProcessingServiceTest|WooPaymentsProviderGatewayAdapterTest|WooPaymentsApiClientTest'`. Expected: all focused callback and processing tests pass.

## Task 3: Fix checkout browser hash parsing and callback action

- [ ] **Step 1: Add failing Blocks JS tests for full legacy hashes.**

Update `client/blocks/assets/js/extensions/payment-methods/woopayments/test/index.js` so the confirmation test uses `#wcpay-confirm-pi:123:pi_123_secret_abc:nonce_123`, asserts Stripe receives `clientSecret: 'pi_123_secret_abc'`, and asserts `fetch` posts `action=update_order_status`, `order_id=123`, `_ajax_nonce=nonce_123`, `intent_id=pi_123`. Add a SetupIntent hash case `#wcpay-confirm-si:123:seti_123_secret_abc:nonce_123:ctoken_123` that asserts `confirmSetup()` is called with `confirmation_token` and the same order-status payload is posted. Expected red: current code treats the full tail as the client secret and posts the wrong `wcpay_update_order_status` action without order ID.

- [ ] **Step 2: Update Blocks and classic checkout JS.**

Add a shared local parser in each checkout asset that returns `{ type, orderId, clientSecret, nonce, confirmationToken, intentId }` for the legacy hash. For PaymentIntent hashes use Stripe `confirmPayment()` or `handleNextAction()` with the parsed client secret. For SetupIntent hashes use `confirmSetup()` when a confirmation token is present and `handleNextAction()` otherwise. Post to `settings.ajaxUrl` or `config.ajaxUrl` with `action=update_order_status`, `order_id`, `_ajax_nonce`, `intent_id`, and `should_save_payment_method=false` for the current card-only slice. Preserve current error handling and avoid new visible controls.

- [ ] **Step 3: Run focused JS tests and asset builds.**

Run `pnpm --filter='@woocommerce/block-library' test:js -- extensions/payment-methods/woopayments/test/index.js`, direct Blocks JS lint for the source/test, direct classic ESLint for `client/legacy/js/frontend/woopayments-checkout.js`, `pnpm --filter='@woocommerce/classic-assets' build:project:js`, and `pnpm --filter='@woocommerce/block-library' build:project:bundle`. Expected: tests/lints/builds pass and generated outputs remain ignored like other WooCommerce assets.

## Task 4: Final gates, logs, and commit

- [ ] **Step 1: Run final focused and branch gates.**

Run `php -l` on touched PHP files, PHPStan on touched production PHP files, focused PHPUnit for checkout callback/processing/provider/API classes, focused Blocks Jest, direct JS lint, `pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes`, `git diff --check`, and `pnpm --filter='@woocommerce/plugin-woocommerce' lint:changes:branch`. Do not lint scratchpad session files.

- [ ] **Step 2: Add changelog and commit.**

Create `plugins/woocommerce/changelog/add-native-payments-a3e-checkout-callbacks` with type `add` and a concise native payments description. Commit source/tests/changelog only. Do not force-add generated assets, scratchpad docs, or external staging logs.

- [ ] **Step 3: Update logs.**

Append A3e evidence to `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md` above `IMPLEMENTATION_LOG_APPEND_POINT` and append an A3e block to `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md` above `NEXT_WORK_PACKAGE_APPEND_POINT`. Record the git range from the A3d commit to the new A3e commit.

## Self-Review

- Spec coverage: A3 payment processing and Axis-2 browser callback ownership move forward for card checkout, SetupIntent callback ownership is started, plugin action/hash protocol is preserved, and plugin-wins remains guarded by the arbiter. Express checkout, WooPay, subscription token saving, and native account publishable-key sourcing are documented as later packages because this branch does not yet have native token/account services to make them correct.
- Placeholder scan: no placeholders or TBD steps remain.
- Type consistency: the plan uses the existing `PaymentContext`, `PaymentOutcome`, `CapabilityManifest`, `WooPaymentsApiClient`, `WooPaymentsProviderGatewayAdapter`, and `OrderPaymentLifecycleService` names as currently implemented.
