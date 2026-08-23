<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSessionService;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsCustomerService class.
 */
class WooPaymentsCustomerServiceTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'wcpay_session_store_id' );
		unset( $_GET['change_payment_method'], $GLOBALS['wcpay_test_subscription_ids'], $GLOBALS['wcpay_test_checkout_blocks_api_request'] );
		if ( WC()->session ) {
			WC()->session->set( 'wcpay_customer_id', null );
		}

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * @testdox Registration should hook created-customer promotion only when native owns the runtime.
	 */
	public function test_register_hooks_created_customer_promotion_only_for_native_runtime(): void {
		$native_sut = $this->create_sut( false, $this->create_customer_api_client( array() ) );
		$native_sut->register();
		$this->assertNotFalse( has_action( 'woocommerce_created_customer', array( $native_sut, 'handle_woocommerce_created_customer' ) ) );

		$plugin_sut = new WooPaymentsCustomerService();
		$plugin_sut->init( $this->create_customer_api_client( array() ), $this->create_account_service_stub( false ), new WooPaymentsSessionService(), new StaticNativeRuntimeArbiter( false ) );
		$plugin_sut->register();
		$this->assertFalse( has_action( 'woocommerce_created_customer', array( $plugin_sut, 'handle_woocommerce_created_customer' ) ), 'The promotion hook must not register while the standalone plugin owns the runtime.' );
	}

	/**
	 * @testdox A guest session customer ID should be promoted onto an account created during checkout.
	 */
	public function test_created_customer_promotes_guest_session_customer_id_during_checkout(): void {
		$user_id = $this->factory->user->create( array( 'user_login' => 'created-during-checkout' ) );
		WC()->session->set( 'wcpay_customer_id', 'cus_guest' );
		$this->fake_wcs_checkout_blocks_api_request();
		$GLOBALS['wcpay_test_checkout_blocks_api_request'] = true;

		$sut = $this->create_sut( false, $this->create_customer_api_client( array() ) );
		$sut->handle_woocommerce_created_customer( $user_id );

		$this->assertSame( 'cus_guest', get_user_option( WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, $user_id ), 'The guest session customer must follow the newly created account.' );
	}

	/**
	 * @testdox Account creation outside a checkout should not adopt the session customer ID.
	 */
	public function test_created_customer_outside_checkout_does_not_promote_session_customer_id(): void {
		if ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT ) {
			$this->markTestSkipped( 'Another test in this process defined WOOCOMMERCE_CHECKOUT; the outside-checkout scenario cannot be simulated.' );
		}

		$user_id = $this->factory->user->create( array( 'user_login' => 'created-outside-checkout' ) );
		WC()->session->set( 'wcpay_customer_id', 'cus_guest' );
		$this->fake_wcs_checkout_blocks_api_request();
		$GLOBALS['wcpay_test_checkout_blocks_api_request'] = false;

		$sut = $this->create_sut( false, $this->create_customer_api_client( array() ) );
		$sut->handle_woocommerce_created_customer( $user_id );

		$this->assertFalse( get_user_option( WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, $user_id ) );
	}

	/**
	 * @testdox Checkout account creation without a guest session customer should write nothing.
	 */
	public function test_created_customer_without_session_customer_id_writes_nothing(): void {
		$user_id = $this->factory->user->create( array( 'user_login' => 'created-no-session-customer' ) );
		WC()->session->set( 'wcpay_customer_id', null );
		$this->fake_wcs_checkout_blocks_api_request();
		$GLOBALS['wcpay_test_checkout_blocks_api_request'] = true;

		$sut = $this->create_sut( false, $this->create_customer_api_client( array() ) );
		$sut->handle_woocommerce_created_customer( $user_id );

		$this->assertFalse( get_user_option( WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, $user_id ) );
	}

	/**
	 * @testdox Should prepare the logged-in customer's billing details for the add-payment-method form.
	 */
	public function test_get_prepared_customer_data_uses_logged_in_customer_on_add_payment_method_page(): void {
		$user_id = $this->factory->user->create(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'user_email' => 'ada@example.com',
			)
		);
		update_user_meta( $user_id, 'billing_email', 'billing@example.com' );
		update_user_meta( $user_id, 'billing_country', 'RO' );
		wp_set_current_user( $user_id );

		$my_account_page_id = $this->factory->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_option( 'woocommerce_myaccount_page_id', $my_account_page_id );
		$this->go_to( get_permalink( $my_account_page_id ) );
		$GLOBALS['wp']->query_vars['add-payment-method'] = '';

		$sut = $this->create_sut( false, $this->create_customer_api_client( array() ) );
		$this->assertTrue( method_exists( $sut, 'get_prepared_customer_data' ), 'The native customer service should own prepared checkout customer data.' );

		$data = $sut->get_prepared_customer_data();

		$this->assertSame( 'Ada Lovelace', $data['name'] );
		$this->assertSame( 'billing@example.com', $data['email'] );
		$this->assertSame( 'RO', $data['billing_country'] );
		$this->assertNull( $data['address'] );
	}

	/**
	 * @testdox Logged-in shoppers should use mode-aware user storage for WooPayments customer IDs.
	 */
	public function test_get_or_create_customer_id_uses_mode_aware_user_storage_for_logged_in_customers(): void {
		$user_id    = $this->factory->user->create( array( 'user_login' => 'merchant' ) );
		$order      = $this->create_checkout_order( $user_id );
		$api_client = $this->create_customer_api_client( array( 'cus_live', 'cus_test' ) );

		$live_sut = $this->create_sut( false, $api_client );
		$this->assertSame( 'cus_live', $live_sut->get_or_create_customer_id_for_order( $order ) );
		$this->assertSame( 'cus_live', get_user_option( '_wcpay_customer_id_live', $user_id ) );

		delete_user_option( $user_id, '_wcpay_customer_id_live' );

		$test_sut = $this->create_sut( true, $api_client );
		$this->assertSame( 'cus_test', $test_sut->get_or_create_customer_id_for_order( $order ) );
		$this->assertSame( 'cus_test', get_user_option( '_wcpay_customer_id_test', $user_id ) );
	}

	/**
	 * @testdox Read-only customer evidence should not migrate or delete persisted user options.
	 */
	public function test_get_persisted_customer_id_by_user_id_does_not_mutate_deprecated_storage(): void {
		$user_id = $this->factory->user->create( array( 'user_login' => 'read-only-customer-evidence' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION, 'cus_deprecated' );

		$sut = $this->create_sut( false, $this->create_customer_api_client( array() ) );

		$this->assertSame( 'cus_deprecated', $sut->get_persisted_customer_id_by_user_id( $user_id ) );
		$this->assertSame(
			'cus_deprecated',
			get_user_option( WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION, $user_id ),
			'Read-only evidence must preserve the deprecated customer option in place.'
		);
		$this->assertFalse(
			get_user_option( WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, $user_id ),
			'Read-only evidence must not create the mode-aware option.'
		);
	}

	/**
	 * @testdox Legacy customer IDs should migrate to the live key when the account is live, even in test mode.
	 */
	public function test_migration_targets_live_key_when_account_is_live_even_in_test_mode(): void {
		$user_id = $this->factory->user->create( array( 'user_login' => 'legacy-live-account' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION, 'cus_legacy' );

		$sut = $this->create_sut( true, $this->create_customer_api_client( array() ), true );

		$this->assertNull( $sut->get_customer_id_by_user_id( $user_id ), 'A live-account legacy ID must not resolve as the test-mode customer.' );

		$this->assertSame( 'cus_legacy', get_user_option( WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, $user_id ), 'A live account\'s legacy customer must land under the live key regardless of the store mode.' );
		$this->assertFalse( get_user_option( WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, $user_id ) );
		$this->assertFalse( get_user_option( WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION, $user_id ) );
	}

	/**
	 * @testdox Legacy customer IDs on a live account in live mode should migrate and resolve immediately.
	 */
	public function test_migration_resolves_live_key_on_live_account_in_live_mode(): void {
		$user_id = $this->factory->user->create( array( 'user_login' => 'legacy-live-live' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION, 'cus_legacy' );

		$sut = $this->create_sut( false, $this->create_customer_api_client( array() ), true );

		$this->assertSame( 'cus_legacy', $sut->get_customer_id_by_user_id( $user_id ) );
		$this->assertSame( 'cus_legacy', get_user_option( WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, $user_id ) );
		$this->assertFalse( get_user_option( WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION, $user_id ) );
	}

	/**
	 * @testdox Legacy customer IDs should migrate to the test key when the account is not live.
	 */
	public function test_migration_targets_test_key_when_account_is_not_live(): void {
		$user_id = $this->factory->user->create( array( 'user_login' => 'legacy-test-account' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION, 'cus_legacy' );

		$sut = $this->create_sut( true, $this->create_customer_api_client( array() ), false );

		$this->assertSame( 'cus_legacy', $sut->get_customer_id_by_user_id( $user_id ) );
		$this->assertSame( 'cus_legacy', get_user_option( WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, $user_id ) );
		$this->assertFalse( get_user_option( WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, $user_id ) );
		$this->assertFalse( get_user_option( WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION, $user_id ) );

		$other_user_id = $this->factory->user->create( array( 'user_login' => 'legacy-test-account-live-mode' ) );
		update_user_option( $other_user_id, WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION, 'cus_legacy' );

		$live_mode_sut = $this->create_sut( false, $this->create_customer_api_client( array() ), false );

		$this->assertNull( $live_mode_sut->get_customer_id_by_user_id( $other_user_id ), 'A test-account legacy ID must not resolve as the live-mode customer.' );
		$this->assertSame( 'cus_legacy', get_user_option( WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, $other_user_id ) );
		$this->assertFalse( get_user_option( WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION, $other_user_id ) );
	}

	/**
	 * @testdox Legacy customer IDs should default to the live key when the account liveness is unknown.
	 */
	public function test_migration_defaults_to_live_key_when_account_liveness_is_unknown(): void {
		$user_id = $this->factory->user->create( array( 'user_login' => 'legacy-unknown-account' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION, 'cus_legacy' );

		$sut = $this->create_sut( false, $this->create_customer_api_client( array() ), null );

		$this->assertSame( 'cus_legacy', $sut->get_customer_id_by_user_id( $user_id ), 'Unknown account liveness must be treated as live to avoid losing live customer data.' );
		$this->assertSame( 'cus_legacy', get_user_option( WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, $user_id ) );
		$this->assertFalse( get_user_option( WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, $user_id ) );
		$this->assertFalse( get_user_option( WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION, $user_id ) );
	}

	/**
	 * @testdox The legacy customer option should be kept when the migrated write cannot be confirmed.
	 */
	public function test_migration_keeps_legacy_option_when_the_write_fails(): void {
		$user_id = $this->factory->user->create( array( 'user_login' => 'legacy-write-failure' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION, 'cus_legacy' );
		update_user_option( $user_id, WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, 'cus_legacy' );

		$sut = $this->create_sut( true, $this->create_customer_api_client( array() ), true );

		$this->assertNull( $sut->get_customer_id_by_user_id( $user_id ) );
		$this->assertSame( 'cus_legacy', get_user_option( WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION, $user_id ), 'The legacy option must survive when the migrated write is not confirmed.' );
	}

	/**
	 * @testdox Customers created from a user should carry the billing identity and shipping address.
	 */
	public function test_customer_created_for_user_uses_billing_identity_and_shipping(): void {
		$user_id = $this->factory->user->create(
			array(
				'first_name' => 'Account',
				'last_name'  => 'Identity',
				'user_email' => 'account@example.com',
				'user_login' => 'apm-billing-identity',
			)
		);
		update_user_meta( $user_id, 'billing_first_name', 'Billing' );
		update_user_meta( $user_id, 'billing_last_name', 'Person' );
		update_user_meta( $user_id, 'billing_email', 'billing@example.com' );
		update_user_meta( $user_id, 'billing_phone', '+40123456789' );
		update_user_meta( $user_id, 'billing_address_1', 'Strada Exemplu 1' );
		update_user_meta( $user_id, 'billing_postcode', '010101' );
		update_user_meta( $user_id, 'billing_city', 'Bucharest' );
		update_user_meta( $user_id, 'billing_country', 'RO' );
		update_user_meta( $user_id, 'shipping_first_name', 'Ship' );
		update_user_meta( $user_id, 'shipping_last_name', 'Person' );
		update_user_meta( $user_id, 'shipping_address_1', 'Strada Livrare 2' );
		update_user_meta( $user_id, 'shipping_postcode', '020202' );
		update_user_meta( $user_id, 'shipping_city', 'Cluj-Napoca' );
		update_user_meta( $user_id, 'shipping_country', 'RO' );

		$api_client = $this->create_customer_api_client( array( 'cus_apm' ) );
		$sut        = $this->create_sut( false, $api_client );

		$this->assertSame( 'cus_apm', $sut->get_or_create_customer_id_for_user( $user_id ) );

		$payload = $api_client->created_customers[0];
		$this->assertSame( 'Billing Person', $payload['name'], 'The provider customer must carry the billing name, not the account name.' );
		$this->assertSame( 'billing@example.com', $payload['email'], 'The provider customer must carry the billing email, not the account email.' );
		$this->assertSame( '+40123456789', $payload['phone'] );
		$this->assertSame( 'Strada Exemplu 1', $payload['address']['line1'] );
		$this->assertSame(
			array(
				'name'    => 'Ship Person',
				'address' => array(
					'line1'       => 'Strada Livrare 2',
					'line2'       => '',
					'postal_code' => '020202',
					'city'        => 'Cluj-Napoca',
					'state'       => '',
					'country'     => 'RO',
				),
			),
			$payload['shipping'],
			'A shipping block must be sent when the customer has a shipping postcode.'
		);
	}

	/**
	 * @testdox Customers created from a user without a shipping postcode should omit the shipping block.
	 */
	public function test_customer_created_for_user_without_shipping_postcode_omits_shipping(): void {
		$user_id = $this->factory->user->create( array( 'user_login' => 'apm-no-shipping' ) );
		update_user_meta( $user_id, 'billing_first_name', 'Billing' );
		update_user_meta( $user_id, 'billing_last_name', 'Person' );

		$api_client = $this->create_customer_api_client( array( 'cus_apm_ns' ) );
		$sut        = $this->create_sut( false, $api_client );
		$sut->get_or_create_customer_id_for_user( $user_id );

		$this->assertArrayNotHasKey( 'shipping', $api_client->created_customers[0] );
	}

	/**
	 * @testdox Customer IDs should persist network-wide when network saved cards are enabled.
	 */
	public function test_customer_id_persists_network_wide_under_network_saved_cards(): void {
		add_filter( 'wcpay_force_network_saved_cards', '__return_true' );

		$user_id    = $this->factory->user->create( array( 'user_login' => 'network-saved-cards' ) );
		$api_client = $this->create_customer_api_client( array( 'cus_network' ) );
		$sut        = $this->create_sut( false, $api_client );

		$this->assertSame( 'cus_network', $sut->get_or_create_customer_id_for_user( $user_id ) );

		global $wpdb;
		$this->assertSame( 'cus_network', get_user_meta( $user_id, WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, true ), 'Under network saved cards the customer ID must live under the unprefixed (network-wide) key.' );
		$this->assertSame( '', get_user_meta( $user_id, $wpdb->get_blog_prefix() . WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, true ), 'No per-site copy should be written in network mode.' );
		$this->assertSame( 'cus_network', $sut->get_customer_id_by_user_id( $user_id ), 'Reads must resolve the network-wide value through the get_user_option fallback.' );
	}

	/**
	 * @testdox Erasing personal data should also delete network-scoped customer IDs.
	 */
	public function test_erase_customer_data_deletes_network_scoped_customer_ids(): void {
		$user_id = $this->factory->user->create( array( 'user_email' => 'erase-network@example.com' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, 'cus_network', true );

		$sut    = $this->create_sut( false, $this->create_customer_api_client( array() ) );
		$result = $sut->erase_customer_data( 'erase-network@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( '', get_user_meta( $user_id, WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, true ), 'Erasure must remove the network-wide copy as well.' );
	}

	/**
	 * @testdox Guest shoppers should use session storage for WooPayments customer IDs.
	 */
	public function test_get_or_create_customer_id_uses_session_storage_for_guests(): void {
		$order      = $this->create_checkout_order();
		$api_client = $this->create_customer_api_client( array( 'cus_guest' ) );

		$sut = $this->create_sut( false, $api_client );

		$this->assertSame( 'cus_guest', $sut->get_or_create_customer_id_for_order( $order ) );
		$this->assertSame( 'cus_guest', WC()->session ? WC()->session->get( 'wcpay_customer_id' ) : null );
		$this->assertSame( 'cus_guest', $sut->get_or_create_customer_id_for_order( $order ) );
	}

	/**
	 * @testdox Orders with WooPayments customer meta should reuse that customer before user storage.
	 */
	public function test_get_or_create_customer_id_reuses_order_customer_meta_before_user_storage(): void {
		$user_id    = $this->factory->user->create( array( 'user_login' => 'renewal-customer' ) );
		$order      = $this->create_checkout_order( $user_id );
		$api_client = $this->create_customer_api_client( array( 'cus_new' ) );

		update_user_option( $user_id, '_wcpay_customer_id_live', 'cus_user' );
		$order->update_meta_data( '_stripe_customer_id', 'cus_order' );
		$order->save();

		$sut = $this->create_sut( false, $api_client );

		$this->assertSame( 'cus_order', $sut->get_or_create_customer_id_for_order( $order ) );
		$this->assertSame( 'cus_order', get_user_option( '_wcpay_customer_id_live', $user_id ) );
	}

	/**
	 * @testdox Changing a subscription's payment method should not overwrite the provider customer.
	 */
	public function test_customer_update_is_skipped_when_changing_subscription_payment_method(): void {
		$user_id    = $this->factory->user->create( array( 'user_login' => 'pm-change-customer' ) );
		$order      = $this->create_checkout_order( $user_id );
		$api_client = $this->create_customer_api_client( array( 'cus_new' ) );

		$order->update_meta_data( '_stripe_customer_id', 'cus_subscription' );
		$order->save();

		$this->fake_wcs_is_subscription();
		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id(), (string) $order->get_id() );
		$_GET['change_payment_method']          = (string) $order->get_id();

		$sut = $this->create_sut( false, $api_client );

		$this->assertSame( 'cus_subscription', $sut->get_or_create_customer_id_for_order( $order ) );
		$this->assertSame( array(), $api_client->updated_customers, 'A payment-method change must not push the subscription\'s stale billing to the provider customer.' );
	}

	/**
	 * @testdox A user-persisted customer should also be left untouched on a subscription payment-method change.
	 */
	public function test_user_persisted_customer_update_is_skipped_when_changing_subscription_payment_method(): void {
		$user_id    = $this->factory->user->create( array( 'user_login' => 'pm-change-user-customer' ) );
		$order      = $this->create_checkout_order( $user_id );
		$api_client = $this->create_customer_api_client( array( 'cus_new' ) );

		update_user_option( $user_id, '_wcpay_customer_id_live', 'cus_user' );

		$this->fake_wcs_is_subscription();
		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id(), (string) $order->get_id() );
		$_GET['change_payment_method']          = (string) $order->get_id();

		$sut = $this->create_sut( false, $api_client );

		$this->assertSame( 'cus_user', $sut->get_or_create_customer_id_for_order( $order ) );
		$this->assertSame( array(), $api_client->updated_customers );
	}

	/**
	 * @testdox A change_payment_method parameter that is not a subscription should still update the customer.
	 */
	public function test_customer_update_still_runs_when_change_payment_method_is_not_a_subscription(): void {
		$user_id    = $this->factory->user->create( array( 'user_login' => 'pm-change-non-subscription' ) );
		$order      = $this->create_checkout_order( $user_id );
		$api_client = $this->create_customer_api_client( array( 'cus_new' ) );

		$order->update_meta_data( '_stripe_customer_id', 'cus_subscription' );
		$order->save();

		$this->fake_wcs_is_subscription();
		$GLOBALS['wcpay_test_subscription_ids'] = array();
		$_GET['change_payment_method']          = (string) $order->get_id();

		$sut = $this->create_sut( false, $api_client );

		$this->assertSame( 'cus_subscription', $sut->get_or_create_customer_id_for_order( $order ) );
		$this->assertCount( 1, $api_client->updated_customers, 'Only a verified subscription change may skip the customer update.' );
	}

	/**
	 * @testdox Without lock contention and no stored ID, the customer is created once and persisted.
	 */
	public function test_get_or_create_customer_id_creates_once_without_lock_contention(): void {
		$user_id    = $this->factory->user->create( array( 'user_login' => 'first-checkout' ) );
		$order      = $this->create_checkout_order( $user_id );
		$api_client = $this->create_customer_api_client( array( 'cus_created' ) );

		$sut = $this->create_sut( false, $api_client );

		$result = $sut->get_or_create_customer_id_for_order( $order );

		$this->assertSame( 'cus_created', $result );
		$this->assertCount( 1, $api_client->created_customers );
		$this->assertSame( 'cus_created', get_user_option( '_wcpay_customer_id_live', $user_id ) );
		// The advisory lock is released once creation completes.
		$this->assertFalse( wp_cache_get( 'wcpay_customer_create_' . $user_id, 'woopayments' ) );
	}

	/**
	 * @testdox Losing the creation lock should reuse the concurrently created customer instead of creating a duplicate.
	 */
	public function test_get_or_create_customer_id_reuses_customer_created_by_concurrent_request(): void {
		$user_id    = $this->factory->user->create( array( 'user_login' => 'concurrent' ) );
		$order      = $this->create_checkout_order( $user_id );
		$api_client = $this->create_customer_api_client( array( 'cus_should_not_be_created' ) );

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( false );

		$sut = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->onlyMethods( array( 'get_customer_id_by_user_id' ) )
			->getMock();
		$sut->init( $api_client, $account_service, new WooPaymentsSessionService() );

		// The fast-path read misses; the re-read after losing the lock finds the
		// ID that the winning concurrent request just persisted.
		$sut->method( 'get_customer_id_by_user_id' )
			->willReturnOnConsecutiveCalls( null, 'cus_existing' );

		// Simulate another concurrent request already holding the creation lock.
		wp_cache_add( 'wcpay_customer_create_' . $user_id, 1, 'woopayments', 10 );

		$result = $sut->get_or_create_customer_id_for_order( $order );

		$this->assertSame( 'cus_existing', $result );
		$this->assertSame( array(), $api_client->created_customers );

		wp_cache_delete( 'wcpay_customer_create_' . $user_id, 'woopayments' );
	}

	/**
	 * @testdox Losing the lock while the ID is still unstored still creates and must not release the lock owner's key.
	 */
	public function test_get_or_create_customer_id_creates_on_fall_through_without_releasing_others_lock(): void {
		$user_id    = $this->factory->user->create( array( 'user_login' => 'fall-through' ) );
		$order      = $this->create_checkout_order( $user_id );
		$api_client = $this->create_customer_api_client( array( 'cus_fallthrough' ) );

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( false );

		$sut = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->onlyMethods( array( 'get_customer_id_by_user_id' ) )
			->getMock();
		$sut->init( $api_client, $account_service, new WooPaymentsSessionService() );

		// Both the fast-path read and the re-read after losing the lock miss: the
		// lock holder has not persisted the ID yet, so this request must create.
		$sut->method( 'get_customer_id_by_user_id' )->willReturn( null );

		// Simulate another concurrent request already holding the creation lock.
		wp_cache_add( 'wcpay_customer_create_' . $user_id, 1, 'woopayments', 10 );

		$result = $sut->get_or_create_customer_id_for_order( $order );

		// No regression: the customer is still created and persisted.
		$this->assertSame( 'cus_fallthrough', $result );
		$this->assertCount( 1, $api_client->created_customers );

		// Ownership: this request never acquired the lock, so it must not have
		// deleted the lock owner's key on the way out.
		$this->assertNotFalse( wp_cache_get( 'wcpay_customer_create_' . $user_id, 'woopayments' ) );

		wp_cache_delete( 'wcpay_customer_create_' . $user_id, 'woopayments' );
	}

	/**
	 * @testdox Recreating a missing customer should replace the persisted customer ID.
	 */
	public function test_recreate_customer_replaces_a_missing_customer_id_and_updates_storage(): void {
		$user_id = $this->factory->user->create( array( 'user_login' => 'recreate-me' ) );
		$order   = $this->create_checkout_order( $user_id );

		update_user_option( $user_id, '_wcpay_customer_id_live', 'cus_old' );

		$api_client = $this->create_customer_api_client( array( 'cus_new' ) );

		$sut = $this->create_sut( false, $api_client );

		$this->assertSame( 'cus_new', $sut->recreate_customer_for_order( $order ) );
		$this->assertSame( 'cus_new', get_user_option( '_wcpay_customer_id_live', $user_id ) );
	}

	/**
	 * @testdox Should update the customer's default WooPayments payment method.
	 */
	public function test_set_default_payment_method_for_customer_updates_invoice_settings(): void {
		$api_client = $this->create_customer_api_client( array() );
		$sut        = $this->create_sut( false, $api_client );

		$sut->set_default_payment_method_for_customer( 'cus_test', 'pm_default' );

		$this->assertSame( 'cus_test', $api_client->updated_customers[0]['customer_id'] );
		$this->assertSame( 'pm_default', $api_client->updated_customers[0]['customer_data']['invoice_settings']['default_payment_method'] );
	}

	/**
	 * @testdox Should retrieve payment methods for a customer through the API client.
	 */
	public function test_get_payment_methods_for_customer_delegates_to_api_client(): void {
		$api_client                          = $this->create_customer_api_client( array() );
		$api_client->payment_methods_by_type = array(
			'card' => array(
				array(
					'id'   => 'pm_card',
					'type' => 'card',
				),
			),
		);
		$sut                                 = $this->create_sut( false, $api_client );
		$result                              = $sut->get_payment_methods_for_customer( 'cus_test', 'card' );

		$this->assertSame( 'pm_card', $result[0]['id'] );
		$this->assertSame(
			array(
				array(
					'customer_id' => 'cus_test',
					'type'        => 'card',
					'limit'       => 100,
				),
			),
			$api_client->payment_methods_requests
		);
	}

	/**
	 * @testdox Should return no payment methods for an empty customer ID.
	 */
	public function test_get_payment_methods_for_customer_returns_empty_for_missing_customer(): void {
		$api_client = $this->create_customer_api_client( array() );
		$sut        = $this->create_sut( false, $api_client );

		$result = $sut->get_payment_methods_for_customer( '', 'card' );

		$this->assertSame( array(), $result );
		$this->assertSame( array(), $api_client->payment_methods_requests );
	}

	/**
	 * @testdox Should return no payment methods when the remote customer is missing.
	 */
	public function test_get_payment_methods_for_customer_returns_empty_for_missing_remote_customer(): void {
		$api_client                            = $this->create_customer_api_client( array() );
		$api_client->payment_methods_exception = new WooPaymentsApiException( 'Missing customer.', 'resource_missing', 404 );
		$sut                                   = $this->create_sut( false, $api_client );

		$result = $sut->get_payment_methods_for_customer( 'cus_missing', 'card' );

		$this->assertSame( array(), $result );
		$this->assertSame( 'cus_missing', $api_client->payment_methods_requests[0]['customer_id'] );
	}

	/**
	 * @testdox Should rethrow payment method API errors other than missing remote customers.
	 */
	public function test_get_payment_methods_for_customer_rethrows_unhandled_api_errors(): void {
		$api_client                            = $this->create_customer_api_client( array() );
		$api_client->payment_methods_exception = new WooPaymentsApiException( 'Forbidden.', 'wcpay_forbidden', 403 );
		$sut                                   = $this->create_sut( false, $api_client );

		$this->expectException( WooPaymentsApiException::class );
		$this->expectExceptionMessage( 'Forbidden.' );

		$sut->get_payment_methods_for_customer( 'cus_test', 'card' );
	}

	/**
	 * @testdox Registering hooks should add the WooPayments customer-data eraser to the GDPR registry.
	 */
	public function test_register_adds_personal_data_eraser(): void {
		$sut = $this->create_sut( false, $this->create_customer_api_client( array() ) );

		$sut->register();

		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_erasers', array( $sut, 'register_personal_data_eraser' ) ) );

		$erasers = $sut->register_personal_data_eraser( array() );
		$this->assertArrayHasKey( 'woocommerce-payments-customer', $erasers );
		$this->assertSame( array( $sut, 'erase_customer_data' ), $erasers['woocommerce-payments-customer']['callback'] );
	}

	/**
	 * @testdox Erasing personal data should delete all stored WooPayments customer IDs for the user.
	 */
	public function test_erase_customer_data_deletes_stored_customer_ids(): void {
		$user_id = $this->factory->user->create( array( 'user_email' => 'erase-me@example.com' ) );

		update_user_option( $user_id, '_wcpay_customer_id', 'cus_deprecated' );
		update_user_option( $user_id, '_wcpay_customer_id_live', 'cus_live' );
		update_user_option( $user_id, '_wcpay_customer_id_test', 'cus_test' );

		$sut = $this->create_sut( false, $this->create_customer_api_client( array() ) );

		$result = $sut->erase_customer_data( 'erase-me@example.com' );

		$this->assertFalse( get_user_option( '_wcpay_customer_id', $user_id ) );
		$this->assertFalse( get_user_option( '_wcpay_customer_id_live', $user_id ) );
		$this->assertFalse( get_user_option( '_wcpay_customer_id_test', $user_id ) );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( array(), $result['messages'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * @testdox Erasing personal data for an unknown email should return the done shape without errors.
	 */
	public function test_erase_customer_data_for_unknown_email_reports_nothing_removed(): void {
		$sut = $this->create_sut( false, $this->create_customer_api_client( array() ) );

		$result = $sut->erase_customer_data( 'nobody@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( array(), $result['messages'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * @testdox Erasing personal data for a user without any stored IDs should report nothing removed.
	 */
	public function test_erase_customer_data_without_stored_ids_reports_nothing_removed(): void {
		$user_id = $this->factory->user->create( array( 'user_email' => 'clean@example.com' ) );
		unset( $user_id );

		$sut = $this->create_sut( false, $this->create_customer_api_client( array() ) );

		$result = $sut->erase_customer_data( 'clean@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * @testdox Customer create payloads carry the shopper's Sift session ID; the platform links the browsing session for fraud scoring.
	 */
	public function test_customer_create_payload_carries_sift_session_id(): void {
		update_option( 'wcpay_session_store_id', 'st_fixture' );
		WC()->initialize_session();

		$order = wc_create_order();
		$order->set_billing_email( 'shopper@example.com' );
		$order->save();

		$api_client = $this->create_customer_api_client( array( 'cus_new' ) );
		$sut        = $this->create_sut( false, $api_client );

		$sut->get_or_create_customer_id_for_order( $order );

		$this->assertCount( 1, $api_client->created_customers );
		$this->assertSame(
			'st_fixture_' . (string) WC()->session->get_customer_id(),
			$api_client->created_customers[0]['session_id']
		);
	}

	/**
	 * Define a test-only wcs_is_checkout_blocks_api_request() backed by \$GLOBALS['wcpay_test_checkout_blocks_api_request'].
	 */
	private function fake_wcs_checkout_blocks_api_request(): void {
		if ( function_exists( 'wcs_is_checkout_blocks_api_request' ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only shim for the WooCommerce Subscriptions predicate.
		eval( 'namespace { function wcs_is_checkout_blocks_api_request( $request = "" ) { return ! empty( $GLOBALS["wcpay_test_checkout_blocks_api_request"] ); } }' );
	}

	/**
	 * Create a bare account-service stub.
	 *
	 * @param bool $test_mode Whether test mode is enabled.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service_stub( bool $test_mode ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'get_account_is_live' ) )
			->getMock();

		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_account_is_live' )->willReturn( null );

		return $account_service;
	}

	/**
	 * Define a test-only wcs_is_subscription() backed by \$GLOBALS['wcpay_test_subscription_ids'].
	 */
	private function fake_wcs_is_subscription(): void {
		if ( function_exists( 'wcs_is_subscription' ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only shim for the WooCommerce Subscriptions predicate.
		eval( 'namespace { function wcs_is_subscription( $subscription_id ) { $subscription_id = is_object( $subscription_id ) && method_exists( $subscription_id, "get_id" ) ? $subscription_id->get_id() : $subscription_id; return in_array( $subscription_id, $GLOBALS["wcpay_test_subscription_ids"] ?? array(), true ) || in_array( absint( $subscription_id ), $GLOBALS["wcpay_test_subscription_ids"] ?? array(), true ); } }' );
	}

	/**
	 * Create a customer service System Under Test.
	 *
	 * @param bool                 $test_mode       Whether test mode is enabled.
	 * @param WooPaymentsApiClient $api_client      Native API client mock.
	 * @param bool|null            $account_is_live Account liveness: true live, false test, null unknown.
	 * @return WooPaymentsCustomerService
	 */
	private function create_sut( bool $test_mode, WooPaymentsApiClient $api_client, ?bool $account_is_live = null ): WooPaymentsCustomerService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'get_account_is_live' ) )
			->getMock();

		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_account_is_live' )->willReturn( $account_is_live );

		$sut = new WooPaymentsCustomerService();
		$sut->init( $api_client, $account_service, new WooPaymentsSessionService(), new StaticNativeRuntimeArbiter( true ) );

		return $sut;
	}

	/**
	 * Create a concrete API-client double for customer creation.
	 *
	 * @param string[] $customer_ids Customer IDs to return in order.
	 * @return WooPaymentsApiClient
	 */
	private function create_customer_api_client( array $customer_ids ): WooPaymentsApiClient {
		return new class( $customer_ids ) extends WooPaymentsApiClient {
			/**
			 * Customer IDs to return.
			 *
			 * @var string[]
			 */
			private array $customer_ids;

			/**
			 * Updated customer payloads.
			 *
			 * @var array<int,array{customer_id:string,customer_data:array<string,mixed>}>
			 */
			public array $updated_customers = array();

			/**
			 * Created customer payloads.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $created_customers = array();

			/**
			 * Payment methods keyed by type.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $payment_methods_by_type = array();

			/**
			 * Payment method list requests.
			 *
			 * @var array<int,array{customer_id:string,type:string,limit:int}>
			 */
			public array $payment_methods_requests = array();

			/**
			 * Optional exception thrown by payment method list requests.
			 *
			 * @var WooPaymentsApiException|null
			 */
			public ?WooPaymentsApiException $payment_methods_exception = null;

			/**
			 * Constructor.
			 *
			 * @param string[] $customer_ids Customer IDs to return.
			 */
			public function __construct( array $customer_ids ) {
				$this->customer_ids = $customer_ids;
			}

			/**
			 * Create a customer.
			 *
			 * @param array<string,mixed> $customer_data Customer data.
			 * @return string
			 */
			public function create_customer( array $customer_data ): string {
				$this->created_customers[] = $customer_data;

				return (string) array_shift( $this->customer_ids );
			}

			/**
			 * Update a customer.
			 *
			 * @param string              $customer_id Customer ID.
			 * @param array<string,mixed> $customer_data Customer data.
			 */
			public function update_customer( string $customer_id, array $customer_data = array() ): void {
				$this->updated_customers[] = array(
					'customer_id'   => $customer_id,
					'customer_data' => $customer_data,
				);
			}

			/**
			 * Retrieve customer payment methods.
			 *
			 * @param string $customer_id Customer ID.
			 * @param string $type        Payment method type.
			 * @param int    $limit       Result limit.
			 * @return array<string,mixed>
			 * @throws WooPaymentsApiException When configured.
			 */
			public function get_payment_methods( string $customer_id, string $type, int $limit = 100 ): array {
				$this->payment_methods_requests[] = array(
					'customer_id' => $customer_id,
					'type'        => $type,
					'limit'       => $limit,
				);

				if ( null !== $this->payment_methods_exception ) {
					throw $this->payment_methods_exception;
				}

				return array(
					'data' => $this->payment_methods_by_type[ $type ] ?? array(),
				);
			}
		};
	}

	/**
	 * Create a checkout order fixture.
	 *
	 * @param int $customer_id Customer ID.
	 * @return WC_Order
	 */
	private function create_checkout_order( int $customer_id = 0 ): WC_Order {
		$order = wc_create_order();
		$order->set_customer_id( $customer_id );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_email( 'ada@example.com' );
		$order->set_billing_phone( '+40123456789' );
		$order->set_billing_address_1( '1 Core St' );
		$order->set_billing_city( 'Bucharest' );
		$order->set_billing_postcode( '010101' );
		$order->set_billing_country( 'RO' );
		$order->set_shipping_first_name( 'Ada' );
		$order->set_shipping_last_name( 'Lovelace' );
		$order->set_shipping_address_1( '1 Core St' );
		$order->set_shipping_city( 'Bucharest' );
		$order->set_shipping_postcode( '010101' );
		$order->set_shipping_country( 'RO' );
		$order->save();

		return $order;
	}
}
