---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 04:00
tool: writing-plans
target: H24 N7a auth/capture financial matrix closure
reconciles:
  - ../analysis-h24-n7a-auth-capture-drivers.md
  - ../staging-log.md
  - ../review-agent-findings.md
status: draft
last_updated: 2026-06-18 04:22
---

# H24 N7a Auth/Capture Financial Matrix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the N7a money-safety baseline materially stronger by restoring native WooPayments manual authorization request parity and adding deterministic local auth/capture financial reconciliation drivers for reference and native stores.

**Architecture:** Keep money operations provider-owned: native PaymentIntent creation must mirror the WooPayments extension `capture_method` request contract, while capture/cancel execution continues through `PaymentProcessingService` and `WooPaymentsProviderGatewayAdapter`. Keep harness work in ignored `tools/woopayments-merge` files and fail-closed; harness changes may expose product bugs but must not mask them. This slice proves provider/service money-path coverage only and does not claim A4 merchant admin capture UI parity.

**Tech Stack:** WooCommerce Core PHP, WooPayments provider adapter, WooCommerce PHPUnit, ignored Bash/PHP harness scripts, Stripe CLI raw-source financial reconciliation.

---

## Task 1: Restore Native Manual-Capture Request Parity

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`

- [x] **Step 1: Write the failing request-payload test**

Add a test near the native PaymentIntent request tests in `WooPaymentsProviderGatewayAdapterTest.php`:

```php
/**
 * @testdox Charge should send manual capture mode to native payment intents when enabled.
 */
public function test_charge_sends_manual_capture_method_to_native_payment_intents_when_enabled(): void {
	$order            = $this->create_woopayments_order();
	$gateway          = new RecordingLegacyGateway( array( 'result' => 'success' ) );
	$api_client       = new class() extends WooPaymentsApiClient {
		public array $last_request_data = array();

		public function is_available(): bool {
			return true;
		}

		public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
			unset( $idempotency_key );
			$this->last_request_data = $request_data;

			return array(
				'id'             => 'pi_manual_native',
				'status'         => 'requires_capture',
				'customer'       => 'cus_manual_native',
				'payment_method' => 'pm_manual_native',
				'currency'       => 'usd',
				'charges'        => array(
					'total_count' => 1,
					'data'        => array(
						array(
							'id'       => 'ch_manual_native',
							'captured' => false,
						),
					),
				),
			);
		}
	};
	$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
		->disableOriginalConstructor()
		->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
		->getMock();
	$customer_service->expects( $this->once() )
		->method( 'get_or_create_customer_id_for_order' )
		->willReturn( 'cus_manual_native' );

	$sut = $this->create_adapter(
		$gateway,
		$api_client,
		$customer_service,
		null,
		$this->create_account_service( false, array( 'manual_capture' => 'yes' ) )
	);

	$outcome = $sut->charge( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_manual_native' ), 'key_charge' );

	$this->assertSame( 'manual', $api_client->last_request_data['capture_method'] ?? null );
	$this->assertSame( PaymentOutcome::STATUS_AUTHORIZED, $outcome->get_status() );
}
```

- [x] **Step 2: Verify RED**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsProviderGatewayAdapterTest::test_charge_sends_manual_capture_method_to_native_payment_intents_when_enabled
```

Expected: FAIL because `capture_method` is absent from the native PaymentIntent request payload.

- [x] **Step 3: Implement the product fix**

In `WooPaymentsProviderGatewayAdapter::build_native_charge_request_data()`, after the base `$request_data` array is created and before platform-payment-method application, set the capture method from the persisted WooPayments gateway setting:

```php
$request_data['capture_method'] = 'yes' === $this->get_account_service()->get_gateway_setting( 'manual_capture', 'no' ) ? 'manual' : 'automatic';
```

Do not add this to setup intents. Do not put the setting in generic `PaymentProcessingService`.

- [x] **Step 4: Verify GREEN**

Run the focused test again. Expected: PASS.

## Task 2: Add Deterministic Authorization And Capture Drivers

**Files:**
- Modify: `tools/woopayments-merge/flow-drive.sh`
- Modify: `tools/woopayments-merge/flow-drive-deterministic-charge.php`
- Modify: `tools/woopayments-merge/flow-drive-native-charge.php`
- Create: `tools/woopayments-merge/flow-drive-capture.php`
- Optionally create: `tools/woopayments-merge/flow-drive-cancel.php` if canceling uncaptured authorizations is needed to avoid leaving stale local fixtures.

- [x] **Step 1: Extend deterministic charge with `--manual-capture`**

In `flow-drive.sh`, add `MANUAL_CAPTURE=0`, parse `--manual-capture`, and pass a fourth eval-file argument to both deterministic charge drivers:

```bash
--manual-capture) MANUAL_CAPTURE=1; shift ;;
```

For native deterministic charge:

```bash
raw="$($WP eval-file - "$SKU" "$QUANTITY" "$PAYMENT_METHOD" "$MANUAL_CAPTURE" < "$SELF_DIR/flow-drive-native-charge.php" 2>&1)"
```

For reference deterministic charge:

```bash
raw="$($WP eval-file - "$SKU" "$QUANTITY" "$TYPE" "$MANUAL_CAPTURE" < "$SELF_DIR/flow-drive-deterministic-charge.php" 2>&1)"
```

In both PHP drivers, snapshot the current `woocommerce_woocommerce_payments_settings` option, set `manual_capture` to `yes` when the fourth arg is truthy, and restore the original option in a `finally` block. The driver output must include `manual_capture` and the resulting order `_intention_status`.

- [x] **Step 2: Add deterministic capture operation**

Create `flow-drive-capture.php` that accepts an order id, validates the order is WooPayments-paid and currently authorized, then executes the correct runtime path:

```php
if ( class_exists( Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter::class ) ) {
	$arbiter = wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter::class );
	if ( Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter::OWNER_NATIVE === $arbiter->get_runtime_owner() ) {
		$service  = wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\PaymentProcessingService::class );
		$provider = wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider::class );
		$outcome  = $service->capture(
			Automattic\WooCommerce\Internal\Payments\PaymentContext::for_capture( $order, Automattic\WooCommerce\Internal\Payments\OrderPaymentStore::GATEWAY_ID ),
			$provider
		);
		// Emit JSON and fail if $outcome is not completed.
	}
}

$gateway = WC()->payment_gateways()->payment_gateways()['woocommerce_payments'] ?? null;
$result  = is_object( $gateway ) && is_callable( array( $gateway, 'capture_charge' ) ) ? $gateway->capture_charge( $order ) : null;
```

The JSON output must include `op`, `order_id`, `intent_id`, `charge_id`, `status`, `intention_status`, and `success`.

- [x] **Step 3: Wire `flow-drive.sh capture --deterministic --order-id=<id>`**

Allow `capture` as a deterministic operation that calls `flow-drive-capture.php`. Keep non-deterministic Test Lab behavior unchanged for reference/dev-tools operations unless the deterministic flag is present.

- [x] **Step 4: Syntax-check harness changes**

Run:

```bash
bash -n tools/woopayments-merge/flow-drive.sh
php -l tools/woopayments-merge/flow-drive-deterministic-charge.php
php -l tools/woopayments-merge/flow-drive-native-charge.php
php -l tools/woopayments-merge/flow-drive-capture.php
```

Expected: all pass. Scratchpad/harness files remain ignored/local.

## Task 3: Run Auth/Capture Financial Reconciliation On Reference And Target

**Files:**
- Local ignored harness only unless product drift is found.

- [x] **Step 1: Drive reference authorization and capture**

Run with the reference store:

```bash
REF_WP="docker exec -i wcpay_wp_default wp --allow-root"
ref_auth="$(WP="$REF_WP" tools/woopayments-merge/flow-drive.sh charge --deterministic --manual-capture)"
ref_order="$(printf '%s\n' "$ref_auth" | python3 -c 'import json,sys; print(json.loads(sys.stdin.read())["order_id"])')"
WP="$REF_WP" tools/woopayments-merge/financial-reconcile.sh "$ref_order"
WP="$REF_WP" tools/woopayments-merge/flow-drive.sh capture --deterministic --order-id="$ref_order"
WP="$REF_WP" tools/woopayments-merge/financial-reconcile.sh "$ref_order"
```

Expected: pre-capture reconciliation confirms uncaptured state; post-capture reconciliation confirms captured amount, compatible succeeded/processing intent status, fee/net when present, and no false refund/dispute/payout mismatch.

- [x] **Step 2: Drive target native authorization and capture**

Run with the target store:

```bash
TARGET_WP="docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1"
target_auth="$(WP="$TARGET_WP" tools/woopayments-merge/flow-drive.sh charge --deterministic --native --manual-capture)"
target_order="$(printf '%s\n' "$target_auth" | python3 -c 'import json,sys; print(json.loads(sys.stdin.read())["order_id"])')"
WP="$TARGET_WP" tools/woopayments-merge/financial-reconcile.sh "$target_order"
WP="$TARGET_WP" tools/woopayments-merge/flow-drive.sh capture --deterministic --order-id="$target_order"
WP="$TARGET_WP" tools/woopayments-merge/financial-reconcile.sh "$target_order"
```

Expected: same evidence shape as reference. If this fails, inspect whether the failure is a harness issue or a native product drift; fix product drift with RED/GREEN tests before rerunning.

- [x] **Step 3: Record honest coverage boundaries**

Record in `implementation-log.md` and `staging-log.md`: H24 closes auth/capture provider/service money-path coverage if both stores reconcile before and after capture. Do not mark N7a fully complete unless dispute, payout, and target multi-currency drivers also pass.

## Task 4: Review, Gates, And Commit

**Files:**
- Product files from Task 1 if changed.
- Tests from Task 1.
- Changelog under `plugins/woocommerce/changelog/`.
- Scratchpad docs local only.

- [x] **Step 1: Run focused product gates**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsProviderGatewayAdapterTest
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php --memory-limit=2G --error-format=raw --no-progress
git diff --check
```

Expected: PHPUnit/lint/diff pass. PHPStan may still fail only for unrelated unmatched ignored-baseline patterns; if it reports touched-file errors, fix them.

- [x] **Step 2: Dispatch review agents**

Dispatch at least:

- API contract reviewer for manual-capture request parity and native capture outcome shape.
- Reliability reviewer for capture/cancel failure modes, option restore in harness, and idempotent money operations.
- Performance reviewer only as a scoped structural review; no local timing claims are expected for this backend money-path slice.

- [x] **Step 3: Add changelog and commit product changes**

Add a WooCommerce changelog entry for the product fix. Commit product source/tests/changelog only. Do not commit ignored harness or scratchpad files. Do not push.

## Self-Review

- Spec coverage: covers the N7a auth/capture matrix gap and the discovered native manual-capture request drift. It does not claim dispute, payout, target multi-currency, or A4 admin capture UI parity.
- Placeholder scan: no placeholder implementation steps; every code-changing task names files and verification commands.
- Perf honesty: no runtime performance timing claim. H24 uses deterministic money-path raw-source reconciliation, not noisy performance numbers.
