# Core Native Payments A3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the native payment processing path so WooCommerce core owns the checkout/refund/capture/cancel orchestration and routes WooPayments-specific money movement behind `ProviderContract`.

**Architecture:** Keep the generic orchestration in `Automattic\WooCommerce\Internal\Payments`, keep WooPayments-specific normalization in `Automattic\WooCommerce\Internal\Payments\Providers\WooPayments`, and keep native registration behind `NativePaymentsRuntimeArbiter::should_native_register()`. A3 adds a real native gateway with the preserved `woocommerce_payments` ID, a processing service with shared order locks and deterministic idempotency keys, and a provider adapter that normalizes provider responses to `PaymentOutcome`.

**Tech Stack:** WooCommerce Core PHP, WooCommerce DI container, `WC_Payment_Gateway_CC`, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS.

---

## File Structure

- Modify: `plugins/woocommerce/src/Internal/Payments/ProviderContract.php`
    - Add `charge`, `capture`, `cancel`, and `refund` operations that return `PaymentOutcome`.
- Modify: `plugins/woocommerce/src/Internal/Payments/PaymentContext.php`
    - Add static factories for checkout, refund, capture, and cancel contexts so all operation inputs have one neutral shape.
- Create: `plugins/woocommerce/src/Internal/Payments/PaymentOperationIdempotency.php`
    - Builds deterministic operation keys from site, order, provider, operation, amount, currency, and reason.
- Create: `plugins/woocommerce/src/Internal/Payments/PaymentProcessingService.php`
    - Generic checkout/refund/capture/cancel template. Owns lock acquisition, idempotency key construction, outcome-to-order lifecycle handling, and WooCommerce result formatting.
- Create: `plugins/woocommerce/src/Internal/Payments/PaymentExceptionPolicy.php`
    - Maps provider exceptions into a generic failed `PaymentOutcome` and order note.
- Create: `plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php`
    - A core-native `WC_Payment_Gateway_CC` with ID `woocommerce_payments`; delegates `process_payment` and `process_refund` to `PaymentProcessingService`.
- Create: `plugins/woocommerce/src/Internal/Payments/NativePaymentsGatewayRegistry.php`
    - Registers the native gateway only when the arbiter says native owns the runtime.
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProvider.php`
    - Implement money-moving methods and declare capabilities for cards, redirects, refunds, partial refunds, manual capture, express checkout, hosted session, saved tokens, mandates, subscriptions, and in-person.
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php`
    - Calls the active WooPayments gateway when available and normalizes legacy gateway arrays/results to `PaymentOutcome`.
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
    - Register `NativePaymentsGatewayRegistry`.
- Create tests under:
    - `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentOperationIdempotencyTest.php`
    - `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentProcessingServiceTest.php`
    - `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentExceptionPolicyTest.php`
    - `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php`
    - `plugins/woocommerce/tests/php/src/Internal/Payments/NativePaymentsGatewayRegistryTest.php`
    - `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`
    - Update `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderTest.php`
- Create: `plugins/woocommerce/changelog/add-native-payments-a3-processing-path`

## Task 1: Contract And Idempotency Primitives

**Files:**

- Modify: `plugins/woocommerce/src/Internal/Payments/ProviderContract.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/PaymentContext.php`
- Create: `plugins/woocommerce/src/Internal/Payments/PaymentOperationIdempotency.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentOperationIdempotencyTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentContextTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderTest.php`

- [ ] **Step 1: Write the failing idempotency tests**

Add these behaviors to `PaymentOperationIdempotencyTest`:

```php
/**
 * @testdox Should derive the same key for identical charge inputs.
 */
public function test_derives_same_key_for_identical_charge_inputs(): void {
	$order = wc_create_order();
	$order->set_currency( 'USD' );
	$order->set_total( '12.34' );
	$order->save();

	$sut = new PaymentOperationIdempotency();

	$first = $sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'charge', 12.34, 'USD' );
	$second = $sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'charge', 12.34, 'USD' );

	$this->assertSame( $first, $second, 'Retries of the same operation must collapse to one provider operation.' );
	$this->assertStringStartsWith( 'wc_native_payments_', $first );
}

/**
 * @testdox Should change the key when the operation changes.
 */
public function test_changes_key_when_operation_changes(): void {
	$order = wc_create_order();
	$sut   = new PaymentOperationIdempotency();

	$this->assertNotSame(
		$sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'charge', 20.00, 'USD' ),
		$sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'refund', 20.00, 'USD' )
	);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'PaymentOperationIdempotencyTest|PaymentContextTest|WooPaymentsProviderTest'
```

Expected: fail because `PaymentOperationIdempotency` does not exist and `WooPaymentsProvider` does not implement the new provider operations yet.

- [ ] **Step 3: Implement the primitive contract**

Add these methods to `ProviderContract`:

```php
public function charge( PaymentContext $context, string $idempotency_key ): PaymentOutcome;

public function capture( PaymentContext $context, string $idempotency_key ): PaymentOutcome;

public function cancel( PaymentContext $context, string $idempotency_key ): PaymentOutcome;

public function refund( PaymentContext $context, string $idempotency_key ): PaymentOutcome;
```

Add context factories to `PaymentContext`:

```php
public static function for_checkout( WC_Order $order, string $gateway_id, string $payment_method_id = '', array $payment_data = array(), array $provider_data = array() ): self;
public static function for_refund( WC_Order $order, string $gateway_id, float $amount, string $reason = '', array $provider_data = array() ): self;
public static function for_capture( WC_Order $order, string $gateway_id, array $provider_data = array() ): self;
public static function for_cancel( WC_Order $order, string $gateway_id, array $provider_data = array() ): self;
```

Create `PaymentOperationIdempotency`:

```php
class PaymentOperationIdempotency {
	public function derive_key( WC_Order $order, string $provider_id, string $operation, ?float $amount = null, string $currency = '', string $reason = '' ): string {
		$site_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$parts   = array(
			'site'      => (string) $site_id,
			'order'     => (string) $order->get_id(),
			'provider'  => $provider_id,
			'operation' => $operation,
			'amount'    => null === $amount ? '' : wc_format_decimal( $amount, wc_get_price_decimals() ),
			'currency'  => strtoupper( '' === $currency ? (string) $order->get_currency() : $currency ),
			'reason'    => $reason,
		);

		return 'wc_native_payments_' . md5( wp_json_encode( $parts ) ?: implode( '|', $parts ) );
	}
}
```

- [ ] **Step 4: Update the provider test contract**

Replace the A1 assertion that money-moving methods do not exist with assertions that the methods now exist and return `PaymentOutcome` when the adapter is injected with a fake bridge.

- [ ] **Step 5: Run the tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'PaymentOperationIdempotencyTest|PaymentContextTest|WooPaymentsProviderTest'
```

Expected: pass.

## Task 2: Generic Processing Service

**Files:**

- Create: `plugins/woocommerce/src/Internal/Payments/PaymentProcessingService.php`
- Create: `plugins/woocommerce/src/Internal/Payments/PaymentExceptionPolicy.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentProcessingServiceTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentExceptionPolicyTest.php`

- [ ] **Step 1: Write failing processing tests**

Cover these behaviors:

```php
/**
 * @testdox Should call the provider with a deterministic key and complete the order for completed outcomes.
 */
public function test_process_checkout_completes_order_for_completed_outcome(): void {
	$order = wc_create_order();
	$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
	$order->set_total( '10.00' );
	$order->save();

	$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_test', '', 'pm_test', 'cus_test' ) );

	$result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_test' ), $provider );

	$this->assertSame( 'success', $result['result'] );
	$this->assertSame( 'pi_test', $provider->last_idempotency_key_operation_reference );
	$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
}

/**
 * @testdox Should not call the provider while an order operation is locked.
 */
public function test_process_checkout_returns_failure_when_order_operation_is_locked(): void {
	$order = wc_create_order();
	$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
	$order->set_total( '10.00' );
	$order->save();

	$key = $this->idempotency->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'charge', 10.00, $order->get_currency() );
	$this->store->lock_order_payment( $order, $key );

	$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_test' ) );

	$result = $this->sut->process_checkout( PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_test' ), $provider );

	$this->assertSame( 'fail', $result['result'] );
	$this->assertSame( 0, $provider->charge_calls );
}
```

Use helper fake providers in the test namespace, not production.

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'PaymentProcessingServiceTest|PaymentExceptionPolicyTest'
```

Expected: fail because the service classes do not exist.

- [ ] **Step 3: Implement `PaymentExceptionPolicy`**

`PaymentExceptionPolicy::to_failed_outcome( Throwable $exception ): PaymentOutcome` should return:

```php
new PaymentOutcome(
	PaymentOutcome::STATUS_FAILED,
	'',
	'',
	'',
	'',
	array(
		'error_message' => $exception->getMessage(),
		'error_code'    => method_exists( $exception, 'get_error_code' ) ? (string) $exception->get_error_code() : '',
	)
);
```

- [ ] **Step 4: Implement `PaymentProcessingService`**

Inject `OrderPaymentStore`, `OrderPaymentLifecycleService`, `PaymentOperationIdempotency`, and `PaymentExceptionPolicy` via `final public function init(...)`.

Implement:

```php
public function process_checkout( PaymentContext $context, ProviderContract $provider ): array;
public function process_refund( PaymentContext $context, ProviderContract $provider );
public function capture( PaymentContext $context, ProviderContract $provider ): PaymentOutcome;
public function cancel( PaymentContext $context, ProviderContract $provider ): PaymentOutcome;
```

Required behavior:

- `process_checkout` derives a `charge` idempotency key and uses it as the shared payment lock reference.
- Zero-total checkout returns a success result after applying a `STATUS_COMPLETED` event with no provider call.
- Completed outcomes apply `PaymentLifecycleEvent::STATUS_COMPLETED`.
- Authorized outcomes apply `PaymentLifecycleEvent::STATUS_AUTHORIZED`.
- Redirect/customer-action/pending outcomes persist `_intent_id`, `_payment_method_id`, and `_intention_status` from outcome data without completing the order and return `array( 'result' => 'success', 'redirect' => $outcome->get_redirect_url() )`.
- Failed outcomes apply `PaymentLifecycleEvent::STATUS_FAILED` and return `array( 'result' => 'fail', 'redirect' => '' )`.
- Refund derives a `refund` idempotency key and returns `true` for zero amounts.
- Capture and cancel derive `capture` / `cancel` keys and delegate to the provider under the same lock discipline.

- [ ] **Step 5: Run focused tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'PaymentProcessingServiceTest|PaymentExceptionPolicyTest'
```

Expected: pass.

## Task 3: Native WooPayments Provider Adapter

**Files:**

- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProvider.php`
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderTest.php`

- [ ] **Step 1: Write failing adapter tests**

Cover:

- `process_payment` legacy array `array( 'result' => 'success', 'redirect' => $url )` normalizes to `PaymentOutcome::STATUS_COMPLETED` with redirect URL.
- Legacy redirect hash `#wcpay-confirm-...` normalizes to `PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION`.
- Legacy `array( 'result' => 'fail' )` normalizes to `PaymentOutcome::STATUS_FAILED`.
- `process_refund` returning `true` normalizes to `PaymentOutcome::STATUS_COMPLETED`.
- `capture_charge` status `succeeded` normalizes to `PaymentOutcome::STATUS_COMPLETED`.
- `capture_charge` status `requires_capture` normalizes to `PaymentOutcome::STATUS_AUTHORIZED` or failed according to the returned `message`.
- `cancel_authorization` status `canceled` normalizes to `PaymentOutcome::STATUS_CANCELED` only as provider data; the generic service maps cancel order effects.

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsProviderGatewayAdapterTest|WooPaymentsProviderTest'
```

Expected: fail because `WooPaymentsProviderGatewayAdapter` does not exist.

- [ ] **Step 3: Implement the adapter**

The adapter should use `LegacyProxy` only in this class. It should obtain a legacy gateway with:

```php
if ( $this->legacy_proxy->call_function( 'class_exists', 'WC_Payments' ) ) {
	$gateway = $this->legacy_proxy->call_static( 'WC_Payments', 'get_gateway' );
}
```

Public methods:

```php
public function charge( PaymentContext $context, string $idempotency_key ): PaymentOutcome;
public function refund( PaymentContext $context, string $idempotency_key ): PaymentOutcome;
public function capture( PaymentContext $context, string $idempotency_key ): PaymentOutcome;
public function cancel( PaymentContext $context, string $idempotency_key ): PaymentOutcome;
```

If no legacy gateway is available, return a failed outcome with `error_code` `wcpay_gateway_unavailable`; do not call global `WC_Payments` outside the adapter.

- [ ] **Step 4: Implement the provider methods**

`WooPaymentsProvider` should inject the adapter and delegate `charge`, `refund`, `capture`, and `cancel`. Its capabilities should include:

```php
CapabilityManifest::CAPABILITY_CARDS,
CapabilityManifest::CAPABILITY_SAVED_TOKENS,
CapabilityManifest::CAPABILITY_MANDATES,
CapabilityManifest::CAPABILITY_ASYNC_REDIRECT,
CapabilityManifest::CAPABILITY_REFUNDS,
CapabilityManifest::CAPABILITY_PARTIAL_REFUNDS,
CapabilityManifest::CAPABILITY_MANUAL_CAPTURE,
CapabilityManifest::CAPABILITY_EXPRESS_CHECKOUT,
CapabilityManifest::CAPABILITY_HOSTED_SESSION,
CapabilityManifest::CAPABILITY_SUBSCRIPTIONS,
CapabilityManifest::CAPABILITY_IN_PERSON,
```

- [ ] **Step 5: Run focused tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsProviderGatewayAdapterTest|WooPaymentsProviderTest'
```

Expected: pass.

## Task 4: Native Gateway And Registration

**Files:**

- Create: `plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php`
- Create: `plugins/woocommerce/src/Internal/Payments/NativePaymentsGatewayRegistry.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/NativePaymentsGatewayRegistryTest.php`

- [ ] **Step 1: Write failing gateway tests**

Cover:

```php
/**
 * @testdox Should preserve the WooPayments gateway ID and settings option key.
 */
public function test_preserves_gateway_identity(): void {
	$gateway = wc_get_container()->get( NativeWooPaymentsGateway::class );

	$this->assertSame( OrderPaymentStore::GATEWAY_ID, $gateway->id );
	$this->assertSame( 'woocommerce_woocommerce_payments_settings', $gateway->get_option_key() );
	$this->assertContains( 'refunds', $gateway->supports );
	$this->assertContains( 'tokenization', $gateway->supports );
}

/**
 * @testdox Should process payment through the native processing service.
 */
public function test_process_payment_delegates_to_processing_service(): void {
	$order = wc_create_order();
	$order->set_total( '10.00' );
	$order->save();

	$gateway = new NativeWooPaymentsGateway();
	$gateway->init( $this->processing_service, $this->provider );

	$result = $gateway->process_payment( $order->get_id() );

	$this->assertSame( 'success', $result['result'] );
	$this->assertSame( $order->get_id(), $this->processing_service->last_context->get_order_id() );
}
```

For the registry, test that no filter is added when native is disabled or plugin-owned, and that `woocommerce_payment_gateways` receives `NativeWooPaymentsGateway::class` when native owns the runtime.

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'NativeWooPaymentsGatewayTest|NativePaymentsGatewayRegistryTest'
```

Expected: fail because the gateway and registry do not exist.

- [ ] **Step 3: Implement `NativeWooPaymentsGateway`**

The gateway extends `WC_Payment_Gateway_CC` and sets:

```php
$this->id                 = OrderPaymentStore::GATEWAY_ID;
$this->method_title       = __( 'WooPayments', 'woocommerce' );
$this->method_description = __( 'Accept payments with WooPayments.', 'woocommerce' );
$this->has_fields         = true;
$this->supports           = array( 'products', 'refunds', 'tokenization', 'add_payment_method' );
```

It delegates:

```php
public function process_payment( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return array( 'result' => 'fail', 'redirect' => '' );
	}

	return $this->processing_service->process_checkout(
		PaymentContext::for_checkout( $order, $this->id, $this->get_request_payment_method_id(), array(), $this->get_checkout_provider_data() ),
		$this->provider
	);
}

public function process_refund( $order_id, $amount = null, $reason = '' ) {
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	return $this->processing_service->process_refund(
		PaymentContext::for_refund( $order, $this->id, null === $amount ? 0.0 : (float) $amount, (string) $reason ),
		$this->provider
	);
}
```

Request reads must sanitize `$_POST['payment_method']`, `$_POST['wc-woocommerce_payments-payment-token']`, and `$_POST['wc-woocommerce_payments-new-payment-method']`. Do not implement frontend fields beyond the preserved gateway shell in A3; A4 owns full JS.

- [ ] **Step 4: Implement `NativePaymentsGatewayRegistry`**

Implement `RegisterHooksInterface`. `register()` should return immediately unless `$arbiter->should_native_register()` is true. When true, add:

```php
add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
```

`register_gateway( array $gateways ): array` should append `NativeWooPaymentsGateway::class` if not already present.

- [ ] **Step 5: Wire the registry in WooCommerce boot**

Add to the "register method for attaching hooks" section in `includes/class-woocommerce.php`:

```php
$container->get( NativePaymentsGatewayRegistry::class )->register();
```

Use an imported `use` statement only if the surrounding file already imports classes for this section; otherwise match the existing fully qualified style used by A1/A2.

- [ ] **Step 6: Run focused tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'NativeWooPaymentsGatewayTest|NativePaymentsGatewayRegistryTest'
```

Expected: pass.

## Task 5: Fake Provider Validation And Stage Verification

**Files:**

- Test helper inside `PaymentProcessingServiceTest.php` or a small test-only class under `tests/php/src/Internal/Payments/`
- Create: `plugins/woocommerce/changelog/add-native-payments-a3-processing-path`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add fake-provider genericity tests**

Add a deliberately non-Stripe fake provider that returns:

- `PaymentOutcome::STATUS_REQUIRES_REDIRECT` with an offsite redirect URL.
- `PaymentOutcome::STATUS_PENDING_ASYNC` with no card data.
- `PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT` for zero-total orders.

Assert the generic service handles all three without reading Stripe-specific keys.

- [ ] **Step 2: Add changelog**

Create `plugins/woocommerce/changelog/add-native-payments-a3-processing-path`:

```text
Significance: minor
Type: add

Add the native WooPayments processing path behind the core payments runtime arbiter.
```

- [ ] **Step 3: Run focused A3 PHPUnit**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'PaymentOperationIdempotencyTest|PaymentContextTest|PaymentProcessingServiceTest|PaymentExceptionPolicyTest|NativeWooPaymentsGatewayTest|NativePaymentsGatewayRegistryTest|WooPaymentsProviderGatewayAdapterTest|WooPaymentsProviderTest'
```

Expected: all tests pass.

- [ ] **Step 4: Run A0-A3 regression PHPUnit**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'NativePaymentsRuntimeArbiterTest|OrderPaymentStoreTest|PaymentContextTest|PaymentOutcomeTest|CapabilityManifestTest|WooPaymentsProviderTest|PaymentSurfaceDifferTest|NativePaymentsShadowModeTest|PaymentLifecycleEventTest|OrderPaymentLifecycleServiceTest|WooPaymentsEventIngestorTest|WooPaymentsWebhookRestControllerTest|WooPaymentsActionSchedulerServiceTest|WooPaymentsFailedEventStoreTest|WooPaymentsWebhookReliabilityServiceTest|WooPaymentsPaymentMethodDetailsServiceTest|PaymentOperationIdempotencyTest|PaymentProcessingServiceTest|PaymentExceptionPolicyTest|NativeWooPaymentsGatewayTest|NativePaymentsGatewayRegistryTest|WooPaymentsProviderGatewayAdapterTest'
```

Expected: all tests pass.

- [ ] **Step 5: Run static checks**

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse includes/class-woocommerce.php src/Internal/Payments/ProviderContract.php src/Internal/Payments/PaymentContext.php src/Internal/Payments/PaymentOperationIdempotency.php src/Internal/Payments/PaymentExceptionPolicy.php src/Internal/Payments/PaymentProcessingService.php src/Internal/Payments/NativeWooPaymentsGateway.php src/Internal/Payments/NativePaymentsGatewayRegistry.php src/Internal/Payments/Providers/WooPayments/WooPaymentsProvider.php src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php --memory-limit=2G
```

Run from repo root:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
git diff --check
```

Expected: no PHPStan errors, changed-line PHP lint clean, no whitespace errors.

- [ ] **Step 6: Run harness self-check**

Run:

```bash
tools/woopayments-merge/verify.sh --self-check "docker exec -i wcpay_wp_default wp --allow-root"
```

Expected: existing deterministic gates pass. Note honestly that this is still not full A3 checkout parity unless full local checkout flows are driven successfully.

- [ ] **Step 7: Review and commit**

Run focused API/BC, performance, and money-safety review over the A3 diff. Block commit on any critical/high/medium finding until dispositioned.

Commit after verification:

```bash
git add plugins/woocommerce/includes/class-woocommerce.php \
	plugins/woocommerce/src/Internal/Payments/ProviderContract.php \
	plugins/woocommerce/src/Internal/Payments/PaymentContext.php \
	plugins/woocommerce/src/Internal/Payments/PaymentOperationIdempotency.php \
	plugins/woocommerce/src/Internal/Payments/PaymentExceptionPolicy.php \
	plugins/woocommerce/src/Internal/Payments/PaymentProcessingService.php \
	plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php \
	plugins/woocommerce/src/Internal/Payments/NativePaymentsGatewayRegistry.php \
	plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProvider.php \
	plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php \
	plugins/woocommerce/tests/php/src/Internal/Payments/PaymentOperationIdempotencyTest.php \
	plugins/woocommerce/tests/php/src/Internal/Payments/PaymentContextTest.php \
	plugins/woocommerce/tests/php/src/Internal/Payments/PaymentProcessingServiceTest.php \
	plugins/woocommerce/tests/php/src/Internal/Payments/PaymentExceptionPolicyTest.php \
	plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php \
	plugins/woocommerce/tests/php/src/Internal/Payments/NativePaymentsGatewayRegistryTest.php \
	plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php \
	plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderTest.php \
	plugins/woocommerce/changelog/add-native-payments-a3-processing-path
git commit -m "feat(payments): add native payments A3 processing path"
```

## Self-Review

- Spec coverage: A3's native `process_payment`, refund, capture/cancel orchestration, provider seam, preserved gateway ID/settings/supports, shared lock, deterministic idempotency key, and non-Stripe fake-provider validation are all mapped to tasks. Full browser/JS parity for Blocks, express checkout, and WooPay is intentionally deferred to A4 because A4 owns checkout-facing assets and JS dedupe.
- Placeholder scan: no `TBD`, `TODO`, "similar to", or "appropriate handling" placeholders remain.
- Type consistency: every new production class lives under `Automattic\WooCommerce\Internal\Payments`; provider-specific code lives under `Providers\WooPayments`; tests mirror source paths.
