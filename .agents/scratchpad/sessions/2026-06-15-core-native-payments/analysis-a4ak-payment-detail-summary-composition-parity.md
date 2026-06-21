---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 07:38
reconciles:
  - analysis-a4x-money-detail-parity.md
  - analysis-a4ae-payment-detail-dispute-action-parity.md
  - analysis-a4ah-detail-authorization-actions.md
  - analysis-a4ai-refund-modal-parity.md
  - analysis-a4aj-express-checkout-preview-parity.md
  - spec-conformance-baseline.md
  - staging-log.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: draft
---

# A4ak Payment Detail Summary Composition Parity

## Trigger

After A4aj, the remaining A4/N12 detail-surface residuals were broader payment-detail visual/copy comparison, richer detail composition, account-state/browser/log/bundle breadth, checkout/card visual parity, and the final accumulated A4 exit gate. Three read-only explorers checked the reference payment-detail page, the current native route, and the current harness coverage. Their shared conclusion is that the highest-value next chunk is the read-only payment-detail summary composition, using existing backend detail enrichment instead of adding new money-moving routes.

## Source Evidence

Reference WooPayments composes payment details as `Page`, detail-view `TestModeNotice`, `PaymentDetailsSummary`, optional `PaymentDetailsTimeline`, disabled `PaymentTransactionBreakdown`, and `PaymentDetailsPaymentMethod` in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-details/index.tsx:61`. The reference summary composes Date, Sales channel, Customer, Order, Subscription, Payment method, and Risk evaluation in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/summary/index.tsx:136`, with customer/order/subscription links from `CustomerLink` and `OrderLink`. The reference payment-method card renders richer card fields such as Number, Expires, Type, ID, Owner, Owner email, Address, Origin, and checks in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/card/index.js:123`.

Native transaction details currently renders a route heading plus one flat `<dl>` of identifiers, type/date/status/amount, customer text, plain order text, one-line payment method, risk, fee, and net amount in `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx:1042` and `:1176`. It already supports `pi_*`, `ch_*`/`py_*`, and `txn_*` detail loading, keeps refund/capture/fraud-review actions, and loads timeline data. Current UI gaps are therefore composition and data presentation, not route reachability or the action endpoints.

Native backend detail enrichment already supplies the relevant related-record data: `id`, `number`, `url`, `customer_url`, `customer_name`, `customer_email`, `fraud_meta_box_type`, `ip_address`, `suggested_product_type`, and `subscriptions` from `WooPaymentsMoneyMovementOrderService::build_detail_order_info()` at `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsMoneyMovementOrderService.php:662`. It also adds formatted billing addresses to embedded charge objects at `WooPaymentsMoneyMovementOrderService.php:738`. The native TypeScript types currently understate that shape.

Reference `PaymentTransactionBreakdown` is hard-disabled by `disableTransactionBreakdown = true` in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/transaction-breakdown/index.tsx:38`, so A4ak should not resurrect or invent a new transaction-breakdown card. Reference `card_reader_fee` detail routing is real debt, but it branches to a separate card-reader-fee table in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/readers/index.js:27`; native has the reader-charge REST route in `WooPaymentsMobileRestController.php:178`, but the transaction details page ignores `transaction_type`. That should be a later focused card-reader-fee route/table slice, not hidden inside the summary composition patch.

Harness coverage is currently too narrow for this claim: `tools/woopayments-merge/a4-admin-surface-gate.py` only checks payment-detail source tokens such as `Payment details`, `Payment ID`, `Payment method`, `Risk evaluation`, `Net amount`, and `Timeline` at `:520`. Existing A4ah/A4ai browser evidence proves target detail reachability and non-mutating refund-modal rendering, not reference-vs-target composition parity. A4ak needs a widened source token gate and a filtered Playwriter detail proof.

## A4ak Scope

Implement native payment-detail read-only summary composition parity. The page should keep existing transaction actions, authorization actions, dispute actions, refund modal behavior, route loading, and timeline loading unchanged while restructuring the rendered detail content around reference-shaped sections.

In scope:

- Add detail-view `WooPaymentsTestModeNotice` support for `payments`, using the existing native account-settings endpoint and canonical settings sub-route link.
- Add a prominent summary card with amount, status, fee, net amount, refunded amount when present, Date with time, Sales channel, Customer, Order, Subscription, Payment method, and Risk evaluation.
- Render customer/order/subscription links from the already enriched native detail payload. Order/subscription numbers should link when URLs exist and render a dash when absent.
- Render an explicit missing-order notice for payments that are not linked to a WooCommerce order so the absence of order actions is explained.
- Preserve and type detail response fields already returned by native backend enrichment: `customer_url`, `customer_name`, `customer_email`, `subscriptions`, payment method id/card metadata, billing details formatted address, and metadata enough for sales channel display.
- Add a payment-method details card for card/card-present data using the currently returned Stripe charge payload fields where present, with safe fallback dashes when fields are unavailable.
- Keep identifiers visible in a secondary detail card so support/debugging still has Payment ID, Charge ID, Transaction ID, and Type.
- Update focused Jest tests, source token gate, Playwriter detail proof, bundle measurement, and log scans around this slice.

Out of scope:

- No new refund/capture/dispute money-moving behavior.
- No `PaymentTransactionBreakdown` resurrection while reference keeps it hard-disabled.
- No full reference timeline mapper in A4ak; timeline parity is a larger semantic mapping slice.
- No `card_reader_fee` table route in A4ak; track it as a focused follow-up because it needs reader-charge stats routing and table/export behavior.
- No backend WPCOM changes and no WPCOM sandbox access.

## Proposed Verification

RED/GREEN focused Jest should cover the summary composition, linked customer/order/subscription records, missing-order notice, detail-view test-mode notice, richer card fields, amount/fee/net/refunded display, and that existing refund/capture/dispute controls still render when their existing eligibility fixtures apply. Static gates should include targeted ESLint, admin TypeScript, targeted Stylelint, and admin bundle build. The A4 source gate should add tokens for detail-view test-mode notice, summary card, linked records, missing-order notice, and payment-method card fields. Browser proof should use Playwriter on the target transaction detail route after rebuilding assets, capture desktop/mobile screenshots, assert the new composition markers, and scan target debug/Docker logs for fresh warnings/notices/errors.

## Residuals After A4ak

The next payment-detail residuals remain `card_reader_fee` route/table parity, payment-method support beyond card/card-present details, full semantic timeline mapper parity, browser fixtures for uncaptured/fraud-review/detail dispute states, and the accumulated A4/N12 exit gate. These are source-backed and should be tracked as follow-up slices instead of being assumed closed by A4ak.
