---
session: 2026-06-22-woopayments-core-merge-parity
type: analysis
by: subagent:parity-B
created: 2026-06-22 22:55
target: payment core
tool: pirategoat-tools:full-code-review (follow-up)
status: final
---

# Parity batch B — payment core (charge / refund / checkout / setupintent)

Oracle: WooPayments client `~/Work/a8c/woocommerce-payments` (v10.8.0, branch `develop`, HEAD `a82cbee4`).
Core under test: `exp/core-native-payments` in `~/Work/a8c/woocommerce-develop-2`.

Cross-cutting fact established for this batch: **the client has no order-payment lock and no deterministic idempotency key for money-moving operations.** The client's API client (`includes/wc-payment-api/class-wc-payments-api-client.php:2688`) assigns `Idempotency-Key = $this->uuid()` — a *fresh random UUID per HTTP request* — whenever a caller does not supply one, and `process_refund` / `process_payment` never call the available `set_idempotency_key()`. So every refund and charge the client sends is, from the processor's point of view, a brand-new operation. The lock + deterministic-key machinery in core (`OrderPaymentStore::claim_order_payment_lock`, `PaymentOperationIdempotency::derive_key`, `PaymentProcessingService`) is **net-new in core** with no client analogue. Findings 1 and 2 must be judged against that asymmetry.

---

## Finding 1 — 7c43c8da: concurrent same-amount/same-reason refunds (`PaymentProcessingService.php:148-176, 221`)

**Verdict: NEW-IN-CORE (residual narrow concurrency exposure); NOT a regression vs client. Confidence: high.**

### What core does
`PaymentProcessingService::process_refund()` (`src/Internal/Payments/PaymentProcessingService.php:138-177`) derives an idempotency key from `order + provider + 'refund' + amount + currency + reason + refund_instance` (line 149) and then takes an **order-wide** lock (line 150). Two distinct dimensions are in play:

1. **The order-wide lock** (`OrderPaymentStore::claim_order_payment_lock`, `OrderPaymentStore.php:149-196`, doc at 151-153: *"any active lock value blocks checkout, refund, capture, and cancel from starting"*). While one `process_refund` holds it, a genuinely-concurrent second refund returns `WP_Error('native_payment_refund_locked')` (line 151) **regardless of amount/reason**. The lock is released in `finally` (line 165), so it only spans one synchronous call.
2. **The per-instance discriminator** `resolve_refund_instance_id()` (line 221) — added precisely to stop two same-amount/same-reason refunds from sharing a key. It excludes refunds already stamped with `_wcpay_refund_id` (`PROCESSED_REFUND_LINK_META_KEY`, line 190) and returns the *fresh, unprocessed* refund's ID.

### Why core's mitigation holds for the sequential case
WC core creates and **saves** the `WC_Order_Refund` row before invoking the gateway: `wc_create_refund()` does `$refund->save()` (`includes/wc-order-functions.php:667`) *then* `wc_refund_payment()` → `$gateway->process_refund()` (`:669` → `:784`). So at refund-instance-resolution time the unprocessed row exists, and after the first refund completes it carries `_wcpay_refund_id`, so the second sequential refund resolves to a *different* unprocessed row → different key. Sequential same-amount/same-reason refunds work.

### Residual exposure (the finding)
The narrow window is **true concurrency with two unprocessed same-amount/same-reason rows already present**: `find_matching_refund()` returns the *first* match in reversed order (`PaymentProcessingService.php:285-305`), so both racing operations could latch the same row → same instance → same key. In practice the order-wide lock at line 150 collapses this: the second racer is rejected with `native_payment_refund_locked` before it can reach the provider. The user-visible effect is a transient "refund already in progress" error, not a silently-dropped money movement.

### Client evidence — the client has neither construct
`includes/class-wc-payment-gateway-wcpay.php:2746-2846` `process_refund()`:
```php
$refund_request = Refund_Charge::create();
$refund_request->set_charge( $charge_id );
if ( null !== $amount ) {
    $refund_request->set_amount( WC_Payments_Utils::prepare_amount( $amount, $order->get_currency() ) );
}
$refund_request->set_full_reason( $reason );
$refund = $refund_request->send();        // :2806-2812 — no lock, no set_idempotency_key()
```
`Refund_Charge` *exposes* `set_idempotency_key()` (`includes/core/server/request/class-refund-charge.php:124`) but **nothing in the refund path calls it** (`grep set_idempotency_key includes/` → only the definition). So the request falls through to the random-UUID default at `class-wc-payments-api-client.php:2688` (`$headers['Idempotency-Key'] = '' !== $caller_idempotency_key ? $caller_idempotency_key : $this->uuid();`).

**Consequence:** in the client, two concurrent same-amount/same-reason refunds get two different random idempotency keys and **both succeed** — the client never collides them and never serializes them. There is no scenario where the client rejects a legitimate second refund. The error path Finding 1 describes (`native_payment_refund_locked`) simply does not exist in the client.

### Net judgement
This is a **property of net-new core machinery**, not a regression: core is *stricter* than the client (it can transiently reject a concurrent refund the client would let through). That strictness is defensible — it's the price of deterministic idempotency that prevents the *opposite* bug (a replayed key silently dropping a real refund), which is the failure the client's random-UUID approach structurally cannot have but a naive deterministic port would. The finding is real but low-severity: worst case is a retriable transient error on simultaneous duplicate refunds, never lost money. Recommend: keep the design; consider returning a clearer "retry" signal, and confirm `find_matching_refund`'s first-match-wins is acceptable under the lock (it is, because the lock prevents the concurrent second resolver from running at all).

---

## Finding 2 — bfcb6ce5: post-charge failure leaves order pending silently (`PaymentProcessingService.php:116`)

**Verdict: REGRESSION. Client handles this correctly; core does not. Confidence: high.**

### What core does
`process_checkout_outcome()` (`PaymentProcessingService.php:97-127`):
```php
try {
    $outcome = ... $this->charge_provider( $context, $provider, $idempotency_key );  // :119 — charge succeeds here
    $this->apply_checkout_outcome( $order, $outcome );                                // :121 — UNGUARDED
    return $outcome;
} finally {
    $this->order_payment_store->unlock_order_payment( $order );                        // :125 — only unlocks
}
```
`charge_provider()` swallows Throwable and returns a failed outcome (`:402-408`), so the *charge call* never throws. But `apply_checkout_outcome()` → `OrderPaymentLifecycleService::apply_unlocked()` (`OrderPaymentLifecycleService.php:105-124`) runs `save_meta_data()` (:114), `apply_status_transition()` → `payment_complete()` / `update_status()` (:116, :149-152), and `add_order_note()` (:122) **with no try/catch**. Any throw from those (DB error, or a third-party callback on `woocommerce_payment_complete` / `woocommerce_order_status_*`) propagates raw out of `process_checkout` → `NativeWooPaymentsGateway::process_payment()` (`NativeWooPaymentsGateway.php:713-746`, also no try/catch around the call at :732). The charge has already settled at the provider, yet the order keeps its prior status (pending), may lack a persisted `_intent_id`/`_charge_id` (if the throw was during `save_meta_data`), and gets **no failure note**.

### Client evidence — explicit defense-in-depth guard
`includes/class-wc-payment-gateway-wcpay.php`, `process_payment_for_order()` persists intent info **before** the status flip:
```php
$this->order_service->attach_intent_info_to_order( $order, $intent, $allow_update_on_success );   // :2114 — saves order
...
$this->order_service->update_order_status_from_intent( $order, $intent );                          // :2166 — status flip
```
`attach_intent_info_to_order__legacy()` ends with `$order->save()` after setting `_intent_id`, `_charge_id`, and the SUCCEEDED intention status (`includes/class-wc-payments-order-service.php:1230-1249`). So the success is **durably recorded before any post-charge step can throw**.

The surrounding `try` (`:1199` open) has a catch (`:1262`) with an explicit post-charge guard:
```php
// Defense in depth: when the payment intent already succeeded, a downstream exception
// (e.g. third-party plugin throwing from woocommerce_reduce_order_stock,
// woocommerce_payment_complete, or woocommerce_order_status_processing) must not flip
// the order to Failed or surface a failure to the customer who has already been charged.
if ( Intent_Status::SUCCEEDED === $this->order_service->get_intention_status_for_order( $order ) ) {
    $order->add_order_note( sprintf( __( 'Payment succeeded, but a downstream error occurred during
        post-payment processing: %s. Order status preserved.', ... ), esc_html( $e->getMessage() ) ) );
    Logger::warning( ... );
    return [ 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ];
}
```
(`class-wc-payment-gateway-wcpay.php:1266-1294`). The client explicitly engineered against exactly this failure mode: a post-charge exception preserves the order status, records a diagnostic note, and completes checkout as success rather than leaving a charged order looking pending/failed.

### Net judgement
The client (a) persists the payment reference before the status transition and (b) catches post-charge exceptions and converts them to a logged, noted soft-success. Core does **neither**: the charge succeeds, then an unguarded `apply_checkout_outcome` can throw and bubble out with the order left pending and no trace. This is a behavioural regression introduced by the merge. Recommend: wrap the post-charge `apply_checkout_outcome` so a successful outcome always persists the provider reference and never leaves a charged order silently pending — mirror the client's "intent succeeded → preserve status + diagnostic note + return success" guard.

---

## Finding 3 — 47644832: `add_payment_method()` does not verify SetupIntent customer ownership (`NativeWooPaymentsGateway.php:231`)

**Verdict: PARITY (same gap in client). Confidence: high. Bounded by the WPCOM site-scoped endpoint; not a cross-merchant leak.**

### What core does
`NativeWooPaymentsGateway::add_payment_method()` (`NativeWooPaymentsGateway.php:218-270`):
```php
$setup_intent_id = $this->sanitize_post_string( 'wcpay-setup-intent' );   // :220 — user-supplied
...
$setup_intent = $this->get_api_client()->get_setup_intention( $setup_intent_id );  // :231
$status = ... $setup_intent['status'] ...;
if ( 'succeeded' !== $status ) { return ...error; }                        // :233 — status check only
$payment_method_id = $this->get_setup_intent_payment_method_id( $setup_intent );   // :237
$token = $this->get_token_service()->get_or_create_card_token_for_user( $payment_method_id, $user_id ); // :242
```
It never compares `$setup_intent['customer']` against the current user's WooPayments customer ID. `get_setup_intent_payment_method_id()` (:1171-1181) only reads `payment_method`; no customer scoping. So a user who learns another user's SUCCEEDED SetupIntent ID (same store) could attach that payment method's token to their own account.

### Client evidence — same gap
`includes/class-wc-payment-gateway-wcpay.php:4250-4311` `add_payment_method()`:
```php
$setup_intent_id = ! empty( $_POST['wcpay-setup-intent'] ) ? wc_clean( $_POST['wcpay-setup-intent'] ) : false;  // :4266
$customer_id = $this->customer_service->get_customer_id_by_user_id( get_current_user_id() );                     // :4268
if ( ! $setup_intent_id || null === $customer_id ) { throw ...; }                                                // :4270 — only existence gate
$setup_intent = Get_Setup_Intention::create( $setup_intent_id )->send();                                         // :4277-4279
if ( Intent_Status::SUCCEEDED !== $setup_intent->get_status() ) { throw ...; }                                   // :4281 — status check
$payment_method = $setup_intent->get_payment_method_id();
$this->token_service->add_payment_method_to_user( $payment_method, wp_get_current_user() );                      // :4288-4289
```
The client fetches `$customer_id` only to assert the user *has* a WCPay customer (line 4270 null-gate). It **never compares `$setup_intent->get_customer()` to `$customer_id`** — even though the model exposes the getter (`includes/wc-payment-api/models/class-wc-payments-api-setup-intention.php:82` serializes `'customer' => $this->get_customer_id()`). The simpler helper `create_token_from_setup_intent()` (`:643-660`) is weaker still: it skips even the status check. So the client has the identical missing-ownership-check gap; core actually matches the client's stronger `add_payment_method()` (status check + token-for-user) rather than the weaker helper.

### Why this is bounded (the WPCOM-server question)
Both sides fetch the SetupIntent through the **site-scoped** WooPayments endpoint: client `Get_Setup_Intention` runs through the default `is_site_specific = true` request (adds the site fragment; `class-get-setup-intention.php:44-46` → `SETUP_INTENTS_API/{id}`), and core `WooPaymentsApiClient::get_setup_intention()` does `$this->request( array(), 'setup_intents/' . rawurlencode( $id ), 'GET' )` (`Api/WooPaymentsApiClient.php:389-391`), also site-scoped. The WPCOM Transact server scopes setup-intent lookups to the requesting site's connected merchant account, so the exposure is **same-merchant-store only** (one shopper attaching another shopper's PM on the *same* store), not cross-account. The platform does not scope by *end-customer* within an account, so the in-store ownership check is genuinely absent on both client and core.

### Net judgement
PARITY: core faithfully ported the client's existing (missing) ownership check. Not a regression. It *is* a real same-store IDOR-shaped gap on both sides and a good "do better than the client" candidate: add `$setup_intent['customer'] === <current user's _stripe_customer_id>` verification in core's `add_payment_method()` (and ideally push the same fix upstream to the client). Because both sides share the gap and the platform bounds it to one merchant account, severity is moderate, not critical.

---

## Summary table

| id | classification | confidence | client evidence | reason |
|----|----------------|-----------|-----------------|--------|
| 7c43c8da | NEW-IN-CORE | high | `class-wc-payment-gateway-wcpay.php:2806-2812` + `class-wc-payments-api-client.php:2688` | Lock + deterministic refund key are net-new; client uses random per-request UUID and never collides/serializes refunds. Core's order-wide lock can transiently reject a concurrent dup refund (retriable error, never lost money), not a client regression. |
| bfcb6ce5 | REGRESSION | high | `class-wc-payment-gateway-wcpay.php:1266-1294` + `class-wc-payments-order-service.php:1230-1249` | Client persists intent ref before status flip and catches post-charge exceptions into a logged soft-success that preserves status; core's `apply_checkout_outcome` is unguarded and bubbles out, leaving a charged order pending with no note. |
| 47644832 | PARITY | high | `class-wc-payment-gateway-wcpay.php:4266-4289` | Client `add_payment_method()` also checks only SetupIntent status, never `intent.customer == user's customer`; same site-scoped endpoint bounds both to one merchant account. Good "do better than client" target. |
