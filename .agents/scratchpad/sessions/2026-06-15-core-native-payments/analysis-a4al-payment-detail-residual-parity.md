---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 08:22
reconciles:
  - analysis-a4ak-payment-detail-summary-composition-parity.md
  - analysis-a4x-money-detail-parity.md
  - analysis-a4ae-payment-detail-dispute-action-parity.md
  - analysis-a4ah-detail-authorization-actions.md
  - analysis-a4ai-refund-modal-parity.md
  - analysis-a4al-payment-method-mapping-subagent.md
  - readme-a4al-payment-method-mapping-subagent.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - staging-log.md
status: draft
last_updated: 2026-06-20 08:30
---

# A4al Payment Detail Residual Parity

## Trigger

A4ak closed the payment-detail read-only summary composition gap but intentionally left source-backed detail residuals open: `card_reader_fee` detail routing/table behavior, full timeline mapper parity, and payment-method detail variants beyond card/card-present/interac-present. This analysis determines whether those residuals should be handled as one coherent reopened-A4 slice or split, and records source evidence before any implementation plan.

## Working Direction

The likely A4al scope is the remaining native payment-detail residual set, excluding checkout/card shopper visual parity because that is a separate shopper runtime surface. The goal is to keep payment detail parity work coherent: route reader-fee details correctly, make the timeline semantically closer to the reference, and enrich supported non-card payment-method details where the existing native charge/PaymentIntent payload already contains enough data. The slice must not add new money-moving mutations or touch WPCOM.

## Source Evidence

Reference payment details compose the detail page from `PaymentDetailsSummary`, `PaymentDetailsTimeline`, `PaymentTransactionBreakdown`, and `PaymentDetailsPaymentMethod` under the detail-view test-mode notice in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-details/index.tsx:61`. A4ak covered the summary/payment-method skeleton, and `PaymentTransactionBreakdown` remains out of scope because the reference hard-disables it elsewhere, but the timeline and payment-method subtrees still have richer behavior than native.

Reference card-reader fees are not rendered through the normal payment summary. `PaymentCardReaderChargeDetails` calls `useCardReaderStats( props.chargeId, props.transactionId )`, renders the detail test-mode notice, and displays a `TableCard` titled `Card readers` with `Reader id`, `Status`, `Transactions`, and `Fee` columns plus CSV download in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/readers/index.js:27` and `:62`. Native already has a compatible reader-charge summary REST path: `WooPaymentsMobileRestController` registers `/payments/readers/charges/(?P<transaction_id>\w+)` in `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsMobileRestController.php:178`, resolves the transaction to a charge date, and calls `WooPaymentsApiClient::get_readers_charge_summary( $charge_date, $transaction_id )` in `WooPaymentsMobileRestController.php:551`. Native frontend data has no reader-charge helper yet; `data.ts` currently exposes payment details, timelines, refunds, and money-movement lists but no `/payments/readers/charges/*` call.

Reference timeline parity is significantly broader than native's current fallback label mapper. The reference `PaymentDetailsTimeline` uses `useTimeline()` and maps provider events through `mapTimelineEvents()` into WooCommerce `Timeline` items in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/timeline/index.js:18`. `map-events.js` handles status-change rows, payout impact rows, fee/tax/net body rows, refund reasons and ARN details, payment failures, disputes, financing paydown links, and automatic/manual fraud outcomes. Concrete event handling includes `started`, `authorized`, `authorization_voided`, `authorization_expired`, `captured`, `partial_refund`, `full_refund`, `refund_failed`, `failed`, dispute states, financing paydown, and fraud outcomes in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/timeline/map-events.js:789` through `:1254`. Native currently renders timeline events inline in `transaction-details-page.tsx` with `getTimelineMessage()` only special-casing manual fraud approve/block and otherwise `formatLabel( event.type )` at `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx:270`, then a simple `<ol>` at `transaction-details-page.tsx:1241`.

Native backend timeline support already proxies and lightly enriches platform data. `WooPaymentsPaymentDetailsRestController` registers `/payments/timeline/(?P<intention_id>\w+)` at `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentDetailsRestController.php:101`, calls `WooPaymentsApiClient::get_timeline()`, adds local manual fraud outcomes when platform fraud outcome events are present, and sorts by datetime plus reference event order at `WooPaymentsPaymentDetailsRestController.php:162`, `:246`, and `:371`. This means A4al timeline work can start as frontend mapper parity over the existing event payload; it should not invent missing platform fields. If an event body depends on missing fields such as fee envelopes, deposit objects, network costs, or bank names, render only from present data and keep gaps explicit.

Reference payment-method variants are implemented as a component map covering `affirm`, `alipay`, `afterpay_clearpay`, `amazon_pay`, `au_becs_debit`, `bancontact`, `card`, `card_present`, `eps`, `giropay`, `grabpay`, `ideal`, `klarna`, `p24`, `sepa_debit`, `sofort`, `multibanco`, and `wechat_pay` in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/index.js:31`. Many non-card variants read method-specific fields from `charge.payment_method_details[type]` and common billing fields from `billing_details`, for example base methods render `ID`, `Owner`, `Owner email`, and `Address` in `base-payment-method-details/index.tsx:20`, Amazon Pay adds `Amazon Transaction ID` in `amazon-pay/index.js:19`, Bancontact adds bank/BIC/verified name in `bancontact/index.js:19`, BECS adds `BSB` and masked account in `becs/index.js:19`, iDEAL adds bank/BIC/IBAN/verified name in `ideal/index.js:19`, SEPA adds masked IBAN and origin in `sepa/index.js:19`, and Klarna adds category and preferred locale in `klarna/index.js:19`. Native A4ak intentionally constrained rich rendering to `card`, `card_present`, and `interac_present`, with non-card methods falling back to Type/ID/Owner/Owner email in `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-detail-sections.tsx:506`. A4al can safely add method-specific rows for the variants present in the existing charge payload without backend expansion; unsupported or missing fields must degrade to dashes.

## Explorer Disposition

The card-reader-fee explorer confirmed this is a detail-route gap rather than a backend gap. Reference transaction rows treat `metadata.charge_type === 'card_reader_fee'` as the detail route discriminator, suppress source/customer columns for reader fees, and render reader-fee details from the reader-charge summary endpoint. Native already has the reader-charge REST route and API client method, but the money-movement frontend does not route `transaction_type=card_reader_fee` to a reader-fee component and does not expose a reader-charge data helper. A4al should add the frontend route branch, helper, table, loading/error states, and CSV export; it should not expand into Reports `reader_fees` rows or product-list table parity unless the payment-detail route needs that data.

The timeline explorer confirmed that native should keep the backend pass-through intact and add a frontend mapper over the event payload we already receive. The reference expands one provider event into several user-facing rows for status changes, payout impact, main event text, and event bodies, while native currently renders one fallback label row per event. A4al should implement a bounded mapper for the existing event types (`started`, authorization states, `captured`, refund states, `failed`, dispute states, financing paydown, and fraud outcomes), rendering only fields present on the event and retaining a safe fallback for unknown or sparse events.

The payment-method explorer is now captured in `analysis-a4al-payment-method-mapping-subagent.md`. It verified that native normalization already preserves `billing_details`, `payment_method`, and dynamic `payment_method_details[type]`, so a frontend-only renderer can add Address to generic non-card methods and add method-specific rows for `amazon_pay`, `au_becs_debit`, `bancontact`, `eps`, `giropay`, `ideal`, `klarna`, `p24`, `sepa_debit`, and `sofort`. Missing platform fields remain backend/platform follow-ups; native must not infer values it does not have. Wallet icon parity is intentionally deferred because it requires broader asset/mapping work than the detail-row parity needed here.

## Initial Boundaries

In scope candidates:

- Native payment detail behavior for `transaction_type === 'card_reader_fee'`, including reference-compatible card-reader fee details when the needed data is locally available.
- Timeline labels/content/status mapping for existing timeline data and local fraud/dispute/refund/capture metadata.
- Non-card payment-method detail rendering that can be derived from the current detail payload without platform or backend schema expansion.
- Focused Jest/PHP tests only where the surface actually changes, widened ignored A4 source gate tokens, Playwriter detail proofs, and log scans.

Out of scope candidates:

- Shopper checkout/card Elements visual parity.
- New platform/WPCOM API fields or WPCOM code changes.
- New refund/capture/dispute mutations beyond already implemented detail actions.
- Reports `reader_fees` / `Reader costs` row parity, because that belongs to Reports parity and not the payment-detail route residuals.
- Full final A4/N12 exit gate; A4al can strengthen it but should not claim admin readiness.
