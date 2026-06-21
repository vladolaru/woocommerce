---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-18 07:55
last_updated: 2026-06-18 08:28
tool: ecosystem-integration-reviewer
target: H29 provider refund webhook uncommitted diff
reconciles:
  - analysis-h29-provider-refund-webhooks.md
status: final
---

# H29 Refund Webhook Integration Review

## Findings

### High: Split-UPE WooPayments orders are rejected by refund webhooks

`WooPaymentsRefundEventHandler::is_woopayments_order()` currently returns true only for `OrderPaymentStore::GATEWAY_ID`, so `get_order_for_charge_id()` throws after resolving a valid order whose payment method is a split-UPE WooPayments gateway such as `woocommerce_payments_sepa_debit`. The WooPayments extension explicitly treats gateway IDs beginning with `woocommerce_payments_` as split-UPE LPMs at `includes/class-wc-payment-gateway-wcpay.php:2425`, and its split-gateway test verifies an order can persist `woocommerce_payments_sepa_debit` at `tests/unit/payment-methods/test-class-upe-split-payment-gateway.php:958`. Native neighboring webhook code already accepts the same prefix for payment/dispute ownership at `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php:665` and `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeEventHandler.php:622`.

Impact: `charge.refunded` and `charge.refund.updated` for split-UPE WooPayments orders fail closed forever instead of applying the extension-parity refund side effect. That preserves safety but misses the stated H29 parity goal for non-card WooPayments orders.

### Medium: Refund notes drop WooPayments explicit currency formatting

The new handler formats created-refund and generic failed-refund notes with `wc_price()` at `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsRefundEventHandler.php:336` and `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsRefundEventHandler.php:382`. The extension's corresponding note paths wrap the formatted amount with `WC_Payments_Explicit_Price_Formatter::get_explicit_price()` at `includes/class-wc-payments-order-service.php:2323` and `includes/class-wc-payments-order-service.php:1788`; that formatter documents and implements the multi-currency suffix behavior at `includes/class-wc-payments-explicit-price-formatter.php:121`.

Impact: in multi-currency stores, merchant-facing refund notes can lose the explicit currency suffix the extension shows, for example `R$ 5,90 BRL` becoming just `R$ 5,90`. That is a source-backed note parity gap, even though the refund objects and WooPayments meta keys are otherwise preserved.

## Verified Compatible

The delivery hook arity matches the extension: both `woocommerce_payments_before_webhook_delivery` and `woocommerce_payments_after_webhook_delivery` pass `$event_type` and `$event_body` in the extension at `includes/class-wc-payments-webhook-processing-service.php:167` and `includes/class-wc-payments-webhook-processing-service.php:234`, and native dispatches the same two arguments through `run_delivery_hook()` at `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php:642`.

The manually fired `woocommerce_refund_deleted` hook matches WooCommerce core's two-argument AJAX deletion contract at `plugins/woocommerce/includes/class-wc-ajax.php:2477`.

The ingestor removes only `charge.refunded` and `charge.refund.updated` from `KNOWN_UNHANDLED_EVENT_TYPES`; account, invoice, and notification events remain cutover blockers.

## Fix Resolution

Both original review findings were fixed before commit. `WooPaymentsRefundEventHandler::is_woopayments_order()` now accepts the exact `woocommerce_payments` gateway ID and split-UPE IDs with the WooPayments gateway prefix, and regression coverage proves `woocommerce_payments_sepa_debit` refund webhook handling. Merchant notes now use the WooPayments explicit-price formatter when the extension class is available, with a native fallback that appends the order currency when multi-currency is active.

The adversarial follow-up review found two additional high-risk edges and both were fixed before the final gates: duplicate stale pending retries no longer downgrade a successful existing refund status, and missing/malformed `captured` fields now fail closed. Regression tests cover both cases.
