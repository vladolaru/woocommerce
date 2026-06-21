# Core Native Payments A2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move native payments from read-only shadow scaffolding to owning WooPayments-compatible order lifecycle effects, webhook event ingestion, Action Scheduler reliability identity, and the first namespaced replacement for a `WC_Payments::get_*()` core dependency.

**Architecture:** A2 stays behind `NativePaymentsRuntimeArbiter::should_native_register()` for every mutating registration, so the standalone plugin still wins while active. Native code writes order effects through `OrderPaymentStore` and neutral lifecycle events; the WooPayments provider adapter owns provider payload parsing, REST route compatibility, failed-event persistence, and queue scheduling. Core must not define a global `WC_Payments` shim; static access is reduced through internal namespaced services.

**Tech Stack:** WooCommerce PHP, WC CRUD/HPOS-safe order APIs, WordPress REST API, Action Scheduler, WooCommerce runtime DI container, PHPUnit 9 through `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`.

---

## File Structure

- Create `plugins/woocommerce/src/Internal/Payments/PaymentLifecycleEvent.php`
  for a neutral value object that describes one lifecycle effect.
- Create `plugins/woocommerce/src/Internal/Payments/OrderPaymentLifecycleService.php`
  for HPOS-safe order mutations, duplicate-note suppression, and shared
  WooPayments-compatible order locking.
- Create
  `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php`
  for provider payload validation, order lookup with `order_key` guard, and
  mapping supported webhook events to neutral lifecycle events.
- Create
  `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsWebhookRestController.php`
  for the native `POST /wc/v3/payments/webhook` compatibility route.
- Create
  `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsActionSchedulerService.php`
  for canonical group/hook scheduling with pending-action dedupe.
- Create
  `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsFailedEventStore.php`
  for `wcpay_failed_event_<md5>` transient persistence.
- Create
  `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsFailedEventsProvider.php`
  for the native fetch seam. The default implementation is filter-backed and
  returns an empty page, because live server API migration happens in a later
  stage.
- Create
  `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsWebhookReliabilityService.php`
  for preserved `wcpay_webhook_fetch_events` and
  `wcpay_webhook_process_event` consumers.
- Create
  `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentMethodDetailsService.php`
  for the first namespaced internal replacement of
  `WC_Payments::get_payments_api_client()`.
- Modify `plugins/woocommerce/src/Internal/Orders/PaymentInfo.php` to use the
  payment-method details service, preserving the existing cache meta and output
  shape.
- Modify `plugins/woocommerce/includes/class-woocommerce.php` to register the
  native webhook REST controller and reliability service.
- Create `tools/woopayments-merge/queue-handoff-manifest.json` for the A2
  scheduler/storage handoff manifest.
- Add PHPUnit tests under
  `plugins/woocommerce/tests/php/src/Internal/Payments/`.
- Extend `plugins/woocommerce/tests/php/src/Internal/Orders/PaymentInfoTest.php`.
- Add one WooCommerce changelog entry for the A2 stage.

## Task 1: Neutral Lifecycle Event And Order Effects

**Files:**

- Create: `plugins/woocommerce/src/Internal/Payments/PaymentLifecycleEvent.php`
- Create: `plugins/woocommerce/src/Internal/Payments/OrderPaymentLifecycleService.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentLifecycleEventTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/OrderPaymentLifecycleServiceTest.php`

- [ ] **Step 1: Write failing lifecycle value-object tests**

```php
public function test_completed_event_exposes_status_reference_meta_and_note(): void {
	$event = new PaymentLifecycleEvent(
		PaymentLifecycleEvent::STATUS_COMPLETED,
		'pi_123',
		array( '_intent_id' => 'pi_123', '_charge_id' => 'ch_123' ),
		array(),
		'Payment complete.'
	);

	$this->assertSame( PaymentLifecycleEvent::STATUS_COMPLETED, $event->get_status() );
	$this->assertSame( 'pi_123', $event->get_payment_reference() );
	$this->assertSame( 'pi_123', $event->get_meta_to_update()['_intent_id'] );
	$this->assertSame( 'Payment complete.', $event->get_note() );
}

public function test_unknown_status_is_rejected(): void {
	$this->expectException( InvalidArgumentException::class );
	new PaymentLifecycleEvent( 'unknown-status' );
}
```

- [ ] **Step 2: Write failing order lifecycle tests**

Cover these exact assertions in `OrderPaymentLifecycleServiceTest`:

```php
public function test_completed_event_marks_order_paid_and_preserves_meta(): void
public function test_authorized_event_moves_order_on_hold(): void
public function test_failed_event_marks_order_failed_and_unlocks_order(): void
public function test_canceled_event_marks_order_cancelled_and_deletes_fee_meta(): void
public function test_capture_expired_event_marks_order_failed(): void
public function test_started_event_adds_note_without_status_change(): void
public function test_duplicate_note_is_not_added_twice(): void
public function test_locked_order_is_not_mutated_for_same_reference(): void
```

Use `wc_create_order()`, set `woocommerce_payments` as the payment method, and
reload with `wc_get_order( $order->get_id() )` before assertions.

- [ ] **Step 3: Verify the new tests fail for missing classes**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'PaymentLifecycleEventTest|OrderPaymentLifecycleServiceTest'
```

Expected: failure because the new classes do not exist yet.

- [ ] **Step 4: Implement the value object**

Implement these public constants and methods:

```php
const STATUS_COMPLETED       = 'completed';
const STATUS_AUTHORIZED      = 'authorized';
const STATUS_FAILED          = 'failed';
const STATUS_CANCELED        = 'canceled';
const STATUS_CAPTURE_EXPIRED = 'capture_expired';
const STATUS_STARTED         = 'started';

public function __construct(
	string $status,
	?string $payment_reference = null,
	array $meta_to_update = array(),
	array $meta_to_delete = array(),
	?string $note = null
)
public function get_status(): string
public function get_payment_reference(): ?string
public function get_meta_to_update(): array
public function get_meta_to_delete(): array
public function get_note(): ?string
```

Validate `$status` against the constants and normalize all meta keys and values
to strings.

- [ ] **Step 5: Implement the order lifecycle service**

Use these method boundaries:

```php
final public function init( OrderPaymentStore $order_payment_store ): void
public function apply( WC_Order $order, PaymentLifecycleEvent $event ): void
private function apply_status_transition( WC_Order $order, PaymentLifecycleEvent $event ): void
private function add_note_once( WC_Order $order, string $note ): void
```

The status mapping is:

- `completed`: call `$order->payment_complete( $reference )`.
- `authorized`: call `$order->update_status( 'on-hold', $note )`.
- `failed`: call `$order->update_status( 'failed', $note )`.
- `canceled`: call `$order->update_status( 'cancelled', $note )`.
- `capture_expired`: call `$order->update_status( 'failed', $note )`.
- `started`: do not change the order status.

Before status transition, update `meta_to_update` and delete
`meta_to_delete`. Lock by payment reference when present. If the same reference
is already locked, return without mutation. Always unlock in `finally` after a
mutation attempt.

- [ ] **Step 6: Verify lifecycle tests pass**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'PaymentLifecycleEventTest|OrderPaymentLifecycleServiceTest'
```

Expected: all lifecycle tests pass.

## Task 2: WooPayments Event Ingestor

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php`
- Test:
  `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestorTest.php`

- [ ] **Step 1: Write failing ingestor tests**

Cover these exact assertions:

```php
public function test_payment_intent_succeeded_completes_order(): void
public function test_payment_intent_failed_marks_order_failed(): void
public function test_payment_intent_canceled_marks_order_cancelled_and_deletes_fee_meta(): void
public function test_charge_expired_marks_order_failed(): void
public function test_unknown_event_type_is_a_successful_noop(): void
public function test_mismatched_order_key_does_not_mutate_order(): void
public function test_malformed_event_throws_invalid_argument_exception(): void
```

Event fixture shape:

```php
array(
	'id'   => 'evt_123',
	'type' => 'payment_intent.succeeded',
	'data' => array(
		'object' => array(
			'id'       => 'pi_123',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'metadata' => array(
				'order_id'  => (string) $order->get_id(),
				'order_key' => $order->get_order_key(),
			),
			'charges'  => array(
				'data' => array(
					array( 'id' => 'ch_123' ),
				),
			),
		),
	),
)
```

- [ ] **Step 2: Verify ingestor tests fail**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsEventIngestorTest
```

Expected: failure because `WooPaymentsEventIngestor` does not exist yet.

- [ ] **Step 3: Implement supported event parsing**

Implement:

```php
final public function init( OrderPaymentLifecycleService $lifecycle_service ): void
public function process( array $event ): void
private function get_event_object( array $event ): array
private function get_order_from_event_object( array $object ): ?WC_Order
private function build_lifecycle_event( string $event_type, array $object ): ?PaymentLifecycleEvent
```

Supported mappings:

- `payment_intent.succeeded`: completed with `_intent_id`, `_charge_id`,
  `_payment_method_id`, `_intention_status`, `_wcpay_intent_currency`.
- `payment_intent.payment_failed`: failed with `_intent_id` and
  `_intention_status`.
- `payment_intent.canceled`: canceled with `_intent_id`, `_intention_status`,
  and deleted `_wcpay_transaction_fee`, `_wcpay_net`.
- `charge.expired`: capture expired with `_charge_id`.

Unknown event types return without mutation. Missing event type/object throws
`InvalidArgumentException`. A mismatched `metadata.order_key` returns without
mutation.

- [ ] **Step 4: Verify ingestor tests pass**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsEventIngestorTest
```

Expected: all ingestor tests pass.

## Task 3: Native Webhook REST Route

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsWebhookRestController.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
- Test:
  `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsWebhookRestControllerTest.php`

- [ ] **Step 1: Write failing route tests**

Cover these exact assertions:

```php
public function test_registers_no_route_when_plugin_owns_runtime(): void
public function test_registers_wc_v3_payments_webhook_when_native_owns_runtime(): void
public function test_success_response_matches_woopayments_envelope(): void
public function test_bad_payload_returns_bad_request_envelope(): void
public function test_processing_exception_returns_error_envelope(): void
```

Use legacy proxy mocks for active-plugin detection and add
`woocommerce_native_payments_enabled` only for the native-owned cases.

- [ ] **Step 2: Verify route tests fail**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsWebhookRestControllerTest
```

Expected: failure because the controller does not exist yet.

- [ ] **Step 3: Implement controller and registration**

Implement `RegisterHooksInterface` with:

```php
final public function init(
	NativePaymentsRuntimeArbiter $arbiter,
	WooPaymentsEventIngestor $event_ingestor
): void
public function register()
public function register_routes(): void
public function handle_webhook( WP_REST_Request $request ): WP_REST_Response
```

The route is:

```php
register_rest_route(
	'wc/v3',
	'/payments/webhook',
	array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => array( $this, 'handle_webhook' ),
		'permission_callback' => function () {
			return current_user_can( 'manage_woocommerce' );
		},
	)
);
```

Responses:

- Success: status `200`, body `array( 'result' => 'success' )`.
- `InvalidArgumentException`: status `400`, body `array( 'result' => 'bad_request' )`.
- Other `Throwable`: status `500`, body `array( 'result' => 'error' )`.

Register in `class-woocommerce.php` next to the existing native shadow
registration:

```php
$container->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWebhookRestController::class )->register();
```

- [ ] **Step 4: Verify route tests pass**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsWebhookRestControllerTest
```

Expected: all route tests pass.

## Task 4: Queue Reliability And Handoff Manifest

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsActionSchedulerService.php`
- Create:
  `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsFailedEventStore.php`
- Create:
  `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsFailedEventsProvider.php`
- Create:
  `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsWebhookReliabilityService.php`
- Create: `tools/woopayments-merge/queue-handoff-manifest.json`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
- Test:
  `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsActionSchedulerServiceTest.php`
- Test:
  `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsFailedEventStoreTest.php`
- Test:
  `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsWebhookReliabilityServiceTest.php`

- [ ] **Step 1: Write failing scheduler and transient tests**

Assert:

```php
WooPaymentsActionSchedulerService::GROUP_ID === 'woocommerce_payments'
WooPaymentsActionSchedulerService::schedule_job() avoids duplicate pending actions
WooPaymentsFailedEventStore::get_transient_name( 'evt_123' ) === 'wcpay_failed_event_' . md5( 'evt_123' )
WooPaymentsFailedEventStore persists, reads, and deletes event arrays
```

- [ ] **Step 2: Write failing reliability tests**

Assert:

```php
public function test_registers_no_actions_when_plugin_owns_runtime(): void
public function test_registers_preserved_actions_when_native_owns_runtime(): void
public function test_account_refresh_flag_schedules_fetch_job(): void
public function test_fetch_events_stores_events_and_schedules_processing_jobs(): void
public function test_fetch_events_schedules_follow_up_when_provider_has_more(): void
public function test_process_event_consumes_transient_and_invokes_ingestor(): void
public function test_process_event_skips_missing_payload(): void
```

- [ ] **Step 3: Verify queue tests fail**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsActionSchedulerServiceTest|WooPaymentsFailedEventStoreTest|WooPaymentsWebhookReliabilityServiceTest'
```

Expected: failure because the queue classes do not exist yet.

- [ ] **Step 4: Implement queue identity services**

Implement constants:

```php
const GROUP_ID                     = 'woocommerce_payments';
const WEBHOOK_FETCH_EVENTS_ACTION  = 'wcpay_webhook_fetch_events';
const WEBHOOK_PROCESS_EVENT_ACTION = 'wcpay_webhook_process_event';
const CONTINUOUS_FETCH_FLAG_ACCOUNT_DATA = 'has_more_failed_events';
```

Use `as_has_scheduled_action( $hook, $args, self::GROUP_ID )` before
`as_schedule_single_action( time(), $hook, $args, self::GROUP_ID )`.

The failed-event provider returns:

```php
array(
	'data'     => array(),
	'has_more' => false,
)
```

filtered by `woocommerce_native_payments_woopayments_failed_webhook_events`.

The reliability service registers only when native owns runtime:

```php
add_action( 'woocommerce_payments_account_refreshed', array( $this, 'maybe_schedule_fetch_events' ), 10, 1 );
add_action( self::WEBHOOK_FETCH_EVENTS_ACTION, array( $this, 'fetch_events_and_schedule_processing_jobs' ) );
add_action( self::WEBHOOK_PROCESS_EVENT_ACTION, array( $this, 'process_event' ), 10, 1 );
```

- [ ] **Step 5: Create queue handoff manifest**

Write `tools/woopayments-merge/queue-handoff-manifest.json` with:

```json
{
  "action_scheduler_group": "woocommerce_payments",
  "webhook_reliability": {
    "fetch_hook": "wcpay_webhook_fetch_events",
    "process_hook": "wcpay_webhook_process_event",
    "failed_event_transient_pattern": "wcpay_failed_event_<md5(event_id)>"
  },
  "known_legacy_hyphen_group": "woocommerce-payments",
  "stage": "A2"
}
```

- [ ] **Step 6: Register reliability service in WooCommerce boot**

Register in `class-woocommerce.php`:

```php
$container->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWebhookReliabilityService::class )->register();
```

- [ ] **Step 7: Verify queue tests pass**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsActionSchedulerServiceTest|WooPaymentsFailedEventStoreTest|WooPaymentsWebhookReliabilityServiceTest'
```

Expected: all queue tests pass.

## Task 5: Replace One Core `WC_Payments::get_*()` Static Seam

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentMethodDetailsService.php`
- Modify: `plugins/woocommerce/src/Internal/Orders/PaymentInfo.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Orders/PaymentInfoTest.php`
- Test:
  `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentMethodDetailsServiceTest.php`

- [ ] **Step 1: Write failing service tests**

Assert:

```php
public function test_returns_empty_when_payment_method_id_is_empty(): void
public function test_returns_empty_when_plugin_runtime_is_absent(): void
public function test_proxies_plugin_api_client_when_plugin_runtime_is_active(): void
public function test_logs_and_returns_empty_when_client_throws(): void
```

Use `LegacyProxy` function mocks for `class_exists` and `wc_get_logger`.

- [ ] **Step 2: Extend `PaymentInfoTest`**

Add a test that creates a WooPayments order with `_payment_method_id` but no
`_wcpay_raw_payment_method_details`, then uses the internal service seam to
return card details. Assert the result includes `brand`, `last4`, and that
`_wcpay_raw_payment_method_details` was cached.

- [ ] **Step 3: Verify service tests fail**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsPaymentMethodDetailsServiceTest|PaymentInfoTest'
```

Expected: failure until the service exists and `PaymentInfo` uses it.

- [ ] **Step 4: Implement namespaced service**

Implement:

```php
final public function init( LegacyProxy $legacy_proxy ): void
public function get_payment_method_details( string $payment_method_id ): array
```

Behavior:

- Empty payment method ID returns `array()`.
- If `class_exists( \WC_Payments::class )` is false, return `array()`.
- Otherwise call
  `\WC_Payments::get_payments_api_client()->get_payment_method( $payment_method_id )`.
- Catch `Throwable`, log with source `payment-info`, and return `array()`.

- [ ] **Step 5: Modify `PaymentInfo` to use the service**

Replace the direct static call with:

```php
$payment_details = wc_get_container()
	->get( WooPaymentsPaymentMethodDetailsService::class )
	->get_payment_method_details( (string) $payment_method_id );
```

Keep the existing `_wcpay_raw_payment_method_details` cache format and the
existing card/card-present output mapping.

- [ ] **Step 6: Verify facade tests pass**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsPaymentMethodDetailsServiceTest|PaymentInfoTest'
```

Expected: all facade and payment-info tests pass.

## Task 6: Stage Verification, Review, And Commit

**Files:**

- Add changelog:
  `plugins/woocommerce/changelog/add-native-payments-a2-lifecycle-webhooks`
- Update:
  `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update:
  `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Run focused A2 PHPUnit suite**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'PaymentLifecycleEventTest|OrderPaymentLifecycleServiceTest|WooPaymentsEventIngestorTest|WooPaymentsWebhookRestControllerTest|WooPaymentsActionSchedulerServiceTest|WooPaymentsFailedEventStoreTest|WooPaymentsWebhookReliabilityServiceTest|WooPaymentsPaymentMethodDetailsServiceTest|PaymentInfoTest'
```

Expected: all tests pass.

- [ ] **Step 2: Run PHPStan on new source files**

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse src/Internal/Payments/PaymentLifecycleEvent.php src/Internal/Payments/OrderPaymentLifecycleService.php src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php src/Internal/Payments/Providers/WooPayments/WooPaymentsWebhookRestController.php src/Internal/Payments/Providers/WooPayments/WooPaymentsActionSchedulerService.php src/Internal/Payments/Providers/WooPayments/WooPaymentsFailedEventStore.php src/Internal/Payments/Providers/WooPayments/WooPaymentsFailedEventsProvider.php src/Internal/Payments/Providers/WooPayments/WooPaymentsWebhookReliabilityService.php src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentMethodDetailsService.php --memory-limit=2G
```

Expected: no errors.

- [ ] **Step 3: Run PHP linting**

Run:

```bash
pnpm lint:php -- plugins/woocommerce/src/Internal/Payments/PaymentLifecycleEvent.php plugins/woocommerce/src/Internal/Payments/OrderPaymentLifecycleService.php plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsWebhookRestController.php plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsActionSchedulerService.php plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsFailedEventStore.php plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsFailedEventsProvider.php plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsWebhookReliabilityService.php plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentMethodDetailsService.php plugins/woocommerce/src/Internal/Orders/PaymentInfo.php plugins/woocommerce/tests/php/src/Internal/Payments/PaymentLifecycleEventTest.php plugins/woocommerce/tests/php/src/Internal/Payments/OrderPaymentLifecycleServiceTest.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestorTest.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsWebhookRestControllerTest.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsActionSchedulerServiceTest.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsFailedEventStoreTest.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsWebhookReliabilityServiceTest.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentMethodDetailsServiceTest.php plugins/woocommerce/tests/php/src/Internal/Orders/PaymentInfoTest.php
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
git diff --check
```

Expected: all commands pass.

- [ ] **Step 4: Run harness self-check**

Run:

```bash
tools/woopayments-merge/verify.sh --self-check "docker exec -i wcpay_wp_default wp --allow-root"
```

Expected: `PASS` for all self-check gates. Record the exact output in the
implementation log and staging log with the caveat that A2 native registration
is still dormant unless the native flag owns runtime.

- [ ] **Step 5: Run adversarial review subagents**

Dispatch independent review over the local diff:

- API/BC review for REST route, Action Scheduler group/hook names, transient
  shape, and static facade compatibility.
- WordPress/performance review for order CRUD usage, duplicate queries, AS
  scheduling, and logging.
- Architecture review for provider payload boundaries and global facade risks.

Disposition every finding in the implementation log before committing.

- [ ] **Step 6: Commit A2**

Stage only A2 product files, tests, manifest, changelog, and boot wiring. Do
not stage `.agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/` or `.agents/scratchpad/`.

Commit:

```bash
git commit -m "feat(payments): add native payments A2 lifecycle ingestion"
```

Use a body with `[Context]`, `[Problem]`, and `[Solution]`. After commit, record
the git range from the pre-A1 base through the new A2 commit in the staging log.

## Self-Review

- Spec coverage: A2 covers native lifecycle effects, event ingestion,
  preserved webhook route identity, preserved scheduler group/hook/transient
  identity, queue handoff manifest, multisite `order_key` guard, and one
  `WC_Payments::get_*()` core dependency reduction.
- Deferred by design: payment submission, checkout payment processing, refunds,
  captures, subscriptions, fees, disputes beyond capture-expired status, live
  WPCOM failed-event fetch API wiring, and global plugin auto-deactivation stay
  in later stages.
- Placeholder scan: no `TBD`, `TODO`, or unspecified test commands remain.
- Type consistency: all new classes live under
  `Automattic\WooCommerce\Internal\Payments` or
  `Automattic\WooCommerce\Internal\Payments\Providers\WooPayments`; all native
  registrations remain guarded by the runtime arbiter.
