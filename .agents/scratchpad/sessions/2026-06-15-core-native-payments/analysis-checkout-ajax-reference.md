---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-16 16:44
target: /Users/vladolaru/Work/a8c/woocommerce-payments checkout AJAX callbacks
last_updated: 2026-06-16 17:24
status: final
---

# Checkout AJAX Callback Behavior

## Scope

Local WooPayments reference checkout: `/Users/vladolaru/Work/a8c/woocommerce-payments`.

Callbacks under investigation:

- `create_setup_intent`
- `update_order_status`

## Findings Log

Initial scratchpad created before source inspection, per repository analysis rules.

## Callback Registration

- `update_order_status` is registered for both logged-in and guest AJAX actions: `wp_ajax_update_order_status` and `wp_ajax_nopriv_update_order_status`. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:565-566`.
- `create_setup_intent` is registered only as `wp_ajax_create_setup_intent`, with no `nopriv` registration. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:568`.

## create_setup_intent

### Request and nonce

- Client request shape from checkout API: `action=create_setup_intent`, `wcpay-payment-method=<payment method id>`, `_ajax_nonce=<createSetupIntentNonce>`. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/checkout/api/index.js:307-312`.
- The nonce is generated into JS config as `createSetupIntentNonce = wp_create_nonce( 'wcpay_create_setup_intent_nonce' )`. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-checkout.php:183-190`.
- Server verifies `wcpay_create_setup_intent_nonce` with `check_ajax_referer( ..., false, false )`. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4284-4291`.
- Server request parsing for payment credentials accepts `wcpay-confirmation-token`, `wcpay-payment-method`, then `wcpay-payment-method-sepa`, in that order, but `create_and_confirm_setup_intent()` passes the result to `set_payment_method()`, whose request class validates `pm`, `src`, or legacy `card` IDs. References: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-payment-information.php:309-318`, `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4254-4276`, `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/core/server/request/class-create-and-confirm-setup-intention.php:89-93`.
- Optional `save_payment_method_in_platform_account` is read from `$_POST`, parsed as a boolean, and passed as hook arg to the setup-intent request. The normal checkout `setupIntent()` client path does not send it. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4254-4276`.

### Server behavior

- Invalid nonce throws an add-payment-method exception with code `invalid_referrer`. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4284-4291`.
- Rate limiting uses key `add_payment_method_{current_user_id}` and throws `retried_too_soon` if the user retries too soon. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4293-4298`.
- The helper obtains the current WP user, reuses their WCPay customer if present, or creates a WCPay customer for that user before sending the setup intent request. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4263-4269`.
- It sends a `Create_And_Confirm_Setup_Intention` request with `customer`, `payment_method`, default `confirm=true`, hook `wcpay_create_and_confirm_setup_intention_request`, and hook args `( Payment_Information, should_save_in_platform_account, false )`. References: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4271-4276`, `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/core/server/request/class-create-and-confirm-setup-intention.php:22-35`.
- Unit tests pin customer reuse versus creation and payment method propagation. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/tests/unit/test-class-wc-payment-gateway-wcpay.php:2592-2651`.

### Response shape

- Success uses the WordPress JSON success envelope with HTTP 200: `{ success: true, data: { id, status, client_secret } }`. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4300-4307`.
- Error uses the WordPress JSON error envelope with a filtered status code: `{ success: false, data: { error: { message } } }`. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4308-4317`.
- Client returns the data directly when status is `succeeded`; otherwise it calls `stripe.confirmCardSetup( client_secret )` and returns the confirmed `setupIntent`, or throws the Stripe error. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/checkout/api/index.js:318-334`.

## update_order_status

### Request and nonce

- The nonce name is `wcpay_update_order_status_nonce`. It is embedded in the checkout redirect hash as `#wcpay-confirm-{pi|si}:{orderId}:{clientSecret}:{nonce}[:{confirmationToken}]`, with a fresh nonce included so guest account creation does not invalidate the later AJAX call. References: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:1983-2005`, `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:2278-2285`.
- Client parses that hash, confirms the PaymentIntent or SetupIntent with Stripe, then posts to AJAX with `action=update_order_status`, `order_id`, `_ajax_nonce`, `intent_id`, `should_save_payment_method` as string `'true'` or `'false'`, and `is_changing_payment` as string `'true'` or `'false'`. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/checkout/api/index.js:139-154`, `/Users/vladolaru/Work/a8c/woocommerce-payments/client/checkout/api/index.js:242-272`.
- The handler verifies `wcpay_update_order_status_nonce` with `check_ajax_referer( ..., false, false )`, reads `order_id` via `absint`, reads `intent_id` via `sanitize_text_field( wp_unslash() )`, and treats missing `intent_id` as translated `unknown` for note text. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:3888-3914`.
- `is_changing_payment` is parsed through `FILTER_VALIDATE_BOOLEAN`; if absent the handler falls back to the legacy GET-based subscription-change detector. References: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:3934-3940`, `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:5141-5152`.

### Validation and error shape

- Invalid nonce throws a `Process_Payment_Exception` with code `invalid_referrer`; missing order throws `order_not_found`. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:3892-3907`.
- The order's stored `_intent_id` must exist and exactly match the request `intent_id`; empty or mismatched IDs throw `Intent_Authentication_Exception`. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:3909-3932`.
- For `intent_id_mismatch` and `empty_intent_id`, the handler adds an order note that the attempted payment intent ID did not match any payment for the order and was ignored. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4093-4110`.
- Error responses are manually echoed as bare JSON, not `wp_send_json_error`: `{ "error": { "message": "<exception message>" } }`, then `wp_die()`. There is no `success:false` wrapper and no explicit status code in this path. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4113-4131`.

### Intent refresh and meta effects

- Positive-amount, non-subscription-change requests fetch a PaymentIntent via `Get_Intention`, then capture status, charge, charge ID, and payment method details from the charge. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:3941-3957`.
- That positive-amount branch attaches multi-currency Stripe exchange-rate meta when applicable, attaches intent info to the order, and attaches transaction fee meta. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:3959-3961`.
- `attach_intent_info_to_order()` stores transaction ID and legacy WCPay order meta: `_intent_id`, `_payment_method_id`, `_charge_id`, `_intention_status`, customer ID, `_wcpay_intent_currency`, `_wcpay_payment_transaction_id`, risk level, and `_wcpay_payment_method_details` when charge details exist. References: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-order-service.php:1175-1201`, `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-order-service.php:1230-1241`, `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-order-service.php:2705-2721`.
- The exchange-rate helper writes `_wcpay_multi_currency_stripe_exchange_rate` only when the store currency equals the account currency and the order currency differs from the account currency. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:2551-2580`.
- Zero-amount orders and explicit subscription payment-method changes fetch a SetupIntent via `Get_Setup_Intention`. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:3962-3968`.
- For a succeeded zero-amount SetupIntent, when the order is not already paid and this is not a subscription payment-method change, the handler fetches payment method details, sets the branded payment method title and card meta before `payment_complete( $intent_id )`, adds the usual success note, writes `_intention_status`, and saves. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:3969-4001`.
- After either intent path, any non-empty intent payment method ID is written to `_payment_method_id` before token creation, so renewals can still find it if token creation later fails. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4004-4011`.
- When intent status is `succeeded`, the duplicate-payment-prevention processing session for the order is cleared. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4013-4015`.

### Token save, branding, and subscription effects

- `should_save_payment_method` is true when the order is recurring, or when POST `should_save_payment_method` is exactly `'true'`. Recurring includes subscription-change, subscription-containing orders, and renewal orders when WooCommerce Subscriptions support is present. References: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4017-4023`, `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/compat/subscriptions/trait-wc-payments-subscriptions-utilities.php:53-66`.
- If the intent is authorized, saving is required, and a payment method ID exists, the token is created and attached to the order before the status transition. Non-recurring token-save failures are logged and ignored; recurring token-save failures throw a customer-facing error. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4024-4047`.
- `add_token_to_order()` may add the same token more than once because WooCommerce orders cannot remove prior tokens; this is intentional so the last token remains active for subscriptions. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:2589-2602`.
- If a token was attached, the handler sets the payment method gateway/title and stores card meta before status update, because the status transition can synchronously send customer/renewal emails. References: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4050-4060`, `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:2476-2527`.
- Card meta storage writes `_wcpay_payment_method_details`, `last4`, and `_card_brand` for regular card details, but does not write card meta for Link-as-card wallet details. References: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4707-4711`, `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4723-4762`.
- Unit tests pin: no stock reduction while changing a subscription payment method; branded titles and card meta are set only on the save/token path; the branded title exists before status transition; zero-amount SetupIntent paths source card details from the confirmed payment method; Link zero-amount paths do not store underlying card meta. References: `/Users/vladolaru/Work/a8c/woocommerce-payments/tests/unit/test-class-wc-payment-gateway-wcpay.php:5231-5287`, `/Users/vladolaru/Work/a8c/woocommerce-payments/tests/unit/test-class-wc-payment-gateway-wcpay.php:5289-5490`, `/Users/vladolaru/Work/a8c/woocommerce-payments/tests/unit/test-class-wc-payment-gateway-wcpay.php:5492-5710`.

### Status transition and success response

- Subscription payment-method-change status updates run inside `with_stock_reduction_disabled()`, which forces `woocommerce_payment_complete_reduce_order_stock` to false at `PHP_INT_MAX - 1`; other flows call `update_order_status_from_intent()` directly. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4062-4070`, `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:5155-5185`.
- `update_order_status_from_intent()` ignores already-paid orders and locked orders, locks processing for 5 minutes with transient `wcpay_processing_intent_{order_id}`, then routes statuses: `canceled` to cancelled, `succeeded` to `payment_complete`, `processing`/`requires_capture` to on-hold or fraud review, `requires_action`/`requires_payment_method` to failed when an error exists, on-hold for offline methods, or pending-with-note otherwise. References: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-order-service.php:351-394`, `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-order-service.php:1389-1551`, `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-order-service.php:2386-2455`, `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-order-service.php:2525-2538`.
- On authorized success, normal checkout empties the cart, computes `get_return_url( $order )`, and manually echoes bare JSON `{ "return_url": "<url>" }`, then dies. Subscription payment-method changes do not empty the cart, call `maybe_update_subscription_payment_method()`, and return the order view URL when available. References: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4072-4091`, `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/compat/subscriptions/trait-wc-payment-gateway-wcpay-subscriptions.php:1397-1410`.
- There is no explicit success envelope for `update_order_status`, and there is no explicit response in the non-authorized, non-exception path. The client expects either `{ return_url }` or `{ error: { message } }`. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/checkout/api/index.js:281-296`.

## Preserve or Defer Boundaries for Core A3

- Preserve the two different response contracts: `create_setup_intent` uses `wp_send_json_success/error`; `update_order_status` uses bare echoed JSON. Normalizing these would be a compatibility change.
- Preserve the fresh `wcpay_update_order_status_nonce` embedded in `#wcpay-confirm-*` redirects. It exists specifically for guest checkout account creation before the follow-up AJAX call.
- Preserve the `update_order_status` intent ID equality check against order `_intent_id`, including the customer-visible error shape and the mismatch/empty-intent order note.
- Preserve the pre-status-transition token attach and branded-title/card-meta ordering. Tests explicitly guard email timing and subscription sync behavior.
- Preserve the no-save boundary: positive-amount asynchronous confirmations do not brand the title or store card meta unless the token-save path ran.
- Preserve the zero-amount SetupIntent special case that calls `payment_complete()` directly after sourcing card details from the payment method, but keep the Link card-meta guard.
- Preserve subscription payment-method-change handling: explicit request flag, SetupIntent path, stock-reduction suppression, no cart emptying, and return to the view-order URL.
- Defer cleanup of legacy order meta attachment via `attach_intent_info_to_order__legacy()`, duplicate token rows on orders, GET-based fallback detection for subscription changes, and the order-lock TODOs. Those are broader behavior changes than the bounded checkout AJAX preservation work.
