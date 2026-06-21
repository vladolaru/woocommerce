---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 04:28
tool: writing-plans
target: H25 N7a dispute webhook parity
reconciles:
  - analysis-h25-n7a-dispute-payout-multicurrency.md
  - review-agent-findings.md
last_updated: 2026-06-18 04:50
status: final
---

# H25 N7a Dispute Webhook Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore native WooPayments parity for `charge.dispute.*` webhook events so the N7(a) money-safety baseline covers dispute outcomes with source-backed order side effects.

**Architecture:** Keep dispute handling provider-owned in native WooPayments rather than forcing it through the generic lifecycle event abstraction. Add explicit charge-id order resolution, reference-compatible order status/note/refund mutations, and a small native API-client method for dispute summary reads used by closed/lost disputes.

**Tech Stack:** WooCommerce Core PHP, WooPayments native provider classes, PHPUnit in wp-env, ignored `tools/woopayments-merge` harness for live verification.

---

## File Map

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php`: remove dispute events from the known-unhandled set and add explicit dispute processing paths.
- Add `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeEventHandler.php`: provider-owned dispute side-effect handler with charge-id order resolution, reference-compatible notes/status/refund behavior, idempotency, and fail-closed validation.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`: add `get_dispute_summary( string $dispute_id ): array` using the WPCOM `disputes/{id}/summary` route.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestorTest.php`: add RED/GREEN coverage for dispute created, inquiry created, update/funds notes, closed won/warning/lost, charge-id resolution, missing-order fail-closed behavior, and idempotency.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`: add route/validation coverage for dispute summary.
- Add a WooCommerce Core changelog entry after source verification passes.
- Optional ignored harness edits only if live dispute checks need a better assertion than the current dispute-meta comparator.

## Task 1: RED Coverage For Dispute Webhook Side Effects

- [x] **Step 1: Add failing dispute event tests**

Add tests in `WooPaymentsEventIngestorTest` that use an order with `_charge_id=ch_123` and no `_intent_id` dependency. Cover the broad contract in one focused batch: `charge.dispute.created` changes status to `on-hold` and adds a dispute note; `warning_needs_response` uses inquiry wording; `charge.dispute.updated`, `funds_withdrawn`, and `funds_reinstated` add the reference update notes without changing status; `charge.dispute.closed` with `won` completes the order; `closed` with `lost` creates a local WC refund capped by dispute summary; duplicate delivery does not duplicate notes/refunds; missing charge order throws so the REST layer remains fail-closed.

Example helper shape:

```php
private function create_dispute_event( string $event_type, WC_Order $order, array $overrides = array() ): array {
	return array(
		'id'   => 'evt_dispute',
		'type' => $event_type,
		'data' => array(
			'object' => array_merge(
				array(
					'id'               => 'du_123',
					'charge'           => 'ch_123',
					'amount'           => 5000,
					'reason'           => 'fraudulent',
					'status'           => 'needs_response',
					'evidence_details' => array(
						'due_by' => strtotime( '2026-07-01 00:00:00 UTC' ),
					),
				),
				$overrides
			),
		),
	);
}
```

- [x] **Step 2: Run RED PHPUnit**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsEventIngestorTest
```

Expected: the new tests fail because native still throws `Native WooPayments webhook handling is not implemented for event type: charge.dispute.*`.

## Task 2: Implement Native Dispute Processor

- [x] **Step 1: Add provider-owned dispute handling**

In `WooPaymentsEventIngestor`, add a dispute branch before generic lifecycle creation:

```php
if ( $this->is_dispute_event( $event_type ) ) {
	$this->process_dispute_event( $event_type, $event_object );
	$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
	return;
}
```

Implementation details:

- Resolve the order from `data.object.charge` via `_charge_id`.
- Throw `RuntimeException` when a dispute event has no order so delivery fails closed instead of being acknowledged.
- For `charge.dispute.created`, format the amount in the order currency, append explicit currency text, convert `evidence_details.due_by` with `date_i18n( wc_date_format(), $timestamp )`, set status `on-hold`, and add the dispute/inquiry note once.
- For `charge.dispute.updated`, `funds_withdrawn`, and `funds_reinstated`, add the reference update note once and leave status unchanged.
- For `charge.dispute.closed`, fetch summary data through `WooPaymentsApiClient::get_dispute_summary()`. If fetch fails, continue with an empty summary like the reference does after logging. For `lost`, create a local refund with `wc_create_refund()` and `refund_payment=false`; cap the amount to the order's remaining refund amount and use empty line items for partial disputes. For non-lost closures, update status to `completed`.
- Do not write dispute-specific order meta. The reference does not.

- [x] **Step 2: Add dispute summary API method**

Add `WooPaymentsApiClient::get_dispute_summary()`:

```php
public function get_dispute_summary( string $dispute_id ): array {
	$this->validate_route_resource_id( $dispute_id );

	return $this->request( array(), 'disputes/' . $dispute_id . '/summary', 'GET' );
}
```

- [x] **Step 3: Run GREEN PHPUnit**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsEventIngestorTest|WooPaymentsApiClientTest'
```

Expected: the new dispute tests and API client route tests pass.

## Task 3: Harness-Oriented Verification And Logs

- [x] **Step 1: Check whether the existing reconciler would falsely fail reference-compatible dispute orders**

Use the source-backed finding from `analysis-h25-n7a-dispute-payout-multicurrency.md`: because the reference does not store dispute meta, do not claim the existing `dispute_id` meta comparator as a complete dispute oracle. If a live dispute run is possible, capture status/notes/refund evidence separately from the meta comparator.

- [x] **Step 2: Run focused static and unit gates**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsEventIngestorTest|WooPaymentsWebhookRestControllerTest|WooPaymentsApiClientTest|OrderPaymentLifecycleServiceTest'
composer exec --working-dir=plugins/woocommerce -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php --memory-limit=2G
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
git diff --check
```

Expected: all pass.

- [x] **Step 3: Run restored harness gates that do not require unsupported payout fixtures**

Run the existing financial fixture and cross-store verification gates after code passes:

```bash
bash tools/woopayments-merge/tests/financial-reconcile-fixtures.sh
bash tools/woopayments-merge/tests/compare-measured-gates-fixtures.sh
bash tools/woopayments-merge/verify.sh --ref 'docker exec -i wcpay_wp_default wp --allow-root' --target 'docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1'
```

Expected: fixture gates pass; cross-store verification either passes or reports a concrete product/runtime issue to fix before commit.

## Task 4: Review, Commit, And Session Docs

- [x] **Step 1: Run review agents**

Dispatch at least one reliability reviewer and one API/architecture reviewer over the H25 diff. Ask them specifically to check dispute idempotency, fail-closed behavior, local-refund semantics, route validation, and reference contract drift.

- [x] **Step 2: Fix any source-backed review findings**

Use RED/GREEN regression tests for any findings that affect product behavior. Do not change harness logic to mask bugs.

- [x] **Step 3: Add changelog and commit**

Add a WooCommerce changelog entry for native WooPayments dispute webhook parity and commit source/tests/changelog as one logical product change only after gates and review pass.

- [x] **Step 4: Update session logs**

Append H25 to `implementation-log.md`, `staging-log.md`, and `review-agent-findings.md` with source, tests, harness/browser evidence, review verdicts, and git range. Keep payout-linkage and converted-currency fixture hardening as follow-up N7(a) coverage gaps unless completed in this slice.
