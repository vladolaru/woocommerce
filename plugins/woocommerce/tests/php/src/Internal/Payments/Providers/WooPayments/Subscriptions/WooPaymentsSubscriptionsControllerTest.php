<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\PaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\ProviderInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProviderGatewayAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\LegacyRuntimeProxy;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\ProviderTextLogAssertions;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\SubscriptionDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use Automattic\WooCommerce\Tests\Internal\Payments\RecordingPaymentProcessingService;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticWooPaymentsRuntimeArbiter;
use WC_Order;
use WC_Payment_Token_CC;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments handler of WooCommerce Subscriptions renewals: its hooks and the renewal charge.
 *
 * The client attaches its renewal handlers and the failed-renewal email whenever WooPayments loads, with or without its
 * gateway enabled: `wcpay_init()` runs on `plugins_loaded` (client 11.1.0 `woocommerce-payments.php:214`), builds the
 * card gateway and calls its `init_hooks()` (`includes/class-wc-payments.php:630-649`), which attaches the email filter
 * and the per-gateway renewal actions (`includes/compat/subscriptions/trait-wc-payment-gateway-wcpay-subscriptions.php:274-298`).
 * Each case boots the native payments bootstrap for one request; tearDown undoes what that boot leaves for the rest of
 * the process. The legacy facade case and the staging case run in their own process, as they declare WC_Payments or
 * alias WCS_Staging.
 */
class WooPaymentsSubscriptionsControllerTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * Payment gateways before the case booted the native bootstrap.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_payment_gateways = array();

	/**
	 * Remember the gateway list the case may rebuild.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_payment_gateways = WC()->payment_gateways()->payment_gateways;
	}

	/**
	 * Undo what one booted request leaves for the rest of the process: the gateway list rebuilt with native gateways, the
	 * container's replacements and resolved roots, the payments ownership memos, the REST routes, the two static
	 * one-time hook flags and the WooCommerce Subscriptions stand-in records.
	 */
	public function tearDown(): void {
		WC()->payment_gateways()->payment_gateways = $this->original_payment_gateways;
		unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ], $GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] );
		wc_get_container()->reset_all_replacements();
		wc_get_container()->reset_all_resolved();
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		$GLOBALS['wp_rest_server'] = null;

		$renewal_hooks = new \ReflectionProperty( WooPaymentsSubscriptionsController::class, 'attached' );
		$renewal_hooks->setAccessible( true );
		$renewal_hooks->setValue( null, false );
		$fallback_hooks = new \ReflectionProperty( NativeWooPaymentsGateway::class, 'classic_checkout_fallback_hooks_added' );
		$fallback_hooks->setAccessible( true );
		$fallback_hooks->setValue( null, false );
		parent::tearDown();
	}

	/**
	 * @testdox A native-owned $state store has the renewal handlers and the failed-renewal email after init, and refuses a renewal across test and live mode.
	 * @dataProvider native_owned_states
	 *
	 * @param string $state Stored native tier.
	 */
	public function test_native_owned_store_has_renewal_handlers_after_init( string $state ): void {
		$this->load_subscriptions();
		$this->arrange_ownership( false, true, $state );

		$this->register_native_payments_and_run_init();

		foreach ( array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'amazon_pay' ) as $gateway_id ) {
			$this->assertTrue( has_action( 'woocommerce_scheduled_subscription_payment_' . $gateway_id ), "The $gateway_id renewal handler must be attached." );
			$this->assertTrue( has_action( 'woocommerce_subscription_failing_payment_method_updated_' . $gateway_id ), "The $gateway_id failing-method handler must be attached." );
		}
		$this->assertSame( 20, has_filter( 'woocommerce_email_classes', array( NativeWooPaymentsGateway::class, 'add_subscription_emails' ) ), 'The failed-renewal email must be registered.' );

		$this->use_order_mode( 'prod' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Subscription was made when WooPayments was in the test mode and cannot be renewed in the live mode.' );
		apply_filters( 'wcs_renewal_order_items', array( 'line_item_a' ), new WC_Order(), $this->create_subscription_paid_in_mode( 'test' ) );
	}

	/**
	 * @testdox Attaching the renewal handler resolves none of the services a renewal uses.
	 */
	public function test_connected_store_attaches_the_renewal_root_without_resolving_renewal_services(): void {
		$this->load_subscriptions();
		$this->arrange_ownership( false, true, WooPaymentsSetupTier::CONNECTED );

		$this->register_native_payments_and_run_init();

		$root = wc_get_container()->get( WooPaymentsSubscriptionsController::class );
		$this->assertSame( 10, has_action( 'woocommerce_scheduled_subscription_payment_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID, array( $root, 'scheduled_subscription_payment' ) ) );
		foreach ( array( 'processing_service', 'provider', 'token_service' ) as $property ) {
			$reflection = new \ReflectionProperty( WooPaymentsSubscriptionsController::class, $property );
			$reflection->setAccessible( true );
			$this->assertNull( $reflection->getValue( $root ), "Attaching the renewal hooks must not resolve the $property." );
		}
	}

	/**
	 * @testdox A $label store has no native renewal handler and no native failed-renewal email after init.
	 * @dataProvider stores_without_native_renewals
	 *
	 * @param string $label        Case label.
	 * @param bool   $plugin_owned Whether the WooPayments plugin is active.
	 * @param bool   $native       Whether native payments is enabled.
	 * @param string $state        Stored native tier.
	 */
	public function test_store_without_native_ownership_has_no_native_renewal_handlers( string $label, bool $plugin_owned, bool $native, string $state ): void {
		unset( $label );
		$this->load_subscriptions();
		$this->arrange_ownership( $plugin_owned, $native, $state );

		$this->register_native_payments_and_run_init();
		if ( $plugin_owned ) {
			// Other code can still build the native card gateway, for example the legacy facade; it must not attach either.
			new NativeWooPaymentsGateway();
		}

		foreach ( array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'amazon_pay' ) as $gateway_id ) {
			$this->assertFalse( has_action( 'woocommerce_scheduled_subscription_payment_' . $gateway_id ), "No native $gateway_id renewal handler may be attached." );
		}
		$this->assertFalse( has_filter( 'woocommerce_email_classes', array( NativeWooPaymentsGateway::class, 'add_subscription_emails' ) ), 'The native failed-renewal email must not be registered.' );
	}

	/**
	 * @testdox A scheduled renewal on a connected store with the gateway disabled finds the native gateway and reaches the native handler.
	 */
	public function test_scheduled_renewal_reaches_the_native_handler(): void {
		$this->load_subscriptions();
		$this->arrange_ownership( false, true, WooPaymentsSetupTier::CONNECTED );
		$controller = $this->install_spy_controller();
		$this->register_native_payments_and_run_init();
		$this->reload_payment_gateways();
		list( $subscription, $renewal_order ) = $this->create_subscription_with_renewal_order();

		$this->assertSame( wc_get_container()->get( NativeWooPaymentsGateway::class ), wc_get_payment_gateway_by_order( $subscription ), 'Subscriptions must find the native gateway, or it treats the subscription as manual.' );
		$this->assertTrue( $this->run_scheduled_subscription_payment( $subscription, $renewal_order ), 'The renewal must be automatic, not a manual renewal order.' );
		$this->assertSame( array( array( 'scheduled_subscription_payment', 12.5, $renewal_order->get_id(), WooPaymentsPersistenceVocabulary::GATEWAY_ID ) ), $controller->calls, 'The renewal must reach the native handler once.' );
	}

	/**
	 * @testdox The gateway's renewal methods forward to the renewal handler, and a split gateway charges under its own ID.
	 */
	public function test_gateway_renewal_methods_forward_to_the_controller(): void {
		$controller = $this->install_spy_controller();
		$card       = new NativeWooPaymentsGateway();
		$split      = new NativeWooPaymentsGateway( ( new WooPaymentsPaymentMethodRegistry() )->get( 'sepa_debit' ) );
		$order      = new WC_Order();
		$order->save();

		$card->scheduled_subscription_payment( 7.25, $order );
		$split->scheduled_subscription_payment( 7.25, $order );
		$card->update_failing_payment_method( $order, $order );
		$card->maybe_force_subscription_to_manual( $order );

		$this->assertSame(
			array(
				array( 'scheduled_subscription_payment', 7.25, $order->get_id(), WooPaymentsPersistenceVocabulary::GATEWAY_ID ),
				array( 'scheduled_subscription_payment', 7.25, $order->get_id(), WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'sepa_debit' ),
				array( 'update_failing_payment_method', $order->get_id(), $order->get_id() ),
				array( 'maybe_force_subscription_to_manual', $order->get_id() ),
			),
			$controller->calls
		);
	}

	/**
	 * @testdox A gateway built after the renewal root attached adds no second renewal handler.
	 */
	public function test_gateway_built_after_the_root_adds_no_second_handler(): void {
		global $wp_filter;

		$this->load_subscriptions();
		$this->arrange_ownership( false, true, WooPaymentsSetupTier::ACTIVE );
		$controller = $this->install_spy_controller();
		$this->register_native_payments_and_run_init();
		$this->reload_payment_gateways();
		list( , $renewal_order ) = $this->create_subscription_with_renewal_order();

		$hook = 'woocommerce_scheduled_subscription_payment_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID;
		$this->assertSame( 1, array_sum( array_map( 'count', $wp_filter[ $hook ]->callbacks ) ), 'Exactly one renewal callback may be attached.' );
		do_action( $hook, $renewal_order->get_total(), $renewal_order );
		$this->assertCount( 1, $controller->calls, 'A renewal must reach the handler once.' );
	}

	/**
	 * @testdox The renewal root attaches on plugins_loaded priority 11 when WooCommerce loads earlier, as the client does.
	 */
	public function test_renewal_root_attaches_at_the_client_timing(): void {
		global $wp_actions;

		$this->load_subscriptions();
		$this->arrange_ownership( false, true, WooPaymentsSetupTier::CONNECTED );
		$root = wc_get_container()->get( WooPaymentsSubscriptionsController::class );
		unset( $wp_actions['plugins_loaded'] );

		$root->register();

		$this->assertSame( 11, has_action( 'plugins_loaded', array( $root, 'handle_plugins_loaded' ) ) );
		$this->assertFalse( has_action( 'woocommerce_scheduled_subscription_payment_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'Nothing may attach before Subscriptions has loaded.' );
		$root->handle_plugins_loaded();
		$this->assertSame( 10, has_action( 'woocommerce_scheduled_subscription_payment_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID, array( $root, 'scheduled_subscription_payment' ) ) );
		$this->assertSame( 20, has_filter( 'woocommerce_email_classes', array( NativeWooPaymentsGateway::class, 'add_subscription_emails' ) ) );
	}

	/**
	 * @testdox A store where nobody owns payments attaches no renewal handler when the legacy facade builds the gateway.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_owner_none_gateway_from_the_legacy_facade_attaches_no_handler(): void {
		$this->load_subscriptions();
		$this->arrange_ownership( false, false, WooPaymentsSetupTier::CONNECTED );
		if ( ! class_exists( 'WC_Payments', false ) ) {
			require_once WC_ABSPATH . 'includes/legacy/woopayments-compat/class-wc-payments.php';
		}
		$this->setExpectedDeprecated( 'WC_Payments::get_gateway' );

		$gateway = \WC_Payments::get_gateway();

		$this->assertInstanceOf( NativeWooPaymentsGateway::class, $gateway );
		$this->assertFalse( has_action( 'woocommerce_scheduled_subscription_payment_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID ), 'No native renewal handler may be attached.' );
		$this->assertFalse( has_filter( 'woocommerce_email_classes', array( NativeWooPaymentsGateway::class, 'add_subscription_emails' ) ), 'The native failed-renewal email must not be registered.' );
	}

	/**
	 * @testdox A renewal of a subscription paid in $subscription_mode mode, created in $current_mode mode, is refused only when the modes differ.
	 * @dataProvider renewal_modes
	 *
	 * Client 11.1.0 `tests/unit/subscriptions/test-class-wc-payments-subscription-service.php:858-884`. The staging case below
	 * shows the guard is attached to `wcs_renewal_order_items`.
	 *
	 * @param string      $subscription_mode `_wcpay_mode` on the subscription's parent order.
	 * @param string      $current_mode      Current order mode of the store.
	 * @param string|null $expected_error    Expected refusal message, or null when the renewal goes ahead.
	 */
	public function test_renewal_is_refused_when_the_mode_changed( string $subscription_mode, string $current_mode, ?string $expected_error ): void {
		$this->use_order_mode( $current_mode );
		$items = array( 'line_item_a', 'line_item_b' );

		try {
			if ( null !== $expected_error ) {
				$this->expectException( \RuntimeException::class );
				$this->expectExceptionMessage( $expected_error );
			}

			$result = WooPaymentsSubscriptionsController::check_renewal_mode( $items, new WC_Order(), $this->create_subscription_paid_in_mode( $subscription_mode ) );

			$this->assertSame( $items, $result );
		} finally {
			$this->reset_container_replacements();
		}
	}

	/**
	 * @testdox A staging copy does not refuse renewals over the mode, as in the client.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_staging_copy_does_not_refuse_renewals_over_the_mode(): void {
		$this->load_subscriptions();
		require_once __DIR__ . '/../Fixtures/DuplicateSiteSubscriptionsStaging.php';
		class_alias( \Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\DuplicateSiteSubscriptionsStaging::class, 'WCS_Staging' );
		$this->arrange_ownership( false, true, WooPaymentsSetupTier::ACTIVE );
		$this->register_native_payments_and_run_init();
		$this->use_order_mode( 'prod' );
		$items = array( 'line_item_a' );

		$result = apply_filters( 'wcs_renewal_order_items', $items, new WC_Order(), $this->create_subscription_paid_in_mode( 'test' ) );

		$this->assertSame( $items, $result );
	}

	/**
	 * @testdox WooCommerce Subscriptions copies no charge idempotency key into a subscription, a parent, a renewal or a resubscribe order.
	 *
	 * The key belongs to one order's charge. Subscriptions 9.0.1 copies a parent order's meta to its subscription
	 * (`includes/core/class-wc-subscriptions-checkout.php:191`) and the subscription's meta to every renewal
	 * (`includes/core/wcs-order-functions.php:242`, `includes/early-renewal/class-wcs-cart-early-renewal.php:150`), all
	 * through the data copier's `wc_subscriptions_object_data` filter (`includes/core/class-wc-subscriptions-data-copier.php:162`).
	 * A renewal carrying the parent's key would send it with another body, which Stripe refuses for 24 hours (review 37 F1);
	 * without the key the renewal charge mints a fresh one. The record of the parent's ambiguous charge failure belongs to
	 * the same charge and stays behind with the key, and so does the record of an ambiguous refund of that charge.
	 */
	public function test_subscriptions_copies_leave_out_the_charge_idempotency_key(): void {
		$this->load_subscriptions( true );
		$this->arrange_ownership( false, true, WooPaymentsSetupTier::ACTIVE );
		$this->register_native_payments_and_run_init();

		foreach ( array( 'subscription', 'parent', 'renewal_order', 'resubscribe_order' ) as $copy_type ) {
			$data = array(
				WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META => 'key_parent_charge',
				WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META => array(
					'order_id'  => 123,
					'customer'  => 'cus_parent',
					'failed_at' => 1700000000,
				),
				WooPaymentsProviderGatewayAdapter::REFUND_AMBIGUITY_META => array(
					'order_id'  => 123,
					'charge_id' => 'ch_parent',
					'key'       => 'key_parent_refund',
					'failed_at' => 1700000000,
				),
				'_payment_method_id' => 'pm_saved',
			);

			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Fired as WooCommerce Subscriptions' data copier does.
			$copied = apply_filters( 'wc_subscriptions_object_data', $data, new WC_Order(), new WC_Order(), $copy_type );

			$this->assertSame( array( '_payment_method_id' => 'pm_saved' ), $copied, "A $copy_type copy must leave out only the charge key and the charge and refund ambiguity records." );
		}
		$this->assertFalse( has_filter( 'wcs_renewal_order_meta_query', array( WooPaymentsSubscriptionsController::class, 'exclude_charge_idempotency_key_from_meta_query' ) ), 'The deprecated meta query filter must stay unhooked, or Subscriptions logs a deprecation.' );
	}

	/**
	 * @testdox Subscriptions versions without the data copier copy no charge idempotency key either.
	 *
	 * Before the data copier, `wcs_copy_order_meta()` selected the meta to copy with a query filtered by
	 * `wcs_{$type}_meta_query`, which the data copier still fires as deprecated (Subscriptions 9.0.1
	 * `includes/core/class-wc-subscriptions-data-copier.php:398`).
	 */
	public function test_subscriptions_without_the_data_copier_leave_out_the_charge_idempotency_key(): void {
		$this->load_subscriptions();
		$this->arrange_ownership( false, true, WooPaymentsSetupTier::ACTIVE );
		$this->register_native_payments_and_run_init();
		$query = 'SELECT `meta_key`, `meta_value` FROM wp_postmeta WHERE `post_id` = 123';

		foreach ( array( 'subscription', 'parent', 'renewal_order', 'resubscribe_order' ) as $copy_type ) {
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Fired as older WooCommerce Subscriptions versions do.
			$filtered = apply_filters( "wcs_{$copy_type}_meta_query", $query, new WC_Order(), new WC_Order() );

			$this->assertStringStartsWith( $query, $filtered );
			$this->assertStringContainsString( " AND `meta_key` NOT IN ('_wcpay_charge_idempotency_key', '_wcpay_charge_ambiguity', '_wcpay_refund_ambiguity')", $filtered, "The $copy_type meta query must leave out the charge key and the charge and refund ambiguity records." );
		}
		$this->assertFalse( has_filter( 'wc_subscriptions_object_data', array( WooPaymentsSubscriptionsController::class, 'exclude_charge_idempotency_key' ) ) );
	}

	/**
	 * @testdox Should process Amazon Pay scheduled subscription renewals through the renewal handler.
	 */
	public function test_amazon_pay_scheduled_subscription_payment_hook_reaches_the_renewal_handler(): void {
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$order->set_customer_id( $user_id );
		$active_token = $this->create_card_token( $user_id, 'pm_amazon_renewal' );
		$order->add_payment_token( $active_token );
		$order->save();

		$service = new RecordingPaymentProcessingService();
		WooPaymentsSubscriptionsController::attach( $this->create_sut( $service, new WooPaymentsProvider() ) );

		/**
		 * Fires a scheduled WooPayments Amazon Pay subscription renewal payment.
		 *
		 * @since 11.0.0
		 */
		do_action( 'woocommerce_scheduled_subscription_payment_woocommerce_payments_amazon_pay', 12.0, wc_get_order( $order->get_id() ) );

		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertSame( $order->get_id(), $service->last_checkout_context->get_order_id() );
		$this->assertSame(
			array(
				'scheduled_subscription_payment'    => true,
				'saved_payment_method_display_name' => $active_token->get_display_name(),
			),
			$service->last_checkout_context->get_provider_data()
		);
	}

	/**
	 * @testdox Unusable saved renewal methods fail through the payment lifecycle with an actionable note.
	 */
	public function test_scheduled_subscription_payment_fails_unusable_saved_method_with_actionable_note(): void {
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$token   = $this->create_card_token( $user_id, 'pm_unusable_saved_method' );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->add_payment_token( $token );
		$order->update_meta_data( '_payment_method_id', 'pm_unusable_saved_method' );
		$order->save();

		$api_client       = new class() extends WooPaymentsApiClient {
			/**
			 * Last API request.
			 *
			 * @var array<string,mixed>
			 */
			public array $last_request_data = array();

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Create and confirm a payment intention.
			 *
			 * @param array<string,mixed> $request_data Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @throws WooPaymentsApiException Always, to model the unusable saved method.
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $idempotency_key );
				$this->last_request_data = $request_data;

				throw new WooPaymentsApiException( 'Provider diagnostic for pm_unusable_saved_method.', 'payment_method_no_longer_available', 400, 'invalid_request_error', '', array(), 'pi_unusable_saved_method' );
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_renewal' );
		wc_get_container()->replace( WooPaymentsApiClient::class, $api_client );
		wc_get_container()->replace( WooPaymentsCustomerService::class, $customer_service );
		wc_get_container()->reset_all_resolved();

		$status_changes         = 0;
		$status_change_callback = static function ( int $order_id, string $from, string $to ) use ( $order, &$status_changes ): void {
			unset( $from );
			if ( $order->get_id() === $order_id && 'failed' === $to ) {
				++$status_changes;
			}
		};
		add_action(
			'woocommerce_order_status_changed',
			$status_change_callback,
			10,
			3
		);

		try {
			$sut = $this->create_sut( wc_get_container()->get( PaymentProcessingService::class ), wc_get_container()->get( WooPaymentsProvider::class ) );
			$sut->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );
			$order            = wc_get_order( $order->get_id() );
			$notes            = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
			$actionable_notes = array_filter(
				$notes,
				static fn( $note ): bool => false !== strpos( (string) $note->content, 'the saved payment method <strong>' . $token->get_display_name() . '</strong> can no longer be used' )
			);
			$raw_notes        = array_filter(
				$notes,
				static fn( $note ): bool => false !== strpos( (string) $note->content, 'Provider diagnostic' ) || false !== strpos( (string) $note->content, 'pm_unusable_saved_method' )
			);

			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertSame( 'failed', $order->get_status() );
			$this->assertSame( 1, $status_changes );
			$this->assertCount( 1, $actionable_notes );
			$this->assertCount( 0, $raw_notes );
			$this->assertArrayNotHasKey( 'saved_payment_method_display_name', $api_client->last_request_data );
			$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $order->get_payment_method() );
			$this->assertSame( 'pm_unusable_saved_method', $order->get_meta( '_payment_method_id', true ) );
			$order->update_meta_data( '_intention_status', 'processing' );
			$order->save_meta_data();
			$this->assertSame( 'processing', $order->get_meta( '_intention_status', true ) );

			wc_get_container()->get( WooPaymentsEventIngestor::class )->process(
				array(
					'id'   => 'evt_unusable_method_replay',
					'type' => 'payment_intent.payment_failed',
					'data' => array(
						'object' => array(
							'id'                 => 'pi_unusable_saved_method',
							'status'             => 'requires_payment_method',
							'currency'           => 'usd',
							'payment_method'     => 'pm_unusable_saved_method',
							'metadata'           => array(
								'order_id'  => (string) $order->get_id(),
								'order_key' => $order->get_order_key(),
							),
							'last_payment_error' => array(
								'code'           => 'payment_method_no_longer_available',
								'message'        => 'Raw provider diagnostic for pm_unusable_saved_method.',
								'payment_method' => array(
									'id'   => 'pm_unusable_saved_method',
									'type' => 'card',
								),
							),
						),
					),
				)
			);
			$order            = wc_get_order( $order->get_id() );
			$notes            = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
			$actionable_notes = array_filter(
				$notes,
				static fn( $note ): bool => false !== strpos( (string) $note->content, 'the saved payment method <strong>' . $token->get_display_name() . '</strong> can no longer be used' )
			);
			$raw_notes        = array_filter(
				$notes,
				static fn( $note ): bool => false !== strpos( (string) $note->content, 'Raw provider diagnostic' ) || false !== strpos( (string) $note->content, 'pm_unusable_saved_method' )
			);

			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertSame( 'failed', $order->get_status() );
			$this->assertSame( 1, $status_changes );
			$this->assertSame( 'pi_unusable_saved_method', $order->get_meta( '_intent_id', true ) );
			$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ) );
			$this->assertCount( 1, $actionable_notes );
			$this->assertCount( 0, $raw_notes );
		} finally {
			remove_action( 'woocommerce_order_status_changed', $status_change_callback, 10 );
			delete_transient( 'wcpay_processed_event_' . md5( 'evt_unusable_method_replay' ) );
			$this->reset_container_replacements();
			wc_get_container()->reset_all_resolved();
		}
	}

	/**
	 * @testdox Should process scheduled subscription payments with the saved renewal token.
	 */
	public function test_scheduled_subscription_payment_uses_saved_renewal_token(): void {
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$order->set_customer_id( $user_id );
		$order->add_payment_token( $this->create_card_token( $user_id, 'pm_old_renewal' ) );
		$active_token = $this->create_card_token( $user_id, 'pm_renewal' );
		$order->add_payment_token( $active_token );
		$order->save();

		$service = new RecordingPaymentProcessingService();
		$sut     = $this->create_sut( $service, new WooPaymentsProvider(), $this->create_unhooked_token_service() );

		$sut->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );

		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
		$this->assertSame( $order->get_id(), $service->last_checkout_context->get_order_id() );
		$this->assertSame(
			array(
				'payment_token'       => (string) $active_token->get_id(),
				'save_payment_method' => false,
			),
			$service->last_checkout_context->get_payment_data()
		);
		$this->assertSame(
			array(
				'scheduled_subscription_payment'    => true,
				'saved_payment_method_display_name' => $active_token->get_display_name(),
			),
			$service->last_checkout_context->get_provider_data()
		);
	}

	/**
	 * @testdox Should charge tokenized renewals while the Stripe Billing module is not loaded, even with the Stripe Billing options set.
	 */
	public function test_scheduled_subscription_payment_uses_tokenized_renewal_when_deprecated_stripe_billing_flags_remain(): void {
		update_option( '_wcpay_feature_subscriptions', '1' );
		update_option( '_wcpay_feature_stripe_billing', '1' );

		try {
			$user_id = self::factory()->user->create();
			$order   = $this->create_order();
			$order->set_customer_id( $user_id );
			$active_token = $this->create_card_token( $user_id, 'pm_tokenized_renewal' );
			$order->add_payment_token( $active_token );
			$order->save();

			$service = new RecordingPaymentProcessingService();
			$sut     = $this->create_sut( $service, new WooPaymentsProvider() );

			$sut->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );

			$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context );
			$this->assertSame(
				array(
					'payment_token'       => (string) $order->get_payment_tokens()[0],
					'save_payment_method' => false,
				),
				$service->last_checkout_context->get_payment_data()
			);
			$this->assertSame(
				array(
					'scheduled_subscription_payment'    => true,
					'saved_payment_method_display_name' => $active_token->get_display_name(),
				),
				$service->last_checkout_context->get_provider_data()
			);
		} finally {
			delete_option( '_wcpay_feature_subscriptions' );
			delete_option( '_wcpay_feature_stripe_billing' );
		}
	}

	/**
	 * @testdox Should use the current subscription customer when processing a scheduled renewal.
	 */
	public function test_scheduled_subscription_payment_uses_current_subscription_customer(): void {
		$user_id      = self::factory()->user->create();
		$parent_order = $this->create_order();
		$subscription = $this->create_order();
		$renewal      = $this->create_order();

		$parent_order->update_meta_data( '_stripe_customer_id', 'cus_parent_stale' );
		$parent_order->update_meta_data( '_stripe_mandate_id', 'mandate_parent' );
		$parent_order->save();
		$subscription->set_parent_id( $parent_order->get_id() );
		$subscription->update_meta_data( '_stripe_customer_id', 'cus_subscription_current' );
		$subscription->save();
		$renewal->set_customer_id( $user_id );
		$active_token = $this->create_card_token( $user_id, 'pm_renewal' );
		$renewal->add_payment_token( $active_token );
		$renewal->save();

		$this->ensure_wcs_renewal_subscriptions_double();

		$service = new RecordingPaymentProcessingService();
		$sut     = $this->create_sut( $service, new WooPaymentsProvider() );

		$GLOBALS['wcpay_test_renewal_subscription_ids'] = array( $renewal->get_id() => array( $subscription->get_id() ) );
		try {
			$sut->scheduled_subscription_payment( 12.0, wc_get_order( $renewal->get_id() ) );
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_subscription_ids'] );
		}

		$renewal = wc_get_order( $renewal->get_id() );

		$this->assertInstanceOf( WC_Order::class, $renewal );
		$this->assertSame( 'cus_subscription_current', $renewal->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame(
			array(
				'scheduled_subscription_payment'    => true,
				'saved_payment_method_display_name' => $active_token->get_display_name(),
				'renewal_mandate'                   => 'mandate_parent',
			),
			$service->last_checkout_context->get_provider_data()
		);
	}

	/**
	 * @testdox Should fail scheduled renewals and fire the preserved action when customer authentication is required.
	 */
	public function test_scheduled_subscription_payment_fails_and_fires_requires_action_hook(): void {
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$order->set_customer_id( $user_id );
		$order->set_currency( 'USD' );
		$order->add_payment_token( $this->create_card_token( $user_id, 'pm_requires_action' ) );
		$order->save();

		$service  = new class( new PaymentOutcome(
			PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
			'pi_requires_action',
			'#wcpay-confirm-pi:' . $order->get_id() . ':secret:nonce',
			'pm_requires_action',
			'cus_requires_action',
			array(
				'charge_id' => 'ch_requires_action',
				'meta'      => array(
					'_charge_id' => 'ch_legacy_meta',
				),
			)
		) ) extends RecordingPaymentProcessingService {
			/**
			 * Outcome returned by process_checkout_outcome.
			 *
			 * @var PaymentOutcome
			 */
			private PaymentOutcome $outcome;

			/**
			 * Constructor.
			 *
			 * @param PaymentOutcome $outcome Outcome returned by process_checkout_outcome.
			 */
			public function __construct( PaymentOutcome $outcome ) {
				$this->outcome = $outcome;
			}

			/**
			 * Process checkout payment and return the neutral outcome.
			 *
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Provider.
			 * @return PaymentOutcome
			 */
			public function process_checkout_outcome( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
				$this->last_checkout_context = $context;

				return $this->outcome;
			}
		};
		$received = array();
		add_action(
			'woocommerce_woocommerce_payments_payment_requires_action',
			static function ( WC_Order $hook_order, string $intent_id, string $payment_method_id, string $customer_id, string $charge_id, string $currency ) use ( &$received ): void {
				$received = array(
					'hook_order'        => $hook_order,
					'intent_id'         => $intent_id,
					'payment_method_id' => $payment_method_id,
					'customer_id'       => $customer_id,
					'charge_id'         => $charge_id,
					'currency'          => $currency,
				);
			},
			10,
			6
		);

		$sut = $this->create_sut( $service, new WooPaymentsProvider() );

		$sut->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );
		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( $order->get_id(), $received['hook_order']->get_id() );
		$this->assertSame( 'pi_requires_action', $received['intent_id'] );
		$this->assertSame( 'pm_requires_action', $received['payment_method_id'] );
		$this->assertSame( 'cus_requires_action', $received['customer_id'] );
		$this->assertSame( 'ch_requires_action', $received['charge_id'] );
		$this->assertSame( 'USD', $received['currency'] );
	}

	/**
	 * @testdox A customer-action hook callback that throws a $throwable_class leaves the renewal pending and fails the scheduled action.
	 *
	 * Client 11.1.0 `gw:1921` runs the hook without a catch, so the throwable reaches Action Scheduler before
	 * `mark_payment_failed()`. The gateway logs it whatever the logging setting.
	 *
	 * @testWith ["RuntimeException"]
	 *           ["TypeError"]
	 *
	 * @param string $throwable_class Class the hook callback throws.
	 */
	public function test_scheduled_subscription_payment_rethrows_when_requires_action_hook_throws( string $throwable_class ): void {
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$order->set_customer_id( $user_id );
		$order->set_currency( 'USD' );
		$order->add_payment_token( $this->create_card_token( $user_id, 'pm_requires_action' ) );
		$order->save();

		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
			'pi_requires_action',
			'#wcpay-confirm-pi:' . $order->get_id() . ':secret:nonce',
			'pm_requires_action',
			'cus_requires_action',
			array(
				'meta' => array(
					'_charge_id' => 'ch_requires_action',
				),
			)
		);

		$thrown = new $throwable_class( 'email callback failed' );
		add_action(
			'woocommerce_woocommerce_payments_payment_requires_action',
			static function () use ( $thrown ): void {
				throw $thrown;
			}
		);
		$logger = RecordingWcLogger::install();

		$sut = $this->create_sut( $service, new WooPaymentsProvider() );

		$caught = null;
		try {
			$sut->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );
		} catch ( \Throwable $throwable ) {
			$caught = $throwable;
		}

		$this->assertSame( $thrown, $caught, 'The hook failure reaches the scheduled action.' );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$lines = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => 'Failed to run WooPayments subscription renewal authentication hooks.' === $line[1] ) );
		$this->assertCount( 1, $lines, 'The failure is logged whatever the logging setting.' );
		$this->assertSame( $throwable_class, $logger->contexts[ $lines[0] ]['exception'] ?? '' );
	}

	/**
	 * Platform errors a hook callback can throw.
	 *
	 * @return array<string,array{bool}>
	 */
	public function hook_platform_failures(): array {
		return array(
			'a platform error'               => array( false ),
			'its own exception wrapping one' => array( true ),
		);
	}

	/**
	 * @testdox A customer-action hook callback that throws $_dataName is logged by the platform's status and code, never a message.
	 *
	 * A callback can call the platform and let its error out, directly or wrapped, so the message is not WooCommerce's own text.
	 *
	 * @dataProvider hook_platform_failures
	 *
	 * @param bool $wrapped Whether the callback wraps the platform error in its own exception.
	 */
	public function test_requires_action_hook_failure_log_leaves_out_platform_text( bool $wrapped ): void {
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$order->set_customer_id( $user_id );
		$order->set_currency( 'USD' );
		$order->add_payment_token( $this->create_card_token( $user_id, 'pm_requires_action' ) );
		$order->save();

		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
			'pi_requires_action',
			'#wcpay-confirm-pi:' . $order->get_id() . ':secret:nonce',
			'pm_requires_action',
			'cus_requires_action',
			array(
				'meta' => array(
					'_charge_id' => 'ch_requires_action',
				),
			)
		);

		$platform_error = self::make_provider_error();
		$thrown         = $wrapped ? new \RuntimeException( 'Reminder email failed: ' . $platform_error->getMessage(), 0, $platform_error ) : $platform_error;
		add_action(
			'woocommerce_woocommerce_payments_payment_requires_action',
			static function () use ( $thrown ): void {
				throw $thrown;
			}
		);
		$logger = RecordingWcLogger::install();

		$sut = $this->create_sut( $service, new WooPaymentsProvider() );

		$caught = null;
		try {
			$sut->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );
		} catch ( \Throwable $throwable ) {
			$caught = $throwable;
		}

		$this->assertSame( $thrown, $caught, 'The hook failure still reaches the scheduled action.' );
		$context = $this->get_logged_context( $logger, 'Failed to run WooPayments subscription renewal authentication hooks.' );
		$this->assertSame( array( get_class( $thrown ), 404, 'resource_missing' ), array( $context['exception'], $context['http_status'], $context['error_code'] ) );
		$this->assertSame( array( $order->get_id(), 'pi_requires_action' ), array( $context['order_id'], $context['intent_id'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox Should keep a renewal's provider outcome when applying it fails.
	 *
	 * Scheduled renewals have no checkout to answer, so the handed-back failure must not escape into Action Scheduler.
	 */
	public function test_scheduled_subscription_payment_keeps_outcome_when_applying_it_fails(): void {
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$token   = $this->create_card_token( $user_id, 'pm_renewal_card' );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->add_payment_token( $token );
		$order->save();
		$provider = $this->create_provider_failing_after_charge(
			new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_renewal_downstream', '', 'pm_renewal_card' ),
			'post_lifecycle_effects',
			new \RuntimeException( 'Renewal display details failed' )
		);
		$sut      = $this->create_sut( wc_get_container()->get( PaymentProcessingService::class ), $provider );

		$sut->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'pi_renewal_downstream', $order->get_transaction_id() );
	}

	/**
	 * @testdox A PHP error applying a $outcome_status renewal outcome is rethrown: $rethrown; the renewal ends $expected_status.
	 *
	 * Client 11.1.0 catches only API_Exception around the renewal payment (`trait-wc-payment-gateway-wcpay-subscriptions.php:426`),
	 * so a PHP error escapes to Action Scheduler, which fails the action.
	 * The gateway logs it whatever the logging setting and rethrows it; the charge stays reconcilable on the renewal. A renewal
	 * that needs customer action is the exception: it still runs the requires-action handling, which fails the renewal and
	 * fires the authentication hook, as the client does for that outcome (gw:1921), instead of the scheduled action failing.
	 *
	 * @testWith ["completed", true, "pending"]
	 *           ["requires_customer_action", false, "failed"]
	 *
	 * @param string $outcome_status  Provider outcome status.
	 * @param bool   $rethrown        Whether the PHP error reaches Action Scheduler.
	 * @param string $expected_status Renewal status afterwards.
	 */
	public function test_scheduled_subscription_payment_rethrows_php_error_applying_outcome( string $outcome_status, bool $rethrown, string $expected_status ): void {
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$token   = $this->create_card_token( $user_id, 'pm_renewal_card' );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->add_payment_token( $token );
		$order->save();
		$error               = new \TypeError( 'Argument #1 must be of type array, null given' );
		$provider            = $this->create_provider_failing_after_charge(
			new PaymentOutcome( $outcome_status, 'pi_renewal_error', '', 'pm_renewal_card' ),
			'operation_effects',
			$error
		);
		$sut                 = $this->create_sut( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$logger              = RecordingWcLogger::install();
		$authentication_hook = 0;
		add_action(
			'woocommerce_woocommerce_payments_payment_requires_action',
			static function () use ( &$authentication_hook ): void {
				++$authentication_hook;
			}
		);

		$thrown = null;
		try {
			$sut->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );
		} catch ( \Throwable $throwable ) {
			$thrown = $throwable;
		}

		$this->assertSame( $rethrown ? $error : null, $thrown, $rethrown ? 'The PHP error must reach Action Scheduler.' : 'A requires-action renewal must not fail the scheduled action.' );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $expected_status, $order->get_status() );
		$this->assertSame( 'pi_renewal_error', $order->get_transaction_id() );
		$this->assertSame( $rethrown ? 0 : 1, $authentication_hook );
		$lines = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'woopayments' === $line[2] && str_starts_with( $line[1], 'Error applying the WooPayments subscription renewal payment' ) ) );
		$this->assertCount( 1, $lines, 'The PHP error is logged whatever the logging setting.' );
		$this->assertSame( 'error', $lines[0][0] );
	}

	/**
	 * @testdox A platform error applying a renewal payment is logged with its status and code, never its message.
	 */
	public function test_renewal_apply_failure_log_leaves_out_platform_text(): void {
		self::enable_woopayments_debug_logging();
		$user_id = self::factory()->user->create();
		$order   = $this->create_order();
		$token   = $this->create_card_token( $user_id, 'pm_renewal_card' );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->add_payment_token( $token );
		$order->save();
		$provider = $this->create_provider_failing_after_charge(
			new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_renewal_platform_error', '', 'pm_renewal_card' ),
			'post_lifecycle_effects',
			self::make_provider_error()
		);
		$sut      = $this->create_sut( wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$logger   = RecordingWcLogger::install();

		$sut->scheduled_subscription_payment( 12.0, wc_get_order( $order->get_id() ) );

		$context = $this->get_logged_context( $logger, 'Error applying the WooPayments subscription renewal payment.' );
		$this->assertSame( array( 404, 'resource_missing', $order->get_id() ), array( $context['http_status'], $context['error_code'], $context['order_id'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox A platform error during the renewal token repair is logged with its status and code, never its message.
	 */
	public function test_renewal_token_repair_log_leaves_out_platform_text(): void {
		$this->ensure_wcs_renewal_subscriptions_double();
		self::enable_woopayments_debug_logging();
		$customer_id = self::factory()->user->create();
		$parent      = wc_create_order();
		$parent->set_customer_id( $customer_id );
		$parent->update_meta_data( '_payment_method_id', 'pm_repair_123' );
		$parent->save();
		$subscription = wc_create_order();
		$subscription->set_parent_id( $parent->get_id() );
		$subscription->set_customer_id( $customer_id );
		$subscription->save();
		$renewal = wc_create_order();
		$renewal->set_customer_id( $customer_id );
		$renewal->set_payment_method( 'woocommerce_payments' );
		$renewal->save();
		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service->method( 'get_or_create_token_for_user' )->willThrowException( self::make_provider_error() );
		wc_get_container()->replace( WooPaymentsTokenService::class, $token_service );
		$logger = RecordingWcLogger::install();
		$sut    = $this->create_sut( new RecordingPaymentProcessingService(), new WooPaymentsProvider() );

		$GLOBALS['wcpay_test_renewal_subscription_ids'] = array( $renewal->get_id() => array( $subscription->get_id() ) );
		try {
			$sut->scheduled_subscription_payment( 10.00, $renewal );
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_subscription_ids'] );
		}

		$context = $this->get_logged_context( $logger, 'Error repairing subscription renewal payment token for order #' . $renewal->get_id() . '.' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox Should repair a renewal order's missing token from the parent order and charge it.
	 */
	public function test_scheduled_subscription_payment_repairs_missing_token_from_parent_order(): void {
		$this->ensure_wcs_renewal_subscriptions_double();
		$customer_id = self::factory()->user->create();

		$parent = wc_create_order();
		$parent->set_customer_id( $customer_id );
		$parent->update_meta_data( '_payment_method_id', 'pm_repair_123' );
		$parent->save();

		$subscription = wc_create_order();
		$subscription->set_parent_id( $parent->get_id() );
		$subscription->set_customer_id( $customer_id );
		$subscription->set_payment_method( 'woocommerce_payments' );
		$subscription->save();

		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_user_id( $customer_id );
		$token->set_token( 'pm_repair_123' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();

		$renewal = wc_create_order();
		$renewal->set_customer_id( $customer_id );
		$renewal->set_payment_method( 'woocommerce_payments' );
		$renewal->set_total( '10.00' );
		$renewal->save();

		$GLOBALS['wcpay_test_renewal_subscription_ids'] = array( $renewal->get_id() => array( $subscription->get_id() ) );

		$service = new RecordingPaymentProcessingService();
		$sut     = $this->create_sut( $service, new WooPaymentsProvider() );

		try {
			$sut->scheduled_subscription_payment( 10.00, $renewal );
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_subscription_ids'] );
		}

		$this->assertInstanceOf( PaymentOperationContext::class, $service->last_checkout_context, 'The repaired token must let the renewal charge proceed.' );
		$this->assertSame( (string) $token->get_id(), $service->last_checkout_context->get_payment_data()['payment_token'] );

		$renewal_fresh = wc_get_order( $renewal->get_id() );
		$this->assertNotSame( 'failed', $renewal_fresh->get_status() );
		$this->assertContains( $token->get_id(), array_map( 'absint', $renewal_fresh->get_payment_tokens() ), 'The restored token must land on the renewal order itself.' );
		$renewal_notes = array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $renewal->get_id() ) ) );
		$this->assertContains( 'Recovered missing subscription payment method token from the parent order.', $renewal_notes );

		$subscription_fresh = wc_get_order( $subscription->get_id() );
		$this->assertContains( $token->get_id(), array_map( 'absint', $subscription_fresh->get_payment_tokens() ), 'The subscription must get the restored token so the next renewal does not need repair.' );
		$subscription_notes = implode( ' | ', array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $subscription->get_id() ) ) ) );
		$this->assertStringContainsString( 'restored', $subscription_notes );
	}

	/**
	 * @testdox Should still fail the renewal when the parent order has no payment method to restore.
	 */
	public function test_scheduled_subscription_payment_fails_when_repair_finds_nothing(): void {
		$this->ensure_wcs_renewal_subscriptions_double();
		$customer_id = self::factory()->user->create();

		$parent = wc_create_order();
		$parent->set_customer_id( $customer_id );
		$parent->save();

		$subscription = wc_create_order();
		$subscription->set_parent_id( $parent->get_id() );
		$subscription->set_customer_id( $customer_id );
		$subscription->save();

		$renewal = wc_create_order();
		$renewal->set_customer_id( $customer_id );
		$renewal->set_payment_method( 'woocommerce_payments' );
		$renewal->save();

		$GLOBALS['wcpay_test_renewal_subscription_ids'] = array( $renewal->get_id() => array( $subscription->get_id() ) );
		$this->enable_debug_logging();
		$logger = RecordingWcLogger::install();

		$service = new RecordingPaymentProcessingService();
		$sut     = $this->create_sut( $service, new WooPaymentsProvider() );

		try {
			$sut->scheduled_subscription_payment( 10.00, $renewal );
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_subscription_ids'] );
		}

		$this->assertNull( $service->last_checkout_context );
		$renewal_fresh = wc_get_order( $renewal->get_id() );
		$this->assertSame( 'failed', $renewal_fresh->get_status() );
		// Client 11.1.0 trait:415.
		$this->assertContains( array( 'error', 'There is no saved payment token for order #' . $renewal->get_id(), 'woopayments' ), $logger->lines );
	}

	/**
	 * @testdox When the token repair throws a $throwable_class, the renewal ends $status and the throwable propagates: $propagates.
	 *
	 * Client 11.1.0 trait:538 catches only exceptions: an exception fails the renewal for a missing token (trait:413-418),
	 * a PHP Error reaches the scheduled action and leaves the renewal pending.
	 *
	 * @testWith ["RuntimeException", "failed", false]
	 *           ["TypeError", "pending", true]
	 *
	 * @param string $throwable_class Class the repair throws.
	 * @param string $status          Renewal status afterwards.
	 * @param bool   $propagates      Whether the throwable leaves scheduled_subscription_payment().
	 */
	public function test_scheduled_subscription_payment_token_repair_failure( string $throwable_class, string $status, bool $propagates ): void {
		$this->ensure_wcs_renewal_subscriptions_double();
		$customer_id = self::factory()->user->create();

		$parent = wc_create_order();
		$parent->set_customer_id( $customer_id );
		$parent->update_meta_data( '_payment_method_id', 'pm_repair_123' );
		$parent->save();

		$subscription = wc_create_order();
		$subscription->set_parent_id( $parent->get_id() );
		$subscription->set_customer_id( $customer_id );
		$subscription->save();

		$renewal = wc_create_order();
		$renewal->set_customer_id( $customer_id );
		$renewal->set_payment_method( 'woocommerce_payments' );
		$renewal->save();

		$thrown        = new $throwable_class( 'Call to a member function get_id() on null' );
		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service->method( 'get_or_create_token_for_user' )->willThrowException( $thrown );
		wc_get_container()->replace( WooPaymentsTokenService::class, $token_service );
		$logger = RecordingWcLogger::install();

		$GLOBALS['wcpay_test_renewal_subscription_ids'] = array( $renewal->get_id() => array( $subscription->get_id() ) );

		$service = new RecordingPaymentProcessingService();
		$sut     = $this->create_sut( $service, new WooPaymentsProvider() );

		$caught = null;
		try {
			$sut->scheduled_subscription_payment( 10.00, $renewal );
		} catch ( \Throwable $throwable ) {
			$caught = $throwable;
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_subscription_ids'] );
		}

		$this->assertSame( $propagates ? $thrown : null, $caught );
		$this->assertNull( $service->last_checkout_context );
		$this->assertSame( $status, wc_get_order( $renewal->get_id() )->get_status() );
		$repair_lines = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => str_starts_with( $line[1], 'Error repairing subscription renewal payment token' ) ) );
		$this->assertCount( $propagates ? 1 : 0, $repair_lines, 'Only a PHP Error is logged with debug logging off.' );
	}

	/**
	 * @testdox A PHP error fetching the payment method during token repair fails the scheduled action and leaves the renewal pending.
	 *
	 * Client 11.1.0 fetches the payment method without a catch (`class-wc-payments-token-service.php:136`) and the repair
	 * catches only Exception (trait:538), so the PHP error reaches Action Scheduler instead of failing the renewal with
	 * "No saved payment method found". The error is logged once, by the
	 * repair.
	 */
	public function test_scheduled_subscription_payment_token_repair_payment_method_fetch_php_error(): void {
		$this->ensure_wcs_renewal_subscriptions_double();
		$customer_id = self::factory()->user->create();

		$parent = wc_create_order();
		$parent->set_customer_id( $customer_id );
		$parent->update_meta_data( '_payment_method_id', 'pm_repair_fetch' );
		$parent->save();

		$subscription = wc_create_order();
		$subscription->set_parent_id( $parent->get_id() );
		$subscription->set_customer_id( $customer_id );
		$subscription->save();

		$renewal = wc_create_order();
		$renewal->set_customer_id( $customer_id );
		$renewal->set_payment_method( 'woocommerce_payments' );
		$renewal->save();

		$error          = new \TypeError( 'Return value must be of type array, null returned' );
		$legacy_runtime = new WooPaymentsLegacyRuntime();
		$legacy_runtime->init( new LegacyRuntimeProxy( false ) );
		$details_service = new WooPaymentsPaymentMethodDetailsService();
		$details_service->init(
			$legacy_runtime,
			new class( $error ) extends WooPaymentsApiClient {
				/**
				 * Error to throw.
				 *
				 * @var \TypeError
				 */
				private \TypeError $error;

				/**
				 * Constructor.
				 *
				 * @param \TypeError $error Error to throw.
				 */
				public function __construct( \TypeError $error ) {
					$this->error = $error;
				}

				// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
				/**
				 * Throw the PHP error.
				 *
				 * @param string $payment_method_id Payment method ID.
				 * @return array<string,mixed>
				 * @throws \TypeError Always.
				 */
				public function get_payment_method( string $payment_method_id ): array {
					unset( $payment_method_id );
					throw $this->error;
				}
				// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
			},
			new StaticWooPaymentsRuntimeArbiter( true )
		);
		$token_service = new WooPaymentsTokenService();
		$token_service->init( $details_service, new StaticWooPaymentsRuntimeArbiter( true ), wc_get_container()->get( WooPaymentsApiClient::class ), wc_get_container()->get( WooPaymentsCustomerService::class ), wc_get_container()->get( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsOrderDataService::class ) );
		wc_get_container()->replace( WooPaymentsTokenService::class, $token_service );

		$GLOBALS['wcpay_test_renewal_subscription_ids'] = array( $renewal->get_id() => array( $subscription->get_id() ) );

		$service = new RecordingPaymentProcessingService();
		$sut     = $this->create_sut( $service, new WooPaymentsProvider() );
		$logger  = RecordingWcLogger::install();

		$caught = null;
		try {
			$sut->scheduled_subscription_payment( 10.00, $renewal );
		} catch ( \Throwable $throwable ) {
			$caught = $throwable;
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_subscription_ids'] );
		}

		$this->assertSame( $error, $caught );
		$this->assertNull( $service->last_checkout_context );
		$this->assertSame( 'pending', wc_get_order( $renewal->get_id() )->get_status() );
		$notes = array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $renewal->get_id() ) ) );
		$this->assertNotContains( 'Subscription renewal failed: No saved payment method found.', $notes );
		$error_lines = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => str_starts_with( $line[1], 'Error repairing subscription renewal payment token' ) ) );
		$this->assertCount( 1, $error_lines, 'The PHP error is logged once.' );
		$this->assertSame( \TypeError::class, $logger->contexts[ $error_lines[0] ]['exception'] );
	}

	/**
	 * @testdox Should not attempt token repair when network-wide saved cards are forced.
	 */
	public function test_scheduled_subscription_payment_skips_repair_for_network_saved_cards(): void {
		$this->ensure_wcs_renewal_subscriptions_double();
		$customer_id = self::factory()->user->create();

		$parent = wc_create_order();
		$parent->set_customer_id( $customer_id );
		$parent->update_meta_data( '_payment_method_id', 'pm_repair_123' );
		$parent->save();

		$subscription = wc_create_order();
		$subscription->set_parent_id( $parent->get_id() );
		$subscription->set_customer_id( $customer_id );
		$subscription->save();

		$renewal = wc_create_order();
		$renewal->set_customer_id( $customer_id );
		$renewal->set_payment_method( 'woocommerce_payments' );
		$renewal->save();

		$GLOBALS['wcpay_test_renewal_subscription_ids'] = array( $renewal->get_id() => array( $subscription->get_id() ) );
		$filter = static fn(): bool => true;
		add_filter( 'wcpay_force_network_saved_cards', $filter );

		$service = new RecordingPaymentProcessingService();
		$sut     = $this->create_sut( $service, new WooPaymentsProvider() );

		try {
			$sut->scheduled_subscription_payment( 10.00, $renewal );
		} finally {
			remove_filter( 'wcpay_force_network_saved_cards', $filter );
			unset( $GLOBALS['wcpay_test_renewal_subscription_ids'] );
		}

		$this->assertNull( $service->last_checkout_context );
		$this->assertSame( 'failed', wc_get_order( $renewal->get_id() )->get_status() );
	}

	/**
	 * @testdox A renewal of a Stripe-billed subscription is not charged here, toggle on or off; a tokenized subscription's renewal still is (client `trait-wc-payment-gateway-wcpay-subscriptions.php:405-407`, `:1243-1258`).
	 * @testWith ["0", "sub_1UM1VrBzWlxcwgpP6A3GwGLe", false]
	 *           ["1", "sub_1UM1VrBzWlxcwgpP6A3GwGLe", false]
	 *           ["1", "", true]
	 *
	 * @param string $toggle                Stripe Billing toggle option value.
	 * @param string $wcpay_subscription_id Stripe subscription ID of the subscription, empty when it is tokenized.
	 * @param bool   $expect_charge         Whether the renewal is charged.
	 */
	public function test_renewals_of_stripe_billed_subscriptions_are_not_charged( string $toggle, string $wcpay_subscription_id, bool $expect_charge ): void {
		$this->load_stripe_billing_module( $toggle );
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription( $user_id, $wcpay_subscription_id );
		$renewal      = \WC_Helper_Order::create_order( $user_id );
		$renewal->add_payment_token( $this->create_card_token( $user_id, 'pm_1UJhOFBzWlxcwgpPvcySvyc5' ) );
		$renewal->set_status( 'pending' );
		$renewal->save();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ][ $renewal->get_id() ]['renewal'][] = $subscription->get_id();
		$note_count = count( wc_get_order_notes( array( 'order_id' => $renewal->get_id() ) ) );
		$service    = new RecordingPaymentProcessingService();
		$sut        = $this->create_sut( $service, new WooPaymentsProvider(), $this->create_unhooked_token_service() );

		$sut->scheduled_subscription_payment( 10.0, wc_get_order( $renewal->get_id() ) );

		if ( $expect_charge ) {
			$this->assertSame( $renewal->get_id(), $service->last_checkout_context ? $service->last_checkout_context->get_order_id() : 0, 'A tokenized renewal is charged.' );
			return;
		}

		$this->assertSame( 0, $service->checkout_attempt_count, 'A Stripe-billed renewal must not be charged.' );
		$saved = wc_get_order( $renewal->get_id() );
		$this->assertSame( 'pending', $saved->get_status() );
		$this->assertCount( $note_count, wc_get_order_notes( array( 'order_id' => $renewal->get_id() ) ) );
	}

	/** @return array<string,array{string,string,?string}> */
	public static function renewal_modes(): array {
		return array(
			'test subscription, live store' => array( 'test', 'prod', 'Subscription was made when WooPayments was in the test mode and cannot be renewed in the live mode.' ),
			'live subscription, test store' => array( 'prod', 'test', 'Subscription was made when WooPayments was in the live mode and cannot be renewed in the test mode.' ),
			'same mode'                     => array( 'test', 'test', null ),
			'no recorded mode'              => array( '', 'prod', null ),
		);
	}

	/**
	 * Make the store report the given order mode.
	 *
	 * @param string $order_mode `test` or `prod`.
	 */
	private function use_order_mode( string $order_mode ): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_order_mode' ) )
			->getMock();
		$account_service->method( 'get_order_mode' )->willReturn( $order_mode );
		wc_get_container()->replace( WooPaymentsAccountService::class, $account_service );
	}

	/**
	 * Create a subscription stand-in whose parent order was paid in the given mode.
	 *
	 * @param string $mode `_wcpay_mode` on the parent order; empty for none.
	 * @return WC_Order
	 */
	private function create_subscription_paid_in_mode( string $mode ): WC_Order {
		$parent_order = new WC_Order();
		$parent_order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		if ( '' !== $mode ) {
			$parent_order->update_meta_data( '_wcpay_mode', $mode );
		}
		$parent_order->save();

		$subscription               = new class() extends WC_Order {
			/** @var WC_Order|null */
			public ?WC_Order $parent_order = null;

			/**
			 * Return the parent order, as WC_Subscription::get_parent() does.
			 *
			 * @return WC_Order|false
			 */
			public function get_parent() {
				return $this->parent_order ?? false;
			}
		};
		$subscription->parent_order = $parent_order;

		return $subscription;
	}

	/** @return array<string,array{string}> */
	public static function native_owned_states(): array {
		return array(
			'connected, gateway disabled' => array( WooPaymentsSetupTier::CONNECTED ),
			'active'                      => array( WooPaymentsSetupTier::ACTIVE ),
		);
	}

	/** @return array<string,array{string,bool,bool,string}> */
	public static function stores_without_native_renewals(): array {
		return array(
			'plugin-owned, native tier clamped' => array( 'plugin-owned', true, true, WooPaymentsSetupTier::ACTIVE ),
			'plugin-owned, native not enabled'  => array( 'plugin-owned', true, false, WooPaymentsSetupTier::DISABLED ),
			'never set up'                      => array( 'never set up', false, false, WooPaymentsSetupTier::DISABLED ),
		);
	}

	/**
	 * Put a renewal handler that records its calls in the container, where the bootstrap and the gateways find it.
	 *
	 * @return WooPaymentsSubscriptionsController&object{calls:array<int,array<int,mixed>>}
	 */
	private function install_spy_controller(): WooPaymentsSubscriptionsController {
		$controller = new class() extends WooPaymentsSubscriptionsController {
			/** @var array<int,array<int,mixed>> */
			public array $calls = array();

			/**
			 * Record a renewal instead of charging.
			 *
			 * @param mixed  $amount        Renewal amount.
			 * @param mixed  $renewal_order Renewal order.
			 * @param string $gateway_id    Gateway the charge goes through.
			 */
			public function scheduled_subscription_payment( $amount, $renewal_order, string $gateway_id = WooPaymentsPersistenceVocabulary::GATEWAY_ID ): void {
				$this->calls[] = array( __FUNCTION__, (float) $amount, $renewal_order->get_id(), $gateway_id );
			}

			/**
			 * Record a failing-method update.
			 *
			 * @param mixed $subscription  Subscription.
			 * @param mixed $renewal_order Renewal order.
			 */
			public function update_failing_payment_method( $subscription, $renewal_order ): void {
				$this->calls[] = array( __FUNCTION__, $subscription->get_id(), $renewal_order->get_id() );
			}

			/**
			 * Record the manual-renewal policy check.
			 *
			 * @param mixed $subscription Subscription.
			 */
			public function maybe_force_subscription_to_manual( $subscription ): void {
				$this->calls[] = array( __FUNCTION__, $subscription->get_id() );
			}
		};
		$controller->init( wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );
		wc_get_container()->replace( WooPaymentsSubscriptionsController::class, $controller );

		return $controller;
	}

	/**
	 * Build WooCommerce's gateway list again, as a new request would after the bootstrap ran.
	 */
	private function reload_payment_gateways(): void {
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
	}

	/**
	 * Create a subscription stand-in paid with WooPayments and its pending renewal order.
	 *
	 * @return array{WC_Order,WC_Order}
	 */
	private function create_subscription_with_renewal_order(): array {
		$subscription = new WC_Order();
		$subscription->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$subscription->save();

		$renewal_order = new WC_Order();
		$renewal_order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$renewal_order->set_total( '12.50' );
		$renewal_order->set_status( 'pending' );
		$renewal_order->save();

		return array( $subscription, $renewal_order );
	}

	/**
	 * Run a scheduled renewal the way WooCommerce Subscriptions does.
	 *
	 * Mirrors Subscriptions 8.x: `WC_Subscription::is_manual()` asks `has_available_payment_method()`, which is
	 * `wc_get_payment_gateway_by_order( $subscription )` (`includes/core/class-wc-subscription.php:700-710`,
	 * `includes/gateways/class-wc-subscriptions-payment-gateways.php:131-133`). An automatic renewal then fires the
	 * gateway's hook through `trigger_gateway_renewal_payment_hook()` (same file, `:68-122`).
	 *
	 * @param WC_Order $subscription  Subscription stand-in.
	 * @param WC_Order $renewal_order Renewal order.
	 * @return bool Whether the renewal was automatic.
	 */
	private function run_scheduled_subscription_payment( WC_Order $subscription, WC_Order $renewal_order ): bool {
		if ( ! wc_get_payment_gateway_by_order( $subscription ) ) {
			return false;
		}

		if ( $renewal_order->get_total() > 0 && $renewal_order->get_payment_method() ) {
			WC()->payment_gateways();
			do_action( 'woocommerce_scheduled_subscription_payment_' . $renewal_order->get_payment_method(), $renewal_order->get_total(), $renewal_order );
		}

		return true;
	}

	/**
	 * Report the WooCommerce Subscriptions core library as loaded, which is how the gateway and the renewal root detect
	 * Subscriptions. They ask LegacyProxy; the mock is reset after every test.
	 *
	 * @param bool $with_data_copier Whether to report Subscriptions' data copier as loaded too.
	 */
	private function load_subscriptions( bool $with_data_copier = false ): void {
		$loaded = $with_data_copier ? array( 'WC_Subscriptions_Core_Plugin', 'WC_Subscriptions_Data_Copier' ) : array( 'WC_Subscriptions_Core_Plugin' );
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => in_array( $class_name, $loaded, true ) || class_exists( $class_name, ...$args ),
			)
		);
	}

	/**
	 * Arrange payments ownership and the stored tier for a fresh request.
	 *
	 * @param bool   $plugin_owned Whether the WooPayments plugin is active.
	 * @param bool   $native       Whether native payments is enabled.
	 * @param string $state        Stored native tier.
	 */
	private function arrange_ownership( bool $plugin_owned, bool $native, string $state ): void {
		$active_plugins = array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) ) );
		if ( $plugin_owned ) {
			$active_plugins[] = WooPaymentsRuntimeArbiter::PLUGIN_FILE;
		}
		update_option( 'active_plugins', $active_plugins );
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, $native ? '__return_true' : '__return_false' );
		update_option( WooPaymentsSetupTier::OPTION_NAME, $state, true );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
	}

	/**
	 * Run the native payments bootstrap for a shopper request, as WooCommerce does when it loads, then run the `init`
	 * callbacks it added. The rest of `init` already ran when the test suite loaded WordPress.
	 */
	private function register_native_payments_and_run_init(): void {
		global $wp_filter;

		$before = isset( $wp_filter['init'] ) ? $wp_filter['init']->callbacks : array();
		( new PaymentsBootstrap(
			static fn( $container, string $request_type ): array => $container->get( WooPaymentsSetupTier::class )->get_classes_for_request( $request_type ),
			static fn( $container ): bool => $container->get( WooPaymentsRuntimeArbiter::class )->is_builtin_owner(),
			static fn(): array => array()
		) )->register( wc_get_container(), '__return_false' );

		$after = isset( $wp_filter['init'] ) ? $wp_filter['init']->callbacks : array();
		ksort( $after );
		foreach ( $after as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $callback ) {
				if ( ! isset( $before[ $priority ][ $id ] ) ) {
					call_user_func( $callback['function'] );
				}
			}
		}
	}

	/**
	 * Build the renewal handler with the given services in the container, where it resolves them when a renewal runs.
	 *
	 * @param PaymentProcessingService     $processing_service Payment processing service.
	 * @param WooPaymentsProvider|null     $provider           WooPayments provider, or null for the container's.
	 * @param WooPaymentsTokenService|null $token_service      Token service, or null for the container's.
	 * @return WooPaymentsSubscriptionsController
	 */
	private function create_sut( PaymentProcessingService $processing_service, ?WooPaymentsProvider $provider = null, ?WooPaymentsTokenService $token_service = null ): WooPaymentsSubscriptionsController {
		wc_get_container()->replace( PaymentProcessingService::class, $processing_service );
		if ( null !== $provider ) {
			wc_get_container()->replace( WooPaymentsProvider::class, $provider );
		}
		if ( null !== $token_service ) {
			wc_get_container()->replace( WooPaymentsTokenService::class, $token_service );
		}

		return new WooPaymentsSubscriptionsController();
	}

	/**
	 * Create an order for gateway tests.
	 *
	 * @return WC_Order
	 */
	private function create_order(): WC_Order {
		$order = wc_create_order();
		$order->set_total( '12.00' );
		$order->save();

		return $order;
	}

	/**
	 * Create a saved WooPayments card token.
	 *
	 * @param int    $user_id           User ID.
	 * @param string $payment_method_id Provider payment method ID.
	 * @return WC_Payment_Token_CC
	 */
	private function create_card_token( int $user_id, string $payment_method_id ): WC_Payment_Token_CC {
		$token = new WC_Payment_Token_CC();
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_user_id( $user_id );
		$token->set_token( $payment_method_id );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();

		return $token;
	}

	/**
	 * Create a token service built from the container's dependencies, with no hooks registered.
	 *
	 * @return WooPaymentsTokenService
	 */
	private function create_unhooked_token_service(): WooPaymentsTokenService {
		$container     = wc_get_container();
		$token_service = new WooPaymentsTokenService();
		$token_service->init(
			$container->get( WooPaymentsPaymentMethodDetailsService::class ),
			new StaticWooPaymentsRuntimeArbiter( false ),
			$container->get( WooPaymentsApiClient::class ),
			$container->get( WooPaymentsCustomerService::class ),
			$container->get( WooPaymentsAccountService::class ),
			$container->get( WooPaymentsOrderDataService::class )
		);

		return $token_service;
	}

	/**
	 * Create a WooPayments provider whose charge returns a fixed outcome and whose effects can throw.
	 *
	 * @param PaymentOutcome $outcome Outcome the charge returns.
	 * @param string         $stage   Where to throw: `operation_effects` (before the lifecycle) or `post_lifecycle_effects` (after it).
	 * @param \Throwable     $failure What to throw.
	 * @return WooPaymentsProvider
	 */
	private function create_provider_failing_after_charge( PaymentOutcome $outcome, string $stage, \Throwable $failure ): WooPaymentsProvider {
		return new class( $outcome, $stage, $failure ) extends WooPaymentsProvider {
			/**
			 * Outcome the charge returns.
			 *
			 * @var PaymentOutcome
			 */
			private PaymentOutcome $outcome;

			/**
			 * Where to throw.
			 *
			 * @var string
			 */
			private string $stage;

			/**
			 * What to throw.
			 *
			 * @var \Throwable
			 */
			private \Throwable $failure;

			/**
			 * Constructor.
			 *
			 * @param PaymentOutcome $outcome Outcome the charge returns.
			 * @param string         $stage   Where to throw.
			 * @param \Throwable     $failure What to throw.
			 */
			public function __construct( PaymentOutcome $outcome, string $stage, \Throwable $failure ) {
				$this->outcome = $outcome;
				$this->stage   = $stage;
				$this->failure = $failure;
			}

			/**
			 * Return the fixed charge outcome.
			 *
			 * @param PaymentOperationContext $context         Payment context.
			 * @param string                  $idempotency_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function charge( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context, $idempotency_key );

				return $this->outcome;
			}

			/**
			 * Throw before the lifecycle when asked to.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 * @return PaymentOutcome
			 * @throws \Throwable When the stage is `operation_effects`.
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $operation );
				if ( 'operation_effects' === $this->stage ) {
					throw $this->failure;
				}

				return $outcome;
			}

			/**
			 * Throw after the lifecycle when asked to.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Applied provider outcome.
			 * @param string                  $operation Operation name.
			 * @throws \Throwable When the stage is `post_lifecycle_effects`.
			 */
			public function apply_post_lifecycle_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): void {
				unset( $context, $outcome, $operation );
				if ( 'post_lifecycle_effects' === $this->stage ) {
					throw $this->failure;
				}
			}
		};
	}

	/**
	 * Turn on WooPayments debug logging, which the client's Logger requires for checkout errors (`src/Internal/Logger.php:64-91`).
	 */
	private function enable_debug_logging(): void {
		$settings = get_option( 'woocommerce_woocommerce_payments_settings', array() );
		update_option( 'woocommerce_woocommerce_payments_settings', array_merge( is_array( $settings ) ? $settings : array(), array( 'enable_logging' => 'yes' ) ) );
	}

	/**
	 * Ensure a minimal renewal-subscriptions lookup double exists.
	 *
	 * @return void
	 */
	private function ensure_wcs_renewal_subscriptions_double(): void {
		if ( function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public renewal lookup.
		eval( 'namespace { function wcs_get_subscriptions_for_renewal_order( $order_id ) { $ids = $GLOBALS["wcpay_test_renewal_subscription_ids"][ $order_id ] ?? array(); return array_map( "wc_get_order", $ids ); } }' );
	}

	/**
	 * Make the built-in WooPayments the payments owner, which the renewal handlers require.
	 */
	private function make_builtin_own_payments(): void {
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
	}

	/**
	 * Report WooCommerce Subscriptions as loaded to the Stripe Billing module and the gateway's subscription supports, which
	 * ask LegacyProxy. The mock is reset after every test.
	 */
	private function report_subscriptions_loaded(): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => in_array( $class_name, array( 'WC_Subscriptions', 'WC_Subscriptions_Core_Plugin' ), true ) || class_exists( $class_name, ...$args ),
			)
		);
	}

	/**
	 * Load WooCommerce Subscriptions and the Stripe Billing module with the given toggle, while native owns payments.
	 *
	 * @param string $toggle Stripe Billing toggle option value.
	 * @return WooPaymentsStripeBillingModule
	 */
	private function load_stripe_billing_module( string $toggle ): WooPaymentsStripeBillingModule {
		$this->report_subscriptions_loaded();
		WooCommerceSubscriptionsDoubles::load();
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, $toggle );

		$arbiter = $this->getMockBuilder( WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$arbiter->method( 'is_builtin_owner' )->willReturn( true );

		$module = new WooPaymentsStripeBillingModule();
		$module->init( $arbiter );
		$module->register();
		wc_get_container()->replace( WooPaymentsStripeBillingModule::class, $module );

		return $module;
	}

	/**
	 * Create a WooPayments subscription for a customer, billed by Stripe Billing when it has a Stripe subscription ID.
	 *
	 * @param int    $user_id               Customer user ID.
	 * @param string $wcpay_subscription_id Stripe subscription ID, empty for a tokenized subscription.
	 * @return SubscriptionDouble
	 */
	private function create_subscription( int $user_id, string $wcpay_subscription_id ): SubscriptionDouble {
		$subscription = new SubscriptionDouble();
		$subscription->set_payment_method( 'woocommerce_payments' );
		$subscription->set_customer_id( $user_id );
		if ( '' !== $wcpay_subscription_id ) {
			$subscription->update_meta_data( '_wcpay_subscription_id', $wcpay_subscription_id );
		}
		$subscription->save();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ][] = $subscription->get_id();

		return $subscription;
	}
}
