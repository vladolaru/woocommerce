---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 06:11
reconciles:
  - ../analysis-a4ai-refund-modal-parity.md
  - ../supervisor-prompt-2026-06-18-2344-N12.md
status: complete
last_updated: 2026-06-20 06:51
---

# A4ai Refund Modal Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore native WooPayments payment-detail full-refund modal parity for order-backed captured transactions without adding the reference plugin's direct no-order refund escape hatch.

**Architecture:** Add the WooPayments-compatible `/wc/v3/payments/refund` POST route to the existing native payment-detail controller, but route only through WooCommerce order refunds so `wc_create_refund()`, the native gateway, provider idempotency, refund metadata, order notes, emails, and webhook follow-up remain the single money path. The frontend will add a small typed refund helper and scoped modal/action UI on the existing transaction details page, with partial refunds linking to the WooCommerce order page. No-order direct charge refunds are explicitly deferred to a separate money-safety design.

**Tech Stack:** WooCommerce PHP REST controllers/tests, WooCommerce order refund APIs, native WooPayments gateway refund transport, React/TypeScript, WordPress components/notices, WooCommerce admin Jest/RTL tests, Playwriter browser proof.

---

## File Structure

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentDetailsRestController.php`: add creatable refund route, input validation, order-backed `wc_create_refund()` call, and explicit fail-closed errors.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsMoneyMovementRestControllerTest.php`: add RED tests for route registration, order-backed success, charge/order mismatch, missing order, invalid amount, and gateway failure behavior.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/data.ts`: add `refundWooPaymentsCharge()`.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts`: add order URL and refund response typing.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx`: add refund action gating, modal, pending/error/success handling, partial refund link, reload, and focus return.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`: add scoped refund action/modal spacing matching the native admin surface.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx`: add RED frontend tests for menu visibility, full-refund modal, reason submission, partial-refund link, hidden states, success reload, failure notice, pending state, and open-inquiry copy.
- Add WooCommerce changelog entry after product gates are green.

## Task 1: Backend Order-Backed Refund Route

- [x] **Step 1: Write RED route-registration and success tests**

Add tests to `WooPaymentsMoneyMovementRestControllerTest.php` near the existing payment-detail route tests. The success test should create a real `WC_Order`, set currency/total/payment method/`_charge_id`/`_intent_id`, stub `woocommerce_refund_payment_gateway` to a local gateway object that returns `true`, dispatch `POST /wc/v3/payments/refund` with `{ charge_id: 'ch_order', amount: 5000, reason: 'requested_by_customer', order_id: $order->get_id() }`, and assert status 200, one refund created for `$50.00`, `refunded_payment` true, reason stored, and the route exists only when native owns runtime.

- [x] **Step 2: Write RED fail-closed tests**

Cover missing `order_id`, unknown order, charge/order mismatch, zero/negative/too-large amount, and gateway `WP_Error`. Assert non-2xx statuses, stable WooPayments-style error codes, no refund rows on pre-validation failures, and the gateway-failure path deleting the attempted refund as `wc_create_refund()` already does.

- [x] **Step 3: Implement the route minimally**

In `WooPaymentsPaymentDetailsRestController::register_routes()`, add `register_rest_route( self::NAMESPACE, '/payments/refund', $this->get_creatable_route( 'process_refund' ) );`. Implement `process_refund( WP_REST_Request $request )` to require an order-backed refund, validate `charge_id`, `amount`, and `order_id`, compare `charge_id` to `$order->get_meta( '_charge_id', true )`, convert minor-unit amount using `wc_format_decimal( $amount / 100, wc_get_price_decimals() )`, call `wc_create_refund( array( 'amount' => $refund_amount, 'reason' => $reason, 'order_id' => $order->get_id(), 'refund_payment' => true, 'restock_items' => true ) )`, and return a small response with `id`, `order_id`, `amount`, `reason`, and `status`.

- [x] **Step 4: Run backend RED/GREEN verification**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsMoneyMovementRestControllerTest
```

Expected after RED: the new tests fail because `/wc/v3/payments/refund` is missing. Expected after implementation: focused suite passes with the new refund assertions.

## Task 2: Frontend Refund Modal and Detail Actions

- [x] **Step 1: Write RED data/helper and page tests**

In `money-movement-pages.test.tsx`, mock `refundWooPaymentsCharge` and add detail tests that load a captured, non-refunded payment intent with `order.url`, open the `Transaction actions` menu, open `Refund in full`, select `Requested by customer`, submit `Refund transaction`, assert `refundWooPaymentsCharge( { chargeId: 'ch_test', amount: 5000, reason: 'requested_by_customer', orderId: 123 } )`, success notice, detail/timeline reload, and focus restoration to `Payment details` when the modal action had focus. Add tests for hidden action when `captured === false`, hidden `Refund in full` when `amount_refunded > 0`, partial-refund link to `order.url`, open-inquiry explanatory copy, pending `Refunding transaction for order #123`, and failure notice without closing the modal.

- [x] **Step 2: Implement data helper and types**

Add a typed helper in `data.ts`:

```ts
export const refundWooPaymentsCharge = ( {
	chargeId,
	amount,
	reason,
	orderId,
}: WooPaymentsRefundRequest ): Promise< WooPaymentsRefundResponse > =>
	apiFetch< WooPaymentsRefundResponse >( {
		path: `${ PAYMENTS_PATH }/refund`,
		method: 'POST',
		data: {
			charge_id: chargeId,
			amount,
			reason,
			order_id: orderId,
		},
	} );
```

Add `WooPaymentsRefundRequest`, `WooPaymentsRefundResponse`, and `url?: string` on `WooPaymentsPaymentOrder` in `types.ts`.

- [x] **Step 3: Implement the detail UI**

In `transaction-details-page.tsx`, add the `Transaction actions` menu for captured, not fully refunded, dispute-refundable, order-backed transactions; add a `Refund transaction` modal with reason radios, open-inquiry copy, full-refund amount copy, `Cancel`, `Refund transaction`, and optional partial-refund link. Use native `Button`, `DropdownMenu`, `MenuGroup`, `MenuItem`, `Modal`, `RadioControl`, and existing notice dispatch. On success, close the modal, reload transaction/timeline without full-page loading, create `Refunded payment #pi_...` success notice, and restore focus to the `Payment details` heading if focus was inside the modal or lost to `body`. On failure, keep the modal open and create a concise error notice.

- [x] **Step 4: Add scoped styles**

Add `.woocommerce-woopayments-money-movement__refund-modal`, `.woocommerce-woopayments-money-movement__refund-actions`, `.woocommerce-woopayments-money-movement__refund-reason`, and `.woocommerce-woopayments-money-movement__partial-refund` styles to `admin/style.scss`, keeping spacing stable on desktop/mobile and avoiding nested cards.

- [x] **Step 5: Run frontend RED/GREEN verification**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js --runTestsByPath client/woopayments/admin/test/money-movement-pages.test.tsx --runInBand
```

Expected after RED: refund tests fail because the helper/UI is missing. Expected after implementation: focused suite passes without React warnings.

## Task 3: Review and Closeout Gates

- [x] **Step 1: Run focused static and backend gates**

Run exact-file ESLint, admin type lint, targeted Stylelint, PHP syntax, changed-file PHPCS, production PHPStan for `WooPaymentsPaymentDetailsRestController.php`, focused PHPUnit, focused Jest, and admin bundle build. Do not lint `.agents/scratchpad`.

- [x] **Step 2: Browser/log proof**

Use Playwriter on the target native store to load a current captured order-backed transaction detail. Verify the action menu appears, open and cancel the refund modal without mutating unless a deterministic throwaway order exists, verify partial-refund link href when order URL exists, capture screenshot/evidence under `data/a4ai-*`, and scan target `debug.log` plus relevant Docker logs for fresh PHP notices/warnings/fatals/deprecations.

- [x] **Step 3: Review gates**

Run local source-backed reviews for money-safety/reliability, frontend accessibility/focus behavior, and API contract parity. Fix source-backed findings before closeout.

- [x] **Step 4: Changelog, docs, branch gates, and commit**

Add a WooCommerce changelog entry, update `analysis-a4ai-refund-modal-parity.md`, `implementation-log.md`, `staging-log.md`, `spec-conformance-baseline.md`, `review-agent-findings.md`, and `README.md`. Run changelog validation, `git diff --check -- . ':!.agents'`, and `pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch`. Commit locally in logical commits only after gates pass; do not push.
