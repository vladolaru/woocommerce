<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingApi;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingMigrator;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingSubscriptionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\SubscriptionDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Payment_Token_CC;
use WC_Unit_Test_Case;

/**
 * Migration of Stripe-billed subscriptions to on-site renewals.
 *
 * Expectations follow client 11.1.0 (`includes/subscriptions/class-wc-payments-subscriptions-migrator.php`; its test file has one
 * case). Stripe subscriptions are the local platform recordings in `Fixtures/rec-t63-billing-api.json`: `update_subscription`
 * answered with the main-chain subscription active on user 1's saved card, `cancel_subscription` with it cancelled.
 *
 * WooCommerce Subscriptions is made active through the legacy proxy's `class_exists`, which every test resets. Its background
 * repairer classes stay defined once loaded; only the Stripe Billing module asks for them.
 */
class StripeBillingMigratorTest extends WC_Unit_Test_Case {

	private const FIXTURE = __DIR__ . '/../Fixtures/rec-t63-billing-api.json';

	private const MAIN_SUBSCRIPTION_ID  = 'sub_1UM1VrBzWlxcwgpP6A3GwGLe';
	private const CLOCK_SUBSCRIPTION_ID = 'sub_1UM1XLBzWlxcwgpPwEbcmZJt';
	private const RECORDED_CARD         = 'pm_1UJhOFBzWlxcwgpPvcySvyc5';

	/**
	 * Recorded active Stripe subscription.
	 *
	 * @var array<string,mixed>
	 */
	private array $active_wcpay_subscription;

	/**
	 * Load WooCommerce Subscriptions with its background repairer, and build the migrator over a mocked platform.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => 'WC_Subscriptions' === $class_name || class_exists( $class_name, ...$args ),
			)
		);
		WooCommerceSubscriptionsDoubles::load();
		WooCommerceSubscriptionsDoubles::load_background_repairer();

		$this->active_wcpay_subscription = $this->get_recorded_body( 'update_subscription' );
	}

	/**
	 * Clear the container replacements and the subscription registry.
	 */
	public function tearDown(): void {
		try {
			$this->reset_container_replacements();
			$this->reset_container_resolutions();
			unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox A scheduled migration moves each Stripe-billed subscription to on-site renewals once, and leaves the others alone.
	 */
	public function test_a_scheduled_migration_moves_each_stripe_billed_subscription_once(): void {
		list( $sut, $api ) = $this->build_migrator();
		$main              = $this->create_stripe_billed_subscription( self::MAIN_SUBSCRIPTION_ID );
		$clock             = $this->create_stripe_billed_subscription( self::CLOCK_SUBSCRIPTION_ID );
		$on_site           = $this->create_stripe_billed_subscription( '' );
		$fetched           = array();
		$canceled          = array();
		$api->method( 'get_subscription' )->willReturnCallback(
			function ( string $id ) use ( &$fetched ) {
				$fetched[] = $id;

				return array( 'id' => $id ) + $this->active_wcpay_subscription;
			}
		);
		$api->method( 'cancel_subscription' )->willReturnCallback(
			function ( string $id ) use ( &$canceled ) {
				$canceled[] = $id;

				return array( 'id' => $id ) + $this->get_recorded_body( 'cancel_subscription' );
			}
		);

		$sut->schedule_migrate_wcpay_subscriptions_action();
		$this->assertTrue( $sut->is_migrating() );
		$this->run_pending_actions( StripeBillingMigrator::SCHEDULED_HOOK );
		$this->run_pending_actions( StripeBillingMigrator::MIGRATE_HOOK );
		// A second run finds nothing left to migrate.
		$sut->schedule_migrate_wcpay_subscriptions_action();
		$this->run_pending_actions( StripeBillingMigrator::SCHEDULED_HOOK );
		$this->run_pending_actions( StripeBillingMigrator::MIGRATE_HOOK );
		do_action( StripeBillingMigrator::MIGRATE_HOOK, $main->get_id() ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		$this->assertSame( array( self::MAIN_SUBSCRIPTION_ID, self::CLOCK_SUBSCRIPTION_ID ), $fetched );
		$this->assertSame( array( self::MAIN_SUBSCRIPTION_ID, self::CLOCK_SUBSCRIPTION_ID ), $canceled );
		foreach ( array( $main, $clock ) as $subscription ) {
			$subscription = wc_get_order( $subscription->get_id() );
			$this->assertFalse( $subscription->meta_exists( '_wcpay_subscription_id' ) );
			$this->assertFalse( $subscription->meta_exists( '_wcpay_pending_invoice_id' ) );
			$this->assertSame( 'in_1UM1VrBzWlxcwgpPgrIwNSlu', $subscription->get_meta( '_migrated_wcpay_billing_invoice_id' ) );
			$this->assertSame( 'This subscription has been successfully migrated to a WooPayments tokenized subscription.', wc_get_order_notes( array( 'order_id' => $subscription->get_id() ) )[0]->content );
		}
		$this->assertSame( self::MAIN_SUBSCRIPTION_ID, wc_get_order( $main->get_id() )->get_meta( '_migrated_wcpay_subscription_id' ) );
		$this->assertFalse( wc_get_order( $on_site->get_id() )->meta_exists( '_migrated_wcpay_subscription_id' ) );
		$this->assertFalse( $sut->is_migrating() );
		$this->assertFalse( get_option( StripeBillingMigrator::MIGRATION_BATCH_OPTION ), 'The batch marker is cleared once every subscription is scheduled.' );
	}

	/**
	 * @testdox Should cancel the Stripe subscription only while it can still bill: $status.
	 * @testWith ["active", true]
	 *           ["past_due", true]
	 *           ["trialing", true]
	 *           ["paused", true]
	 *           ["incomplete", false]
	 *           ["incomplete_expired", false]
	 *           ["canceled", false]
	 *           ["unpaid", false]
	 *
	 * @param string $status        Stripe subscription status.
	 * @param bool   $is_cancelled  Whether the Stripe subscription is cancelled.
	 */
	public function test_cancels_the_stripe_subscription_only_while_it_can_bill( string $status, bool $is_cancelled ): void {
		list( $sut, $api ) = $this->build_migrator();
		$subscription      = $this->create_stripe_billed_subscription( self::MAIN_SUBSCRIPTION_ID );
		$api->method( 'get_subscription' )->willReturn( array( 'status' => $status ) + $this->active_wcpay_subscription );
		$api->expects( $is_cancelled ? $this->once() : $this->never() )->method( 'cancel_subscription' )->with( self::MAIN_SUBSCRIPTION_ID )->willReturn( $this->get_recorded_body( 'cancel_subscription' ) );

		$sut->migrate_wcpay_subscription( $subscription->get_id() );

		$this->assertSame( self::MAIN_SUBSCRIPTION_ID, wc_get_order( $subscription->get_id() )->get_meta( '_migrated_wcpay_subscription_id' ) );
	}

	/**
	 * @testdox Should keep a future next payment date, one second later, so the renewal is rescheduled in WooCommerce.
	 */
	public function test_keeps_a_future_next_payment_date(): void {
		list( $sut, $api ) = $this->build_migrator();
		$next_payment      = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$subscription      = $this->create_stripe_billed_subscription( self::MAIN_SUBSCRIPTION_ID, array( 'next_payment' => $next_payment ) );
		$api->method( 'get_subscription' )->willReturn( $this->active_wcpay_subscription );

		$sut->migrate_wcpay_subscription( $subscription->get_id() );

		$this->assertSame( gmdate( 'Y-m-d H:i:s', strtotime( $next_payment . ' UTC' ) + 1 ), wc_get_order( $subscription->get_id() )->get_meta( '_schedule_next_payment' ) );
	}

	/**
	 * @testdox Should move a past next payment date to the end of the Stripe subscription's current period.
	 */
	public function test_moves_a_past_next_payment_date_to_the_stripe_period_end(): void {
		list( $sut, $api ) = $this->build_migrator();
		$subscription      = $this->create_stripe_billed_subscription( self::MAIN_SUBSCRIPTION_ID, array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		$api->method( 'get_subscription' )->willReturn( $this->active_wcpay_subscription );

		$sut->migrate_wcpay_subscription( $subscription->get_id() );

		$this->assertSame( gmdate( 'Y-m-d H:i:s', (int) $this->active_wcpay_subscription['current_period_end'] ), wc_get_order( $subscription->get_id() )->get_meta( '_schedule_next_payment' ) );
	}

	/**
	 * @testdox Should set the customer's saved card for the Stripe subscription's payment method as the token, without sending it to the cancelled Stripe subscription.
	 */
	public function test_sets_the_customers_saved_card_as_the_token(): void {
		list( $sut, $api ) = $this->build_migrator();
		$subscription      = $this->create_stripe_billed_subscription( self::MAIN_SUBSCRIPTION_ID, array(), false );
		$card              = $this->create_card( $subscription->get_customer_id() );
		$api->method( 'get_subscription' )->willReturn( $this->active_wcpay_subscription );
		$api->expects( $this->never() )->method( 'update_subscription' );
		add_action( 'woocommerce_payment_token_added_to_order', array( wc_get_container()->get( StripeBillingSubscriptionService::class ), 'update_wcpay_subscription_payment_method' ), 10, 3 );

		$sut->migrate_wcpay_subscription( $subscription->get_id() );

		$this->assertSame( array( $card->get_id() ), array_values( wc_get_order( $subscription->get_id() )->get_payment_tokens() ) );
	}

	/**
	 * @testdox Should save the Stripe subscription's payment method for the customer when no saved card matches it.
	 */
	public function test_saves_the_stripe_payment_method_when_no_card_matches(): void {
		list( $sut, $api ) = $this->build_migrator();
		$subscription      = $this->create_stripe_billed_subscription( self::MAIN_SUBSCRIPTION_ID, array(), false );
		$card              = $this->create_card( $subscription->get_customer_id() );
		$token_service     = $this->createMock( WooPaymentsTokenService::class );
		$token_service->expects( $this->once() )->method( 'get_or_create_token_for_user' )->with( 'pm_rec_t63_other_card', $subscription->get_customer_id() )->willReturn( $card );
		wc_get_container()->replace( WooPaymentsTokenService::class, $token_service );
		$api->method( 'get_subscription' )->willReturn( array( 'default_payment_method' => 'pm_rec_t63_other_card' ) + $this->active_wcpay_subscription );

		$sut->migrate_wcpay_subscription( $subscription->get_id() );

		$this->assertSame( array( $card->get_id() ), array_values( wc_get_order( $subscription->get_id() )->get_payment_tokens() ) );
	}

	/**
	 * @testdox Should retry a failed migration with a growing delay, and fail it after the seventh retry; a skipped migration is not retried.
	 */
	public function test_retries_a_failed_migration(): void {
		list( $sut, $api ) = $this->build_migrator();
		$subscription      = $this->create_stripe_billed_subscription( self::MAIN_SUBSCRIPTION_ID );
		$api->method( 'get_subscription' )->willThrowException( new WooPaymentsApiException( 'Error: The request timed out.', 'wcpay_http_request_failed', 500 ) );

		$sut->migrate_wcpay_subscription( $subscription->get_id() );
		$sut->migrate_wcpay_subscription( $subscription->get_id(), 6 );

		$retries      = as_get_scheduled_actions(
			array(
				'hook'   => StripeBillingMigrator::MIGRATE_HOOK . '_retry',
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);
		$retry_delays = array();
		foreach ( $retries as $retry ) {
			$retry_delays[ $retry->get_args()['attempt'] ] = $retry->get_schedule()->get_date()->getTimestamp() - time();
		}
		ksort( $retry_delays );
		$this->assertSame( array( 1, 7 ), array_keys( $retry_delays ) );
		$this->assertEqualsWithDelta( MINUTE_IN_SECONDS, $retry_delays[1], 5 );
		$this->assertEqualsWithDelta( 12 * HOUR_IN_SECONDS, $retry_delays[7], 5 );

		$this->expectExceptionMessage( 'Failed to fetch subscription' );
		$sut->migrate_wcpay_subscription( $subscription->get_id(), 7 );
	}

	/**
	 * @testdox Should skip, without asking Stripe or retrying, a subscription that $reason.
	 * @testWith ["is not Stripe-billed", ""]
	 *           ["was already migrated", "sub_1UM1VrBzWlxcwgpP6A3GwGLe"]
	 *
	 * @param string $reason                Why the subscription is skipped.
	 * @param string $migrated_subscription Stripe subscription ID the subscription was migrated from.
	 */
	public function test_skips_a_subscription_it_must_not_migrate( string $reason, string $migrated_subscription ): void {
		unset( $reason );
		list( $sut, $api ) = $this->build_migrator();
		$subscription      = $this->create_stripe_billed_subscription( '' === $migrated_subscription ? '' : self::CLOCK_SUBSCRIPTION_ID );
		$subscription->update_meta_data( '_migrated_wcpay_subscription_id', $migrated_subscription );
		$subscription->save();
		$api->expects( $this->never() )->method( 'get_subscription' );

		$sut->migrate_wcpay_subscription( $subscription->get_id() );

		$this->assertFalse( as_next_scheduled_action( StripeBillingMigrator::MIGRATE_HOOK . '_retry' ) );
	}

	/**
	 * @testdox Should offer the migration tool while Stripe-billed subscriptions remain, disabled while a migration runs, and never with bundled subscriptions.
	 */
	public function test_offers_the_migration_tool(): void {
		$this->build_migrator();
		$this->assertArrayNotHasKey( 'migrate_wcpay_subscriptions', apply_filters( 'woocommerce_debug_tools', array() ), 'No Stripe-billed subscription, no tool.' );

		$this->create_stripe_billed_subscription( self::MAIN_SUBSCRIPTION_ID );
		$tool = apply_filters( 'woocommerce_debug_tools', array() )['migrate_wcpay_subscriptions'];
		$this->assertSame( 'Migrate Stripe Billing subscriptions', $tool['name'] );
		$this->assertSame( 'Migrate Subscriptions', $tool['button'] );
		$this->assertSame( 'This tool will migrate all Stripe Billing subscriptions to tokenized subscriptions with WooPayments.<br>Number of Stripe Billing subscriptions found: 1', $tool['desc'] );
		$this->assertFalse( $tool['disabled'] );

		call_user_func( $tool['callback'] );
		$tool = apply_filters( 'woocommerce_debug_tools', array() )['migrate_wcpay_subscriptions'];
		$this->assertSame( 'Migration in progress&#8230;', $tool['button'] );
		$this->assertTrue( $tool['disabled'] );

		update_option( '_wcpay_feature_subscriptions', '1' );
		$this->assertArrayNotHasKey( 'migrate_wcpay_subscriptions', apply_filters( 'woocommerce_debug_tools', array() ) );
	}

	/**
	 * @testdox Should keep migrated Stripe Billing meta off the orders WooCommerce Subscriptions creates from a subscription.
	 */
	public function test_keeps_migrated_meta_off_related_orders(): void {
		$this->build_migrator();
		$meta = array(
			'_migrated_wcpay_subscription_id'           => array( self::MAIN_SUBSCRIPTION_ID ),
			'_migrated_wcpay_billing_invoice_id'        => array( 'in_1UM1VrBzWlxcwgpPgrIwNSlu' ),
			'_migrated_wcpay_pending_invoice_id'        => array( 'in_1UM1Y0BzWlxcwgpPefRU4SSy' ),
			'_migrated_wcpay_subscription_discount_ids' => array( array() ),
			'_billing_period'                           => array( 'month' ),
		);

		$this->assertSame( array( '_billing_period' => array( 'month' ) ), apply_filters( 'wc_subscriptions_object_data', $meta ) );
	}

	/**
	 * Build the migrator, with its hooks, over a mocked platform.
	 *
	 * @return array{0:StripeBillingMigrator,1:StripeBillingApi&MockObject}
	 */
	private function build_migrator(): array {
		$api = $this->createMock( StripeBillingApi::class );
		// Services resolved by earlier tests hold the real platform calls; rebuild them over the mock.
		$this->reset_container_resolutions();
		wc_get_container()->replace( StripeBillingApi::class, $api );

		$sut = new StripeBillingMigrator();
		$sut->init_hooks();

		return array( $sut, $api );
	}

	/**
	 * Create an active subscription paid with WooPayments on user 1's recorded card, with the recorded invoices.
	 *
	 * @param string               $wcpay_subscription_id Stripe subscription ID; empty for a subscription renewed on site.
	 * @param array<string,string> $dates                 GMT dates by type.
	 * @param bool                 $with_token            Whether the subscription has the recorded card as its token.
	 * @return SubscriptionDouble
	 */
	private function create_stripe_billed_subscription( string $wcpay_subscription_id, array $dates = array(), bool $with_token = true ): SubscriptionDouble {
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );

		$subscription = new SubscriptionDouble();
		$subscription->set_customer_id( $customer_id );
		$subscription->set_payment_method( 'woocommerce_payments' );
		$subscription->set_status( 'active' );
		if ( '' !== $wcpay_subscription_id ) {
			$subscription->update_meta_data( '_wcpay_subscription_id', $wcpay_subscription_id );
			$subscription->update_meta_data( '_wcpay_billing_invoice_id', 'in_1UM1VrBzWlxcwgpPgrIwNSlu' );
			$subscription->update_meta_data( '_wcpay_pending_invoice_id', 'in_1UM1Y0BzWlxcwgpPefRU4SSy' );
		}
		foreach ( $dates as $date_type => $date ) {
			$subscription->update_meta_data( '_schedule_' . $date_type, $date );
		}
		$subscription->save();
		if ( $with_token ) {
			$subscription->add_payment_token( $this->create_card( $customer_id ) );
		}
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ][] = $subscription->get_id();

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( SubscriptionDouble::class, $subscription );

		return $subscription;
	}

	/**
	 * Save the recorded card for a customer.
	 *
	 * @param int $customer_id Customer ID.
	 * @return WC_Payment_Token_CC
	 */
	private function create_card( int $customer_id ): WC_Payment_Token_CC {
		$card = new WC_Payment_Token_CC();
		$card->set_token( self::RECORDED_CARD );
		$card->set_gateway_id( 'woocommerce_payments' );
		$card->set_user_id( $customer_id );
		$card->set_card_type( 'visa' );
		$card->set_last4( '4242' );
		$card->set_expiry_month( '12' );
		$card->set_expiry_year( '2030' );
		$card->save();

		return $card;
	}

	/**
	 * Run the pending Action Scheduler actions of a hook, as the queue runner does.
	 *
	 * @param string $hook Hook.
	 */
	private function run_pending_actions( string $hook ): void {
		$action_ids = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);
		foreach ( $action_ids as $action_id ) {
			\ActionScheduler::runner()->process_action( $action_id, 'PHPUnit' );
		}
	}

	/**
	 * Get the response body of a recording.
	 *
	 * @param string $pair Entry name.
	 * @return array<string,mixed>
	 */
	private function get_recorded_body( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local test fixture.
		$fixture = json_decode( (string) file_get_contents( self::FIXTURE ), true );
		foreach ( $fixture['entries'] as $entry ) {
			if ( $pair === $entry['pair'] ) {
				return $entry['response']['body'];
			}
		}

		$this->fail( "Fixture entry $pair is missing." );
	}
}
