---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 06:08
reconciles:
  - analysis-a4ah-detail-authorization-actions.md
  - spec-conformance-baseline.md
  - staging-log.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: draft
last_updated: 2026-06-20 06:40
---

# A4ai Refund Modal Parity

> **Prompt:** "ok. continue"

## Starting State

A4ah is committed locally as source/tests `7c045db09d` plus changelog `f382f3a290`, with native admin readiness still fail-closed under N12. A4ah deliberately deferred refund modal parity because it is a real money-facing payment-detail residual and needs a native refund contract that does not weaken existing WooCommerce refund ownership, idempotency, or webhook behavior.

## Investigation Log

- 2026-06-20 06:08 EEST: Started A4ai as the next reopened-A4/N12 parity slice. Initial hypothesis from A4ah: restore the reference payment-detail full-refund modal for order-owned captured charges only, route partial refunds to the WooCommerce order page, and leave no-order charge-only refunds as a separate money-safety problem unless source verification shows the reference/native contract requires a safe broader path in this slice.
- 2026-06-20 06:11 EEST: Source-mapped the reference refund contract and native refund plumbing. Reference `payment-details/summary/index.tsx` shows `Transaction actions` only when the charge is captured, not fully refunded, and dispute-refundable; `Refund in full` is hidden once `amount_refunded > 0`, and `Partial refund` is available only when `charge.order.number` exists. Reference `refund-modal/index.tsx` posts a full amount refund through `usePaymentIntentWithChargeFallback().doRefund`, offers reasons `duplicate`, `fraudulent`, `requested_by_customer`, and `other`, warns that refunding an open inquiry closes it, and links partial refunds to the WooCommerce order screen when an order URL exists. Reference `WC_REST_Payments_Refunds_Controller` supports both order-backed `wc_create_refund( refund_payment => true, restock_items => true )` and a direct no-order platform refund. Native already has safe order-backed refund transport through `NativeWooPaymentsGateway::process_refund()`, `PaymentProcessingService::process_refund()`, and `WooPaymentsProviderGatewayAdapter::refund()`, including idempotency, provider `_wcpay_refund_*` metadata, order notes, and the native `WooPaymentsApiClient::refund_charge()` platform request. Native has no `/wc/v3/payments/refund` compatibility route and no transaction-detail refund UI. A4ai should add only the order-backed compatibility route in `WooPaymentsPaymentDetailsRestController` and fail closed for no-order charges, rather than porting the reference's direct no-order refund escape hatch without a separate money-safety design.
- 2026-06-20 06:34 EEST: Browser proof exposed an additional native detail-data gap. The target transaction detail page loaded the payment intent and charge IDs correctly, but `Transaction actions` stayed hidden because `/wc/v3/payments/payment_intents/{id}` returned `charges.data[0].order = null` while the transactions list had the local WooCommerce order URL. This was not stale frontend code. The fix is backend contract parity: reuse `WooPaymentsMoneyMovementOrderService` to enrich charge and payment-intent detail responses with the same local order context already used by transaction/dispute detail routes. The frontend keeps a defensive order-id parser for native HPOS `id=` and legacy `post=` order URLs, but the route contract now returns rich detail order data for the browser path.
- 2026-06-20 06:40 EEST: A4ai browser/log proof is now source-backed. Playwriter loaded the target native transaction detail, saw `Transaction actions`, opened `Refund in full`, verified the `Refund transaction` modal copy, reason radios, and `Go to the order` link, captured `data/a4ai-refund-modal-target.png` and `data/a4ai-refund-modal-browser.json`, and cancelled without submitting a refund. Target `debug.log` stayed 0 bytes; target web and WooCommerce logs showed no PHP diagnostics or actual 5xx lines. Local WPCOM web logs were clean; WPCOM jobs logs still contain background PHP 8.4 deprecation/database noise outside the target WooCommerce request path.

## Source Verification

Reference frontend behavior:

- `client/payment-details/summary/index.tsx` computes `showControlMenu = charge.captured && ! charge.refunded && isDisputeRefundable`, hides `Refund in full` when `charge.amount_refunded > 0`, exposes `Partial refund` only for an associated order, routes partial refunds to `charge.order.url`, and opens the same modal from open inquiry dispute guidance.
- `client/payment-details/summary/refund-modal/index.tsx` renders title/button `Refund transaction`, reason radio options, full-refund amount copy, optional open-inquiry copy, and optional `Need to refund part of the order? Go to the order.` link.
- `client/data/payment-intents/actions.ts` posts `{ charge_id, amount, reason, order_id }` to `/wc/v3/payments/refund/`, invalidates timeline and payment-intent selectors, and creates success/error notices.
- `includes/admin/class-wc-rest-payments-refunds-controller.php` first attempts an order-backed refund when `order_id` resolves to a `WC_Order`; otherwise it calls `Refund_Charge` directly with source `transaction_details_no_order`.

Native backend state:

- `WooPaymentsPaymentDetailsRestController` owns the WooPayments-compatible detail routes `/wc/v3/payments/charges`, `/payment_intents`, and `/timeline`, and is already registered only when the native runtime owns WooPayments. It is the correct local owner for an order-backed `/wc/v3/payments/refund` compatibility route.
- `WooPaymentsMoneyMovementOrderService` already owns the local order-context contract for transaction and dispute list/detail responses. Payment detail `charges` and `payment_intents` were bypassing that service, which is why the browser detail route had no order-backed refund action even though the transaction list had order context.
- `NativeWooPaymentsGateway::process_refund()` verifies the order exists and, for non-zero amounts, that the order has a WooPayments `_charge_id`, then delegates to `PaymentProcessingService::process_refund( PaymentContext::for_refund(...) )`.
- `PaymentProcessingService::process_refund()` treats zero amount as a no-op, derives an idempotency key from order/provider/action/amount/currency/reason, locks the order payment, delegates provider refund, applies provider metadata to the matching pre-created WC refund, and returns `WP_Error` on failures.
- `WooPaymentsProviderGatewayAdapter::refund()` prefers the native API client when connected, calls `refund_charge( charge, amount, reason, 'woocommerce_native', idempotency_key )`, normalizes `pending` and `succeeded`, and fail-closes failed provider statuses.
- `WooPaymentsApiClient::refund_charge()` posts to `wcpay/refunds`, lifts idempotency to the request header, sends `metadata.refund_source`, passes only Stripe-supported reason values as top-level reason, and preserves arbitrary merchant reason in `metadata.merchant_refund_reason`.

Native frontend state:

- `money-movement/data.ts` has helpers for charge, intent, timeline, list, dispute, and authorization operations, but no refund helper.
- `transaction-details-page.tsx` normalizes charge/payment-intent fields including `captured`, `refunded`, `amount_refunded`, `order`, and `dispute`, renders compact details plus dispute and authorization sections, and has reload/notice/focus infrastructure from A4ah that can be reused for refund success without broad page restructuring.
- `types.ts` has `WooPaymentsCharge`, `WooPaymentsPaymentIntent`, and `WooPaymentsTransaction` fields needed to gate refund UI, but `WooPaymentsPaymentOrder` currently lacks the `url` field used by the reference for partial-refund handoff.

## A4ai Decision

A4ai will restore order-backed refund modal parity only:

- Add `/wc/v3/payments/refund` as a native WooPayments-compatible POST route in `WooPaymentsPaymentDetailsRestController`.
- Require `order_id`, a valid `WC_Order`, a positive amount not greater than `order->get_remaining_refund_amount()`, and a charge ID matching the order's persisted `_charge_id`.
- Create the refund through `wc_create_refund( amount => interpreted minor-unit amount, reason, order_id, refund_payment => true, restock_items => true )` so the native gateway, idempotency, provider metadata, notes, and WooCommerce refund emails/hooks remain the single money path.
- Return the created refund facts on success and return `WP_Error` with an explicit status on invalid/missing order, charge mismatch, invalid amount, or gateway failure.
- Add native frontend `refundWooPaymentsCharge()` and a compact `Refund transaction` modal on transaction details. It will be shown for captured, non-refunded, dispute-refundable transactions with an order. It will hide full-refund once `amount_refunded > 0`, link partial refunds to the order URL when available, reload details/timeline after success, and surface notices on success/failure.
- Enrich native charge and payment-intent detail responses through `WooPaymentsMoneyMovementOrderService`, including `intent.charge` and `intent.charges.data[]`, so the detail route has the local WooCommerce order context required for order-backed refunds.

Deferred:

- No-order direct charge refunds and missing-order refund notices. The reference supports them, but native core should not add a direct platform money movement without a separate safety model, ownership proof, idempotency boundary, and reconciliation coverage.
- Richer reference payment-summary visual parity beyond the refund action surface.
- Live Stripe Elements settings preview and final accumulated A4/N12 gate.
