---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 17:23
last_updated: 2026-06-16 17:23
status: draft
---

# A3f Native WooPayments Saved Token Persistence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve WooPayments card saved-payment-method behavior in the Core-owned native checkout runtime, including Blocks/classic save choice propagation, existing saved-token resolution, token creation, order token attachment, and recurring-payment fail-hard semantics.

**Architecture:** Add a Core-owned WooPayments token service beside the provider adapter that resolves WooCommerce token IDs to provider payment method IDs and creates/attaches card `WC_Payment_Token_CC` records from WooPayments payment method details. Inject that service into the native provider adapter for synchronous success paths and into `WooPaymentsCheckoutAjaxController` for SCA/SetupIntent callback paths, saving tokens before lifecycle status transitions. Enable existing WooCommerce frontend save-token plumbing for Blocks and render a native classic checkbox/saved-token list without reintroducing plugin-owned JS or WPCOM changes.

**Tech Stack:** WooCommerce Core PHP DI classes under `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/`, WooCommerce payment-token APIs (`WC_Payment_Tokens`, `WC_Payment_Token_CC`, `WC_Order::add_payment_token()`), native checkout JS in `@woocommerce/block-library` and `@woocommerce/classic-assets`, PHPUnit, Jest, PHPStan, PHPCS, ESLint, and build workflows.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsTokenService.php` for card token resolution, creation, idempotent lookup, and order attachment.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php` to inject `WooPaymentsTokenService`, resolve saved WC token IDs before native API requests, set `setup_future_usage` for recurring or requested save, and save/attach tokens before returning authorized synchronous outcomes.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutAjaxController.php` to inject `WooPaymentsTokenService`, honor `should_save_payment_method`, force save for recurring orders, attach the token before applying lifecycle, and return a WooPayments-compatible error if recurring token creation fails.
- Modify `plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php` to declare `tokenization`, render the native bridge inside the standard tokenization shell, provide a Core-owned checkbox through the parent saved-method UI, and keep `add_payment_method` out of scope unless this package implements the account add-payment-method handler end to end.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridge.php` and `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/index.js` so Blocks registers `supports.showSaveOption` and `supports.showSavedCards` while still building WooPayments-specific assets through the normal block-library workflow.
- Modify `plugins/woocommerce/client/legacy/js/frontend/woopayments-checkout.js` and Blocks `index.js` so callback requests post the real save choice instead of `false`.
- Add tests in `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsTokenServiceTest.php`, and modify `NativeWooPaymentsGatewayTest`, `WooPaymentsProviderGatewayAdapterTest`, `WooPaymentsCheckoutAjaxControllerTest`, and Blocks `test/index.js`.
- Add `plugins/woocommerce/changelog/add-native-payments-a3f-saved-tokens`.

## Task 1: Add Native WooPayments Token Service

- [ ] **Step 1: Write failing token-service tests.**

Create `WooPaymentsTokenServiceTest` that covers: resolving a WC token ID for `woocommerce_payments` to its provider token string; rejecting a token owned by another user or gateway; creating a card token from payment method details with gateway id `woocommerce_payments`, user id, Stripe payment method id, display brand fallback, last4, expiry, and `_wcpay_wallet_type`; reusing an existing customer token when its provider token matches; attaching a token to an order through `WC_Order::add_payment_token()`; and returning `null` when details are missing required card fields. Expected red: class not found.

- [ ] **Step 2: Implement `WooPaymentsTokenService`.**

Add public methods `resolve_payment_method_id_from_token_id( string $token_id, int $user_id ): string`, `get_or_create_card_token_for_user( string $payment_method_id, int $user_id ): ?WC_Payment_Token_CC`, and `attach_token_to_order( WC_Order $order, WC_Payment_Token $token ): bool`. Use `WC_Payment_Tokens::get()` and `WC_Payment_Tokens::get_customer_tokens( $user_id, OrderPaymentStore::GATEWAY_ID )`; create only card tokens from `WooPaymentsPaymentMethodDetailsService::get_payment_method_details()`, mirroring WooPayments' card fields and wallet meta. Do not implement SEPA/Link/Amazon custom token classes in this package.

- [ ] **Step 3: Run focused token-service PHPUnit.**

Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsTokenServiceTest`. Expected: token-service tests pass.

## Task 2: Wire Saved Tokens Through Native Checkout Processing

- [ ] **Step 1: Add failing adapter and gateway tests.**

In `NativeWooPaymentsGatewayTest`, change the support expectation so the gateway supports `tokenization` and not `add_payment_method`. Add coverage that rendered payment fields include the native bridge and the standard `wc-woocommerce_payments-new-payment-method` checkbox when tokenization is available. In `WooPaymentsProviderGatewayAdapterTest`, add one native charge test where `payment_data['payment_token']` is a WC token ID and the API receives the token's provider payment method id, not the WC token id. Add one positive-amount success test where `save_payment_method=true` creates and attaches a token before the outcome returns. Expected red: gateway lacks tokenization support, the adapter passes the WC token ID as the provider credential, and no token is attached.

- [ ] **Step 2: Implement adapter and gateway plumbing.**

Add `PaymentGatewayFeature::TOKENIZATION` to `NativeWooPaymentsGateway::$supports`. Render standard tokenization UI by letting the parent `WC_Payment_Gateway_CC` token shell run while overriding `form()` to call the native checkout bridge. Inject `WooPaymentsTokenService` into `WooPaymentsProviderGatewayAdapter::init()`. In `get_payment_credential()`, resolve `payment_data['payment_token']` through the token service using the order user id before falling back to the submitted payment method. In `build_native_charge_request_data()`, keep `payment_method_update_data` behavior for saved tokens after resolution. Before returning a successful/authorized synchronous native charge or setup outcome, call the token service when `save_payment_method` is true and attach the token to the order; log and swallow synchronous token-save failures to match the reference plugin behavior.

- [ ] **Step 3: Run focused backend tests.**

Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsTokenServiceTest|NativeWooPaymentsGatewayTest|WooPaymentsProviderGatewayAdapterTest'`. Expected: all focused token/gateway/adapter tests pass.

## Task 3: Persist Tokens in Callback and Frontend Save Choice

- [ ] **Step 1: Add failing callback and Blocks tests.**

In `WooPaymentsCheckoutAjaxControllerTest`, add a successful PaymentIntent callback with `should_save_payment_method=true` that asserts the token is attached before lifecycle completion. Add a recurring callback test by filtering a new internal recurring detector seam to true, making token creation fail, and asserting the response has an error and the order does not complete. In Blocks `test/index.js`, update the callback tests so `should_save_payment_method` is posted as `true` when the response/payment method data says the shopper selected save, and add an assertion that the registered payment method exposes `supports.showSaveOption` and `supports.showSavedCards`. Expected red: controller ignores save choice and Blocks posts `false`.

- [ ] **Step 2: Implement callback token save and frontend propagation.**

Inject `WooPaymentsTokenService` into `WooPaymentsCheckoutAjaxController`. Add a private recurring detector using guarded WooCommerce Subscriptions functions, plus an internal filter `woocommerce_native_woopayments_is_recurring_payment` for focused tests. In `get_update_order_status_response()`, compute `should_save = recurring || request should_save_payment_method === 'true'`; if the retrieved intent is authorized and a payment method id exists, save and attach the token before `OrderPaymentLifecycleService::apply()`. Return the WooPayments-compatible token-save error for recurring failures and only log/swallow non-recurring failures. Update Blocks `getWooPaymentsPaymentMethod()` to pass `supports: { features, showSaveOption: true, showSavedCards: true }`; update `updateOrderStatusAfterConfirmation()` to accept a boolean save choice and post it. Update classic `updateOrderStatusAfterConfirmation()` to read `#wc-woocommerce_payments-new-payment-method` or `input[name="wc-woocommerce_payments-new-payment-method"]` and post `true` only when checked.

- [ ] **Step 3: Run focused callback/frontend tests.**

Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCheckoutAjaxControllerTest|WooPaymentsTokenServiceTest'` and `pnpm --filter='@woocommerce/block-library' test:js -- extensions/payment-methods/woopayments/test/index.js`. Expected: callback and Blocks tests pass.

## Task 4: Final Gates, Review, Logs, and Commit

- [ ] **Step 1: Run final deterministic gates.**

Run `php -l` for touched PHP files; PHPStan on touched production PHP files; focused PHPUnit for `WooPaymentsTokenServiceTest|NativeWooPaymentsGatewayTest|WooPaymentsProviderGatewayAdapterTest|WooPaymentsCheckoutAjaxControllerTest|WooPaymentsCheckoutBridgeTest|PaymentProcessingServiceTest`; focused Blocks Jest; direct Blocks JS lint for source/test; direct classic ESLint for `client/legacy/js/frontend/woopayments-checkout.js`; `pnpm --filter='@woocommerce/classic-assets' build:project:js`; `pnpm --filter='@woocommerce/block-library' build:project:bundle`; `pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes`; `git diff --check`; and `pnpm --filter='@woocommerce/plugin-woocommerce' lint:changes:branch`. Do not lint `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 2: Record blocked browser/harness gates.**

Attempt `test -x tools/woopayments-merge/verify.sh` and `test -f tools/woopayments-merge/HARNESS.md`. If they are still missing, record the browser/e2e/harness gate as blocked with exact file absence, not as passing. Do not use WPCOM edits or WPCOM pushes.

- [ ] **Step 3: Run subagent review gates.**

Dispatch a spec-compliance review and a code-quality review against the A3f diff, explicitly asking reviewers to check token-save sequencing before lifecycle completion, recurring fail-hard semantics, frontend save-choice propagation, existing-token resolution, no WPCOM edits, and WooPayments-specific assets using normal WooCommerce build workflows. Fix confirmed blockers and re-run focused gates.

- [ ] **Step 4: Add changelog and commit.**

Create `plugins/woocommerce/changelog/add-native-payments-a3f-saved-tokens` with type `add` and a concise description. Commit only source/tests/changelog. Do not force-add generated assets, scratchpad docs, or external staging logs.

- [ ] **Step 5: Update logs.**

Append A3f evidence to `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md` above `IMPLEMENTATION_LOG_APPEND_POINT` and append an A3f block to `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md` above `NEXT_WORK_PACKAGE_APPEND_POINT`. Record the git range from `6421d213816e6e9952776ab1ec8a4b4efc7bd18f` to the new A3f commit.

## Self-Review

- Spec coverage: Covers the A3 deferred saved-token and subscription token persistence gap for native card checkout, including classic and Blocks save-choice propagation, existing saved token use, token creation, order attachment, and recurring failure behavior. It does not implement WooPay, express checkout, non-card custom token classes, or the deprecated WooPayments Stripe Billing subscription engine.
- Placeholder scan: no placeholders or TBD steps remain.
- Type consistency: uses existing `NativeWooPaymentsGateway`, `WooPaymentsProviderGatewayAdapter`, `WooPaymentsCheckoutAjaxController`, `WooPaymentsPaymentMethodDetailsService`, WooCommerce token APIs, and A3e callback names.
