<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\PaymentIntentEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentConfirmationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTerminalPaymentsRestController;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\RecordedPlatformEvents;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use WC_Order;
use WC_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for WooPaymentsCompositionDataSettler and the places that read an order again before composing its payment effects.
 *
 * Each place reads the order under its claim after its last platform call, then composes and writes the payment's
 * effects. Composition reads the account, which is cached and can be refreshed from the platform. The rows check, on
 * the platform client double, that no platform call starts between the read and the write.
 */
class WooPaymentsCompositionDataSettlerTest extends WC_Unit_Test_Case {

	/**
	 * Platform calls, order reads in place and order notes, in the order they happened.
	 *
	 * @var string[]
	 */
	private array $log = array();

	/**
	 * Platform client double.
	 *
	 * @var Client
	 */
	private Client $platform;

	/**
	 * The account cache the container's account service reads through.
	 *
	 * @var WooPaymentsDatabaseCache
	 */
	private WooPaymentsDatabaseCache $account_cache;

	/**
	 * The cache's refresh state before the test.
	 *
	 * @var bool
	 */
	private bool $refresh_disabled_before = false;

	/**
	 * Called at each order read in place, before it is logged.
	 *
	 * @var callable|null
	 */
	private $on_read = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		wc_get_container()->reset_all_resolved();

		$this->account_cache = wc_get_container()->get( WooPaymentsDatabaseCache::class );
		$service_cache       = new ReflectionProperty( WooPaymentsAccountService::class, 'database_cache' );
		$service_cache->setAccessible( true );
		$this->assertSame( $this->account_cache, $service_cache->getValue( wc_get_container()->get( WooPaymentsAccountService::class ) ), 'The account service reads through the container cache.' );
		$this->refresh_disabled_before = (bool) $this->get_cache_property( 'refresh_disabled' );
		$this->set_cache_property( 'refresh_disabled', false );
		$this->set_cache_property( 'contents', array() );

		set_current_screen( 'front' );
		update_option( 'woocommerce_currency', 'USD' );
		delete_option( WooPaymentsDatabaseCache::ACCOUNT_KEY );
		// The recorded account is a test-drive account, served only while test-mode onboarding is on.
		add_filter( 'wcpay_test_mode_onboarding', '__return_true' );
		add_action(
			'woocommerce_order_note_added',
			function (): void {
				$this->log[] = 'note';
			}
		);

		$this->platform = $this->create_platform_double();
		wc_get_container()->replace( Client::class, $this->platform );
		wc_get_container()->replace( OrderPaymentLifecycleService::class, $this->create_read_logging_lifecycle() );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		try {
			$this->set_cache_property( 'contents', array() );
			$this->set_cache_property( 'refresh_disabled', $this->refresh_disabled_before );
			$this->reset_legacy_proxy_mocks();
			wc_get_container()->reset_all_replacements();
			wc_get_container()->reset_all_resolved();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox No platform call starts between the authoritative read and the write ($_dataName).
	 * @dataProvider provide_sites
	 *
	 * @param string $site   Place that reads the order again.
	 * @param int    $window Which read-to-write window of the run belongs to the site, or -1 for the last read to the end of the run.
	 */
	public function test_no_platform_call_starts_between_the_authoritative_read_and_the_write( string $site, int $window ): void {
		// Every account read that may refresh does, so an account read in the window would reach the platform.
		add_filter(
			'wcpay_database_cache_ttl',
			static fn( $ttl, $key ) => WooPaymentsDatabaseCache::ACCOUNT_KEY === $key ? -1 : $ttl,
			10,
			2
		);

		$this->run_site( $site );

		$calls = -1 === $window ? $this->calls_from_the_last_read_to_the_end() : ( $this->read_to_write_windows()[ $window ] ?? null );
		$this->assertIsArray( $calls, 'The run reads the order again before it writes: ' . implode( ', ', $this->log ) );
		$this->assertSame( array(), $calls, 'No platform call between the read and the write: ' . implode( ', ', $this->log ) );
		$this->assertContains( 'get_account', $this->log, 'The account was fetched in the run, before the read.' );
	}

	/**
	 * Provide the sites: name, window.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public function provide_sites(): array {
		return array(
			'checkout charge'                 => array( 'checkout charge', -1 ),
			'completed capture'               => array( 'completed capture', -1 ),
			'redirect return'                 => array( 'redirect return', 0 ),
			'attached-intent recovery'        => array( 'attached-intent recovery', 0 ),
			'terminal authorization'          => array( 'terminal capture', 0 ),
			'terminal completion'             => array( 'terminal capture', 1 ),
			'authorization webhook'           => array( 'authorization webhook', 0 ),
			'succeeded webhook'               => array( 'succeeded webhook', 0 ),
			'Stripe Billing succeeded intent' => array( 'succeeded intent under a caller lock', 0 ),
		);
	}

	/**
	 * @testdox A completed capture whose account entry passes its TTL between the read and the composition fetches no account, and writes the rate the settled account gives.
	 *
	 * The settled account's default currency is EUR (the recorded account with only that value changed) on an EUR store,
	 * and the order is in USD, so the rate is written only from the settled account: the empty-account default, USD, matches
	 * the order and writes none (WooPaymentsOrderDataService::get_settlement_exchange_rate_order_meta()).
	 */
	public function test_an_account_entry_expiring_after_the_read_is_not_fetched(): void {
		$this->use_eur_account_and_store();
		$now = time();
		$this->register_legacy_proxy_function_mocks(
			array(
				'time' => static function () use ( &$now ): int {
					return $now;
				},
			)
		);
		update_option(
			WooPaymentsDatabaseCache::ACCOUNT_KEY,
			array(
				'data'               => $this->platform->account,
				'fetched'            => $now,
				'errored'            => false,
				'consecutive_errors' => 0,
			),
			false
		);
		$this->on_read = static function () use ( &$now ): void {
			$now += DAY_IN_SECONDS + 60;
		};

		$order = $this->run_completed_capture( 'USD' );

		$this->assertNotContains( 'get_account', $this->log, 'The account entry was fresh until the read, and is not refreshed after it.' );
		$this->assertSame( '1.13905', wc_get_order( $order->get_id() )->get_meta( '_wcpay_multi_currency_stripe_exchange_rate', true ) );
	}

	/**
	 * @testdox A completed capture on a store whose account entry was deleted fetches the account once, before the read, and writes the rate the fetched account gives.
	 *
	 * An account update deletes the entry (WooPaymentsAccountService::clear_cache()). The charge is REC-3's recorded charge
	 * (`Fixtures/platform-eur-charge.json`), whose settlement rate is 1.13905; the account and store are as in the row above.
	 */
	public function test_a_deleted_account_entry_is_fetched_before_the_read(): void {
		$this->use_eur_account_and_store();

		$order = $this->run_completed_capture( 'USD' );

		$this->assertSame( array( 'get_account', 'read' ), array_values( array_intersect( $this->log, array( 'get_account', 'read' ) ) ) );
		$this->assertSame( '1.13905', wc_get_order( $order->get_id() )->get_meta( '_wcpay_multi_currency_stripe_exchange_rate', true ) );
	}

	/**
	 * Serve a settled account whose default currency is EUR, on a store whose currency is EUR.
	 */
	private function use_eur_account_and_store(): void {
		$this->platform->account['store_currencies']['default'] = 'eur';
		update_option( 'woocommerce_currency', 'EUR' );
	}

	/**
	 * Run one site.
	 *
	 * @param string $site Site name.
	 */
	private function run_site( string $site ): void {
		switch ( $site ) {
			case 'checkout charge':
				$intent = $this->load_recorded_eur_charge();
				$order  = $this->create_order( 'EUR', '12.34' );
				$this->init_from_container( new PaymentProcessingService() )->process_checkout_outcome(
					PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, (string) $intent['payment_method'] ),
					$this->create_provider_returning( ( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, (string) $intent['id'] ) )->with_effect_plan( WooPaymentsOrderEffectPlan::for_payment_intent( $intent, false ) ) )
				);
				return;
			case 'completed capture':
				$this->run_completed_capture();
				return;
			case 'redirect return':
				$order  = $this->create_order( 'EUR', '12.34' );
				$intent = $this->load_recorded_eur_charge( $order );
				$this->create_intent_confirmation_service()->confirm_fetched_intent_for_order( $order, $intent, false, true );
				return;
			case 'attached-intent recovery':
				$order  = $this->create_order( 'EUR', '12.34' );
				$intent = $this->load_recorded_eur_charge( $order );
				$order->update_meta_data( '_intent_id', (string) $intent['id'] );
				$order->save();
				$this->create_intent_confirmation_service()->apply_attached_payment_intent( $order, $intent );
				return;
			case 'terminal capture':
				$this->run_terminal_capture();
				return;
			case 'authorization webhook':
				$this->run_webhook( 'payment_intent.amount_capturable_updated', 'manual_capture_amount_capturable_updated' );
				return;
			case 'succeeded webhook':
				$this->run_webhook( 'payment_intent.succeeded', 'card_checkout_succeeded' );
				return;
			case 'succeeded intent under a caller lock':
				// Stripe Billing's invoice events apply the intent under their own claim, with no webhook scope around it.
				$intent = RecordedPlatformEvents::body( 'platform-payment-intent-events.json', 'card_checkout_succeeded' )['data']['object'];
				$order  = $this->create_order( 'USD', (string) ( (int) $intent['amount'] / 100 ) );
				$order->update_meta_data( '_intent_id', (string) $intent['id'] );
				$order->save();
				$this->create_payment_intent_event_handler()->apply_succeeded_payment_intent_under_lock( $order, $intent );
				return;
		}

		$this->fail( "Unknown site: $site" );
	}

	/**
	 * Capture REC-3's EUR charge through the payments runtime, from the provider's capture to the lifecycle's write.
	 *
	 * @param string $currency Order currency.
	 * @return WC_Order The captured order.
	 */
	private function run_completed_capture( string $currency = 'EUR' ): WC_Order {
		$intent = $this->load_recorded_eur_charge();
		$order  = $this->create_order( $currency, '12.34' );
		$order->update_meta_data( '_intent_id', (string) $intent['id'] );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();

		$this->init_from_container( new PaymentProcessingService() )->capture(
			PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ),
			$this->create_provider_returning( ( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, (string) $intent['id'] ) )->with_effect_plan( WooPaymentsOrderEffectPlan::for_capture( $intent ) ) )
		);

		return $order;
	}

	/**
	 * Create a WooPayments provider whose charge and capture return the given outcome, as the platform's answer.
	 *
	 * @param PaymentOutcome $outcome Outcome the platform call returns.
	 * @return WooPaymentsProvider
	 */
	private function create_provider_returning( PaymentOutcome $outcome ): WooPaymentsProvider {
		$provider = new class( $this->log, $outcome ) extends WooPaymentsProvider {
			/**
			 * Shared log.
			 *
			 * @var string[]
			 */
			private array $log;

			/**
			 * Outcome the platform call returns.
			 *
			 * @var PaymentOutcome
			 */
			private PaymentOutcome $outcome;

			/**
			 * Constructor.
			 *
			 * @param string[]       $log     Shared log.
			 * @param PaymentOutcome $outcome Outcome the platform call returns.
			 */
			public function __construct( array &$log, PaymentOutcome $outcome ) {
				$this->log     = &$log;
				$this->outcome = $outcome;
			}

			/**
			 * Return the outcome, as the platform's charge.
			 *
			 * @param PaymentOperationContext $context         Payment context.
			 * @param string                  $idempotency_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function charge( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context, $idempotency_key );
				$this->log[] = 'create_and_confirm_intention';

				return $this->outcome;
			}

			/**
			 * Return the outcome, as the platform's capture.
			 *
			 * @param PaymentOperationContext $context         Payment context.
			 * @param string                  $idempotency_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context, $idempotency_key );
				$this->log[] = 'capture_intention';

				return $this->outcome;
			}
		};

		return $this->init_from_container( $provider );
	}

	/**
	 * Capture an in-person authorization through the terminal route: the recorded manual-capture authorization, then
	 * its recorded capture (`Fixtures/platform-manual-capture.json`).
	 */
	private function run_terminal_capture(): void {
		$order      = $this->create_order( 'USD', '10.99' );
		$authorized = $this->load_fixture_body( 'platform-manual-capture.json', 'authorize_manual_capture' );
		$captured   = $this->load_fixture_body( 'platform-manual-capture.json', 'capture_full_amount' );

		$authorized['metadata']['order_id'] = (string) $order->get_id();
		$captured['metadata']['order_id']   = (string) $order->get_id();
		$this->platform->intents            = array( (string) $authorized['id'] => $authorized );
		$this->platform->captured           = $captured;

		$controller = $this->init_from_container( new WooPaymentsTerminalPaymentsRestController() );
		$request    = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', (string) $authorized['id'] );

		$response = $controller->capture_terminal_payment( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response, 'The capture succeeded.' );
	}

	/**
	 * Deliver a recorded payment intent event (`Fixtures/platform-payment-intent-events.json`) for a pending order that waits on its intent.
	 *
	 * @param string $event_type Event type.
	 * @param string $pair       Recorded entry.
	 */
	private function run_webhook( string $event_type, string $pair ): void {
		$intent = RecordedPlatformEvents::body( 'platform-payment-intent-events.json', $pair )['data']['object'];
		$order  = $this->create_order( strtoupper( (string) $intent['currency'] ), (string) ( (int) $intent['amount'] / 100 ) );
		$order->update_meta_data( '_intent_id', (string) $intent['id'] );
		$order->save();
		$intent['metadata']['order_id']  = (string) $order->get_id();
		$intent['metadata']['order_key'] = $order->get_order_key();

		$this->create_payment_intent_event_handler()->process( $event_type, $intent );
	}

	/**
	 * Create the payment intent event handler over the read-logging lifecycle.
	 *
	 * @return PaymentIntentEventHandler
	 */
	private function create_payment_intent_event_handler(): PaymentIntentEventHandler {
		return $this->init_from_container( new PaymentIntentEventHandler(), array( WooPaymentsFeeDetailsNoteController::class => $this->create_fee_details_note_controller() ) );
	}

	/**
	 * Split the log into the platform calls made between each read in place and the next order note, or the end of the run.
	 *
	 * A later read starts a new window: the last read before a write is the one the write is decided on.
	 *
	 * @return array<int,string[]>
	 */
	private function read_to_write_windows(): array {
		$windows = array();
		$current = null;
		foreach ( $this->log as $entry ) {
			if ( 'read' === $entry ) {
				$current = array();
			} elseif ( 'note' === $entry ) {
				if ( null !== $current ) {
					$windows[] = $current;
				}
				$current = null;
			} elseif ( null !== $current ) {
				$current[] = $entry;
			}
		}
		if ( null !== $current ) {
			$windows[] = $current;
		}

		return $windows;
	}

	/**
	 * Get the platform calls made from the last read in place to the end of the run, when a note was written after that read.
	 *
	 * @return string[]|null Null when the run did not read the order or wrote no note after its last read.
	 */
	private function calls_from_the_last_read_to_the_end(): ?array {
		$last_read = array_search( 'read', array_reverse( $this->log, true ), true );
		if ( false === $last_read ) {
			return null;
		}
		$after = array_slice( $this->log, (int) $last_read + 1 );
		if ( ! in_array( 'note', $after, true ) ) {
			return null;
		}

		return array_values( array_diff( $after, array( 'note' ) ) );
	}

	/**
	 * Create the intent confirmation service over a fee details controller whose lifecycle logs its reads.
	 *
	 * @return WooPaymentsIntentConfirmationService
	 */
	private function create_intent_confirmation_service(): WooPaymentsIntentConfirmationService {
		return $this->init_from_container( new WooPaymentsIntentConfirmationService(), array( WooPaymentsFeeDetailsNoteController::class => $this->create_fee_details_note_controller() ) );
	}

	/**
	 * Create a fee details note controller over the container's collaborators, the read-logging lifecycle included.
	 *
	 * @return WooPaymentsFeeDetailsNoteController
	 */
	private function create_fee_details_note_controller(): WooPaymentsFeeDetailsNoteController {
		return $this->init_from_container( new WooPaymentsFeeDetailsNoteController() );
	}

	/**
	 * Initialize an object with its init() dependencies from the container, apart from the given ones.
	 *
	 * @param object               $instance  Object to initialize.
	 * @param array<string,object> $overrides Dependencies by class name.
	 * @return object The object, initialized.
	 */
	private function init_from_container( object $instance, array $overrides = array() ): object {
		$init      = new ReflectionMethod( $instance, 'init' );
		$arguments = array();
		foreach ( $init->getParameters() as $parameter ) {
			$type = $parameter->getType();
			$this->assertInstanceOf( ReflectionNamedType::class, $type );
			$arguments[] = $overrides[ $type->getName() ] ?? wc_get_container()->get( $type->getName() );
		}
		$init->invokeArgs( $instance, $arguments );

		return $instance;
	}

	/**
	 * Create the platform client double: every call it answers is logged.
	 *
	 * @return Client
	 */
	private function create_platform_double(): Client {
		return new class( $this->log, $this->load_recorded_account() ) extends Client {
			/**
			 * Shared log.
			 *
			 * @var string[]
			 */
			private array $log;

			/**
			 * Account the platform returns.
			 *
			 * @var array<string,mixed>
			 */
			public array $account;

			/**
			 * Intents by ID.
			 *
			 * @var array<string,array<string,mixed>>
			 */
			public array $intents = array();

			/**
			 * Captured intent.
			 *
			 * @var array<string,mixed>
			 */
			public array $captured = array();

			/**
			 * Constructor.
			 *
			 * @param string[]            $log     Shared log.
			 * @param array<string,mixed> $account Account the platform returns.
			 */
			public function __construct( array &$log, array $account ) {
				$this->log     = &$log;
				$this->account = $account;
			}

			/**
			 * The site is connected.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Return the account.
			 *
			 * @param string $woocommerce_store_id Store ID.
			 * @return array<string,mixed>
			 */
			public function get_account( string $woocommerce_store_id = '' ): array {
				unset( $woocommerce_store_id );
				$this->log[] = 'get_account';

				return $this->account;
			}

			/**
			 * Return a stored intent.
			 *
			 * @param string $intent_id Intent ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_intention( string $intent_id ): array {
				$this->log[] = 'get_payment_intention';

				return $this->intents[ $intent_id ];
			}

			/**
			 * Return the captured intent.
			 *
			 * @param string              $intent_id         Intent ID.
			 * @param int                 $amount_to_capture Amount.
			 * @param array<string,mixed> $metadata          Metadata.
			 * @param array<string,mixed> $level3            Level 3 data.
			 * @return array<string,mixed>
			 */
			public function capture_intention( string $intent_id, int $amount_to_capture, array $metadata = array(), array $level3 = array() ): array {
				unset( $intent_id, $amount_to_capture, $metadata, $level3 );
				$this->log[] = 'capture_intention';

				return $this->captured;
			}
		};
	}

	/**
	 * Create a lifecycle service that logs each read in place of the caller's order; reads into a fresh copy are not logged.
	 *
	 * @return OrderPaymentLifecycleService
	 */
	private function create_read_logging_lifecycle(): OrderPaymentLifecycleService {
		$on_read   = function (): void {
			if ( null !== $this->on_read ) {
				( $this->on_read )();
			}
			$this->log[] = 'read';
		};
		$lifecycle = new class( $on_read ) extends OrderPaymentLifecycleService {
			/**
			 * Called at each read in place.
			 *
			 * @var callable
			 */
			private $on_read;

			/**
			 * Whether a fresh copy is being read.
			 *
			 * @var bool
			 */
			private bool $reading_copy = false;

			/**
			 * Constructor.
			 *
			 * @param callable $on_read Called at each read in place.
			 */
			public function __construct( callable $on_read ) {
				$this->on_read = $on_read;
			}

			/**
			 * Read a fresh copy, unlogged.
			 *
			 * @param WC_Order $order Order.
			 * @return WC_Order
			 */
			public function get_fresh_order_from_data_store( WC_Order $order ): WC_Order {
				$this->reading_copy = true;
				try {
					return parent::get_fresh_order_from_data_store( $order );
				} finally {
					$this->reading_copy = false;
				}
			}

			/**
			 * Read the order in place, and log it.
			 *
			 * @param WC_Order $order Order.
			 */
			public function reread_order_from_data_store( WC_Order $order ): void {
				if ( ! $this->reading_copy ) {
					( $this->on_read )();
				}
				parent::reread_order_from_data_store( $order );
			}
		};

		return $this->init_from_container( $lifecycle );
	}

	/**
	 * Create a pending WooPayments order.
	 *
	 * @param string $currency Order currency.
	 * @param string $total    Order total.
	 * @return WC_Order
	 */
	private function create_order( string $currency, string $total ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( $currency );
		$order->set_total( $total );
		$order->set_status( 'pending' );
		$order->save();

		return $order;
	}

	/**
	 * Load REC-3's recorded 12.34 EUR charge on a USD account (`Fixtures/platform-eur-charge.json`), naming the order when one is given.
	 *
	 * @param WC_Order|null $order Order the intent pays.
	 * @return array<string,mixed>
	 */
	private function load_recorded_eur_charge( ?WC_Order $order = null ): array {
		$intent = $this->load_fixture_body( 'platform-eur-charge.json', 'eur_charge_create_and_confirm' );
		if ( null !== $order ) {
			$intent['metadata']['order_id'] = (string) $order->get_id();
		}

		return $intent;
	}

	/**
	 * Load the recorded full account of a new test-drive account (REC-T60-2), whose default currency is USD.
	 *
	 * @return array<string,mixed>
	 */
	private function load_recorded_account(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		return json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/platform-test-drive-account.json' ), true )['account'];
	}

	/**
	 * Load one recorded entry's response body.
	 *
	 * @param string $file Fixture file under `Fixtures/`.
	 * @param string $pair Entry pair key.
	 * @return array<string,mixed>
	 */
	private function load_fixture_body( string $file, string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/' . $file ), true );
		foreach ( $fixture['entries'] as $entry ) {
			if ( $pair === $entry['pair'] ) {
				return $entry['response']['body'];
			}
		}

		$this->fail( "No fixture entry $pair in $file." );
	}

	/**
	 * Read a private property of the account cache.
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	private function get_cache_property( string $name ) {
		$property = new ReflectionProperty( WooPaymentsDatabaseCache::class, $name );
		$property->setAccessible( true );

		return $property->getValue( $this->account_cache );
	}

	/**
	 * Set a private property of the account cache.
	 *
	 * @param string $name  Property name.
	 * @param mixed  $value Value.
	 */
	private function set_cache_property( string $name, $value ): void {
		$property = new ReflectionProperty( WooPaymentsDatabaseCache::class, $name );
		$property->setAccessible( true );
		$property->setValue( $this->account_cache, $value );
	}
}
