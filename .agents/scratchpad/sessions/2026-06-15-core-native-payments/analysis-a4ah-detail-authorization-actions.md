---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 05:28
reconciles:
  - analysis-a4ag-next-parity-slice.md
  - spec-conformance-baseline.md
  - staging-log.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: closed
---

# A4ah Detail Authorization Actions

> **Prompt:** "Continue working toward the active thread goal."

## Starting State

A4ag is committed as source/tests `377a6cbe76` plus changelog `30cdbcf46f`, and the product tree is clean. Native admin readiness remains fail-closed under N12 because the accumulated admin surfaces still have source-backed parity gaps. The A4ag closeout explicitly left three major residuals: detail-level capture/cancel/fraud-review actions, refund modal parity, and the live express checkout settings preview.

## Parallel Findings

Kepler source-mapped payment-detail authorization behavior. The reference payment details summary fetches authorization state only when the charge is uncaptured, the display status is `authorized` or `fraud_outcome_review`, and `amount_refunded === 0`. Fraud review is a `requires_capture` PaymentIntent plus `order.fraud_meta_box_type === 'review'`. The reference exposes `Block transaction` and `Approve Transaction` for fraud review, and exposes a normal `Capture` action in the authorization notice. It does not expose a normal detail-level cancel action outside the fraud-review block path. Native already has guarded authorizations REST endpoints and list-level capture/cancel buttons, but transaction details renders only compact details and timeline.

Archimedes source-mapped refund modal parity. The reference full-refund action is available on captured, non-refunded, dispute-refundable charges, and partial refunds route to the WooCommerce order page. Native currently has no native `/wc/v3/payments/refund` POST route for admin detail, but order-bound refunds already flow through `wc_create_refund( refund_payment => true )`, the native gateway, the payment processing service, and refund webhooks. A safe first native refund-modal slice should be order-owned full refunds only, with server-side ownership, remaining-amount, platform-state, and idempotency checks; no-order charge-only refunds remain a separate money-safety problem.

Boole source-mapped the express settings preview. The reference admin preview is a live Stripe Express Checkout preview, not a card PaymentElement preview. Native checkout already has the Elements `loader: 'never'` plus appearance/fonts path, while the native settings preview currently falls back to static notices. This can be a separate settings-only slice contained to `client/admin/client/woopayments/settings/express-checkout/` plus minimal Stripe.js/config plumbing.

## Source Verification

Native `transaction-details-page.tsx` imports `getWooPaymentsCharge`, `getWooPaymentsPaymentIntent`, `getWooPaymentsTimeline`, and `getWooPaymentsTransaction`, normalizes `captured`, `amount_refunded`, `refunded`, `order`, `outcome`, and status fields, and renders `<dl>` details plus disputes and timeline. It does not import or render `getWooPaymentsAuthorization`, `captureWooPaymentsAuthorization`, or `cancelWooPaymentsAuthorization`.

Native `data.ts` already exposes `getWooPaymentsAuthorization( paymentIntentId )`, `captureWooPaymentsAuthorization( orderId, paymentIntentId )`, and `cancelWooPaymentsAuthorization( orderId, paymentIntentId )`. Native `transactions-page.tsx` already uses those action helpers for the uncaptured list, including busy state and notices, which gives a local UI pattern to reuse.

Native `WooPaymentsAuthorizationsRestController::handle_authorization_action()` already checks order existence, refunded-order rejection, order-intent ownership, live PaymentIntent metadata ownership, live `requires_capture` status, manual fraud-outcome entry persistence, native processing delegation, and expected action outcome. That is the key safety reason to prefer detail-page authorization actions before the new refund modal contract.

Native `WooPaymentsMoneyMovementOrderService::build_detail_order_info()` already includes `fraud_meta_box_type`, and the detail frontend can detect review from `transaction.order.fraud_meta_box_type === 'review'` plus `transaction.status === 'requires_capture'` or an uncaptured/capturable PaymentIntent. `WooPaymentsPaymentDetailsRestController` returns raw platform charge/payment-intent details for `pi_*`/`ch_*` routes, so the frontend implementation should preserve the existing detail response shape and make the fraud-review detection tolerant of either `charge.order` or `paymentIntent.order` carrying `fraud_meta_box_type`.

Reference `payment-details/summary/index.tsx` confirms the intended boundary: `Block transaction` calls the cancel authorization path only for fraud review, `Approve Transaction` calls capture, and the ordinary authorization notice renders only `Capture`. Refund dropdown/modal are independent UI in the same reference component but should not be bundled into this slice because they need a new native refund route and money-safety proof.

## A4ah Decision

A4ah will implement detail-page authorization and fraud-review actions only. Scope:

- Load authorization state for capturable detail pages when the transaction has a PaymentIntent, is not captured, and has no refunded amount.
- Render a normal authorization notice with a `Capture` button when the transaction is uncaptured and not fraud-review held.
- Render a fraud-review action row with `Block transaction` and `Approve transaction` when the transaction is a fraud-review hold.
- Call the existing guarded order-scoped capture/cancel authorization helpers, reuse the list-level notice semantics, and reload transaction/timeline/authorization data after success.
- Keep normal detail-level cancel hidden because the reference only exposes cancel as `Block transaction` in the fraud-review path.

Deferred:

- Refund modal parity: real, money-facing, needs a new native POST contract and order-owned full-refund safety proof.
- Express settings live preview: real, settings-only, should stay isolated from checkout bundles and from this money-detail slice.
- Full visual/copy detail parity and final A4/N12 gate: still open after this slice.

## Required Gates

TDD starts in `money-movement-pages.test.tsx` with RED detail-page tests for normal capture, fraud approve, fraud block, busy/disabled action state, error notice behavior, and successful post-action reload. Existing PHP authorization controller tests remain regression gates because this slice intentionally relies on the existing backend safety path. Browser proof should avoid mutating irreversible live store state unless a deterministic local authorization fixture is created; if the local environment cannot safely provide one, record the browser gate as reachability/no-regression proof and keep the money mutation covered by Jest plus the existing PHP endpoint tests.

## Closeout Evidence

A4ah implemented the selected scope without adding new backend money-moving routes. Native transaction details now loads authorization state only for eligible uncaptured PaymentIntent details, renders normal `Capture` and fraud-review `Block transaction` / `Approve transaction` controls, calls the existing guarded order-scoped authorization helpers, reloads detail/timeline/authorization data on success, surfaces non-404 authorization load/action failures, guards stale-route completions, and restores focus only when focus was lost or still inside the replaced authorization action region.

The deterministic gate covered the actual mutation behavior because the live target store did not expose a safe detail-level uncaptured fixture to mutate from the browser. Focused `money-movement-pages.test.tsx` passed with 38 tests after review fixes, including capture, fraud approve/block, pending accessible names, stale route guarding, cross-route error notices, authorization load failures, and no focus stealing after user focus moves elsewhere. Focused backend `WooPaymentsMoneyMovementRestControllerTest` passed with 34 tests and 219 assertions, preserving the money-safety checks for order ownership, provider intent state, refunded orders, fraud outcome persistence, and expected capture/cancel outcomes.

Playwriter session `77` verified the target native transactions list, the uncaptured list, and a current PaymentIntent detail route at `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions%2Fdetails&id=pi_3TjXQGJd67Ti1EoI000GiBWD&transaction_id=txn_3TjXQGJd67Ti1EoI0zkN9nNm&transaction_type=charge`. The loaded detail route rendered `Payment details`, payment/charge/transaction IDs, payment method, risk, fee/net amount, and timeline, with `failedResponses: []`, no page errors, and only the known Chrome `Permissions policy violation: unload is not allowed in this document.` console noise. Evidence is saved in `data/a4ah-transaction-detail-browser.json` and `data/a4ah-transaction-detail-browser.png`. The target `debug.log` stayed at 0 bytes after the browser proof, and target/local WCPay simulator Docker log scans for the proof window found no PHP notices, warnings, deprecations, fatals, uncaught exceptions, stack traces, or database errors.
