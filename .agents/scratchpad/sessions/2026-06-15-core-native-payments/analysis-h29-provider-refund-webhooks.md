---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 07:37
last_updated: 2026-06-18 08:33
target: H29 provider refund webhook migration
reconciles:
  - spec-conformance-baseline.md
  - review-agent-findings.md
  - staging-log.md
status: final
---

# H29 Provider Refund Webhooks

## Source Findings

At H29 selection time, the A2/A5 blocker was source-backed: `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` included `charge.refunded` and `charge.refund.updated`, and `build_lifecycle_event()` threw for known-unhandled types. The cutover controller consumed that list, so these two refund events kept native cutover fail-closed before H29.

The reference extension handles `charge.refunded` in `WC_Payments_Webhook_Processing_Service::process_webhook_refund_triggered_externally()`. It processes only `charge.refunded` with charge `status=succeeded` and `captured=true`, reads the most recent refund from `refunds.data[0]`, resolves the order by `_charge_id`, dedupes existing WC refunds by `_wcpay_refund_id`, validates the Stripe charge/refund amount against the order total, creates a local WC refund for the refunded amount, passes line items for full refunds only, and writes WooPayments refund note/meta through `WC_Payments_Order_Service::add_note_and_metadata_for_created_refund()`.

The reference extension handles `charge.refund.updated` in `process_webhook_refund_updated()`. It requires `charge`, `id`, `amount`, `currency`, and `status`, resolves the order by charge ID, finds an existing WC refund by `_wcpay_refund_id`, handles `failed` and `canceled` through `handle_failed_refund()` including optional `failure_reason`, and for `succeeded` writes note/meta only when a matching WC refund exists. Unknown statuses throw and fail closed.

Existing native code already preserved `_wcpay_refund_id`, `_wcpay_refund_transaction_id`, and `_wcpay_refund_status` in Bucket E; native refund processing already wrote those keys when merchant-initiated refunds succeeded. The external provider-event path was the missing side effect, so H29 needed a WooPayments-provider handler rather than widening the generic lifecycle abstraction with WooPayments refund object shapes.

## Architecture Decision

H29 added `WooPaymentsRefundEventHandler` under `src/Internal/Payments/Providers/WooPayments/`, parallel to `WooPaymentsDisputeEventHandler`. The handler owns provider-specific refund payload parsing, order lookup by `_charge_id`, local refund creation, WooPayments note/meta formatting, duplicate detection, failed/canceled update handling, and fail-closed validation. `WooPaymentsEventIngestor` dispatches refund events before lifecycle-event construction and removes only `charge.refunded` and `charge.refund.updated` from `KNOWN_UNHANDLED_EVENT_TYPES`; account, invoice, and notification events remain cutover blockers.

Local timing is not relevant for this slice except that the full branch should not add broad runtime overhead. The source-backed PHPUnit regressions live in `WooPaymentsEventIngestorTest` and cover full/partial external refunds, duplicate refund dedupe, failed/canceled/succeeded update behavior, missing order/invalid amount/malformed status fail-closed behavior, and the remaining known-unhandled list.

## Implementation Result

H29 is implemented in Core with `WooPaymentsRefundEventHandler` and `WooPaymentsEventIngestor` dispatching `charge.refunded` and `charge.refund.updated` before generic lifecycle construction. The known-unhandled provider-event list now intentionally excludes only those two refund events; account, invoice, and `wcpay.notification` events remain cutover blockers.

The handler preserves provider-owned refund semantics at the WooPayments boundary: charge-id order lookup, split-UPE WooPayments gateway ownership, optional order-key validation, shared `OrderPaymentStore` locking, full/partial local refund creation, `_wcpay_refund_id`, `_wcpay_refund_transaction_id`, `_wcpay_refund_status`, WooPayments-compatible notes, failed/canceled local refund deletion, `woocommerce_refund_deleted`, succeeded-update metadata repair, explicit-currency note formatting, and FROD insufficient-funds copy. The generic payment lifecycle abstraction was not widened with WooPayments refund object shapes.

Review follow-up hardened two reliability/API-contract edges: stale pending duplicate `charge.refunded` retries no longer downgrade an already successful refund status, and missing or malformed `captured` on `charge.refunded` now fails closed instead of being interpreted as an uncaptured no-op. The HPOS same-request `get_refunds()` stale collection behavior after deletion was treated as cache noise only after durable deletion was verified through `wc_get_order()`/`get_post()` and the deleted-refund hook.
