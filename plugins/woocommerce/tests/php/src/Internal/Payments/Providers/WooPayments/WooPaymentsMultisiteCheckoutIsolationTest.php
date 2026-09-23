<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\WooPaymentsCutoverState;
use Automattic\WooCommerce\Enums\PaymentGatewayFeature;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverStateStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Order;
use WC_Payment_Token;
use WC_Payment_Token_CC;
use WC_Unit_Test_Case;

/**
 * Tests native WooPayments checkout isolation across multisite blogs.
 */
class WooPaymentsMultisiteCheckoutIsolationTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Current-blog services should keep checkout identities isolated across blog switches.
	 * @group multisite
	 */
	public function test_current_blog_services_keep_checkout_identities_isolated(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$original_blog_id  = get_current_blog_id();
		$secondary_blog_id = self::factory()->blog->create();
		$user_id           = self::factory()->user->create();
		$primary           = array();

		$this->assertIsInt( $secondary_blog_id );
		$this->assertIsInt( $user_id );

		try {
			$this->assertSame( empty( getenv( 'DISABLE_HPOS' ) ), OrderUtil::custom_orders_table_usage_is_enabled(), 'The primary site should use the requested order store.' );
			$primary = $this->create_site_fixture( $user_id, 'primary', 'US', 11, '10.11' );

			$account_service  = wc_get_container()->get( WooPaymentsAccountService::class );
			$customer_service = wc_get_container()->get( WooPaymentsCustomerService::class );
			$token_service    = wc_get_container()->get( WooPaymentsTokenService::class );
			$runtime_arbiter  = wc_get_container()->get( NativePaymentsRuntimeArbiter::class );
			$cutover_store    = new WooPaymentsCutoverStateStore();
			$order_store      = new OrderPaymentStore();
			$profile          = new WooPaymentsPersistenceProfile();

			$this->assert_site_fixture(
				$primary,
				$user_id,
				$account_service,
				$customer_service,
				$token_service,
				$runtime_arbiter,
				$cutover_store,
				$order_store,
				$profile
			);

			switch_to_blog( $secondary_blog_id );
			$this->install_woocommerce_tables_for_current_site();
			OrderHelper::toggle_cot_feature_and_usage( empty( getenv( 'DISABLE_HPOS' ) ) );
			$this->assertSame( empty( getenv( 'DISABLE_HPOS' ) ), OrderUtil::custom_orders_table_usage_is_enabled(), 'The secondary site should use the requested order store.' );
			$secondary = $this->create_site_fixture( $user_id, 'secondary', 'GB', 22, '20.22' );

			$this->assert_site_fixture(
				$secondary,
				$user_id,
				$account_service,
				$customer_service,
				$token_service,
				$runtime_arbiter,
				$cutover_store,
				$order_store,
				$profile
			);

			restore_current_blog();
			$this->assertSame( $original_blog_id, get_current_blog_id() );
			$this->assert_site_fixture(
				$primary,
				$user_id,
				$account_service,
				$customer_service,
				$token_service,
				$runtime_arbiter,
				$cutover_store,
				$order_store,
				$profile
			);
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}

			if ( isset( $primary['order_id'] ) ) {
				$order = wc_get_order( $primary['order_id'] );
				if ( $order instanceof WC_Order ) {
					$order->delete( true );
				}
			}
			if ( isset( $primary['token_id'] ) ) {
				\WC_Payment_Tokens::delete( $primary['token_id'] );
			}
			$this->delete_site_fixture_options();
			wc_get_container()->reset_all_resolved();
			wpmu_delete_blog( $secondary_blog_id, true );
		}
	}

	/**
	 * @testdox One gateway instance should refresh site-derived checkout state after blog switches.
	 * @group multisite
	 */
	public function test_one_gateway_instance_refreshes_site_derived_checkout_state_after_blog_switches(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$original_blog_id    = get_current_blog_id();
		$secondary_blog_id   = self::factory()->blog->create();
		$user_id             = self::factory()->user->create();
		$primary_token_id    = 0;
		$settings_reads      = 0;
		$count_settings_read = static function ( $value ) use ( &$settings_reads ) {
			++$settings_reads;
			return $value;
		};

		$this->assertIsInt( $secondary_blog_id );
		$this->assertIsInt( $user_id );
		wp_set_current_user( $user_id );

		try {
			update_option( 'wcpay_account_data', $this->account_cache( 'primary', 'US' ) );
			update_option( 'woocommerce_currency', 'USD' );
			update_option(
				'woocommerce_woocommerce_payments_settings',
				array(
					'enabled'     => 'yes',
					'saved_cards' => 'yes',
				)
			);
			$primary_token_id = $this->create_card_token( $user_id, 'pm_primary' );
			$gateway          = new NativeWooPaymentsGateway();
			$provider         = $this->getMockBuilder( WooPaymentsProvider::class )
				->disableOriginalConstructor()
				->onlyMethods( array( 'can_process_payments' ) )
				->getMock();
			$provider->method( 'can_process_payments' )->willReturn( true );
			$provider_property = new \ReflectionProperty( NativeWooPaymentsGateway::class, 'provider' );
			$provider_property->setAccessible( true );
			$provider_property->setValue( $gateway, $provider );
			add_filter( 'wcpay_test_mode', '__return_true' );
			$gateway->supports[] = 'extension_checkout_feature';

			$this->assertTrue( $gateway->supports( 'extension_checkout_feature' ) );
			$this->assertTrue( $gateway->is_available() );
			$this->assertSame( 'yes', $gateway->get_option( 'saved_cards' ) );
			$this->assertSame( array( 'pm_primary' ), $this->get_payment_method_ids( $gateway ) );

			switch_to_blog( $secondary_blog_id );
			$this->install_woocommerce_tables_for_current_site();
			update_option( 'wcpay_account_data', $this->account_cache( 'secondary', 'GB' ) );
			update_option( 'woocommerce_currency', 'GBP' );
			update_option(
				'woocommerce_woocommerce_payments_settings',
				array(
					'enabled'     => 'no',
					'saved_cards' => 'no',
				)
			);
			$this->create_card_token( $user_id, 'pm_secondary' );
			add_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $count_settings_read );

			$this->assertFalse( $gateway->supports( PaymentGatewayFeature::TOKENIZATION ) );
			$this->assertTrue( $gateway->supports( 'extension_checkout_feature' ) );
			$this->assertFalse( $gateway->is_available() );
			$this->assertSame( array(), $this->get_payment_method_ids( $gateway ) );
			$this->assertSame( array(), $this->get_payment_method_ids( $gateway ) );
			$this->assertSame( 'no', $gateway->get_option( 'saved_cards' ) );
			$this->assertSame( 'no', $gateway->enabled );
			$this->assertSame( 1, $settings_reads, 'The gateway should reload settings once per blog transition.' );
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $count_settings_read );
			update_option(
				'woocommerce_woocommerce_payments_settings',
				array(
					'enabled'     => 'yes',
					'saved_cards' => 'no',
				)
			);
			$gateway->init_settings();
			$this->assertTrue( $gateway->is_available() );
			$this->assertSame( 'no', $gateway->get_option( 'saved_cards' ) );

			restore_current_blog();
			$this->assertSame( $original_blog_id, get_current_blog_id() );
			$this->assertTrue( $gateway->supports( PaymentGatewayFeature::TOKENIZATION ) );
			$this->assertTrue( $gateway->supports( 'extension_checkout_feature' ) );
			$this->assertTrue( $gateway->is_available() );
			add_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $count_settings_read );
			$this->assertSame( array( 'pm_primary' ), $this->get_payment_method_ids( $gateway ) );
			$this->assertSame( array( 'pm_primary' ), $this->get_payment_method_ids( $gateway ) );
			$this->assertSame( 'yes', $gateway->get_option( 'saved_cards' ) );
			$this->assertSame( 'yes', $gateway->enabled );
			$this->assertSame( 1, $settings_reads, 'Repeated calls within one blog should not reload settings.' );
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $count_settings_read );

			switch_to_blog( $secondary_blog_id );
			update_option(
				'woocommerce_woocommerce_payments_settings',
				array(
					'enabled'     => 'yes',
					'saved_cards' => 'yes',
				)
			);
			$this->assertSame( array( 'pm_secondary' ), $this->get_payment_method_ids( $gateway ) );
			$this->assertTrue( $gateway->supports( PaymentGatewayFeature::TOKENIZATION ) );
			restore_current_blog();
			$this->assertSame( array( 'pm_primary' ), $this->get_payment_method_ids( $gateway ) );

			$gateway->supports = array_values( array_diff( $gateway->supports, array( PaymentGatewayFeature::TOKENIZATION ) ) );
			switch_to_blog( $secondary_blog_id );
			$this->assertFalse( $gateway->supports( PaymentGatewayFeature::TOKENIZATION ), 'An extension-removed capability must stay removed after a blog switch.' );
			restore_current_blog();
			$this->assertFalse( $gateway->supports( PaymentGatewayFeature::TOKENIZATION ) );
		} finally {
			remove_filter( 'wcpay_test_mode', '__return_true' );
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $count_settings_read );
			while ( ms_is_switched() ) {
				restore_current_blog();
			}

			if ( 0 < $primary_token_id ) {
				\WC_Payment_Tokens::delete( $primary_token_id );
			}
			delete_option( 'woocommerce_woocommerce_payments_settings' );
			delete_option( 'wcpay_account_data' );
			delete_option( 'woocommerce_currency' );
			wp_set_current_user( 0 );
			wc_get_container()->reset_all_resolved();
			wpmu_delete_blog( $secondary_blog_id, true );
		}
	}

	/**
	 * @testdox One gateway instance should refresh country branding after blog switches.
	 * @group multisite
	 */
	public function test_one_gateway_instance_refreshes_country_branding_after_blog_switches(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$original_blog_id  = get_current_blog_id();
		$secondary_blog_id = self::factory()->blog->create();
		$definition        = ( new WooPaymentsPaymentMethodRegistry() )->get( 'afterpay_clearpay' );

		$this->assertIsInt( $secondary_blog_id );
		$this->assertNotNull( $definition );

		try {
			update_option( 'wcpay_account_data', $this->account_cache( 'primary', 'US' ) );
			$gateway = new NativeWooPaymentsGateway( $definition );
			$this->assertSame( 'Cash App Afterpay', $gateway->get_title() );
			$this->assertSame( 'WooPayments (Cash App Afterpay)', $gateway->get_method_title() );

			switch_to_blog( $secondary_blog_id );
			update_option( 'wcpay_account_data', $this->account_cache( 'secondary', 'GB' ) );
			$this->assertSame( 'Clearpay', $gateway->get_title() );
			$this->assertSame( 'WooPayments (Clearpay)', $gateway->get_method_title() );

			restore_current_blog();
			$this->assertSame( $original_blog_id, get_current_blog_id() );
			$this->assertSame( 'Cash App Afterpay', $gateway->get_title() );
			$this->assertSame( 'WooPayments (Cash App Afterpay)', $gateway->get_method_title() );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			delete_option( 'wcpay_account_data' );
			wc_get_container()->reset_all_resolved();
			wpmu_delete_blog( $secondary_blog_id, true );
		}
	}

	/**
	 * Create a site-local WooPayments card token.
	 *
	 * @param int    $user_id           User ID.
	 * @param string $payment_method_id Provider payment method ID.
	 * @return int
	 */
	private function create_card_token( int $user_id, string $payment_method_id ): int {
		$token = new WC_Payment_Token_CC();
		$token->set_gateway_id( OrderPaymentStore::GATEWAY_ID );
		$token->set_user_id( $user_id );
		$token->set_token( $payment_method_id );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );

		return $token->save();
	}

	/**
	 * Create one site's checkout identity fixture.
	 *
	 * @param int    $user_id    Network user ID.
	 * @param string $slug       Site identity slug.
	 * @param string $country    Account country.
	 * @param int    $generation Cutover generation.
	 * @param string $total      Order total.
	 * @return array<string,mixed>
	 */
	private function create_site_fixture( int $user_id, string $slug, string $country, int $generation, string $total ): array {
		update_option( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, 'primary' === $slug ? 'yes' : 'no' );
		delete_option( NativePaymentsRuntimeArbiter::NATIVE_RUNTIME_KILL_SWITCH_OPTION );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'     => 'yes',
				'saved_cards' => 'yes',
				'test_mode'   => 'no',
			)
		);
		update_option(
			'wcpay_account_data',
			$this->account_cache( $slug, $country )
		);
		update_user_option( $user_id, WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, 'cus_' . $slug, false );

		$cutover_store = new WooPaymentsCutoverStateStore();
		$cutover_store->save_record( $this->valid_cutover_record( $generation ) );

		$token_id = $this->create_card_token( $user_id, 'pm_' . $slug );
		$token    = \WC_Payment_Tokens::get( $token_id );
		$this->assertInstanceOf( WC_Payment_Token_CC::class, $token );

		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_customer_id( $user_id );
		$order->set_currency( 'primary' === $slug ? 'USD' : 'GBP' );
		$order->set_total( $total );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->set_transaction_id( 'txn_' . $slug );
		$order->update_meta_data( '_payment_method_id', 'pm_' . $slug );
		$order->update_meta_data( '_intent_id', 'pi_' . $slug );
		$order->update_meta_data( '_charge_id', 'ch_' . $slug );
		$order->add_payment_token( $token );
		$order->save();

		return array(
			'slug'       => $slug,
			'country'    => $country,
			'generation' => $generation,
			'total'      => $total,
			'token_id'   => $token_id,
			'order_id'   => $order->get_id(),
		);
	}

	/**
	 * Build a fresh persisted account cache wrapper.
	 *
	 * @param string $slug    Site identity slug.
	 * @param string $country Account country.
	 * @return array<string,mixed>
	 */
	private function account_cache( string $slug, string $country ): array {
		return array(
			'data'               => array(
				'account_id'        => 'acct_' . $slug,
				'country'           => $country,
				'is_live'           => true,
				'payments_enabled'  => true,
				'details_submitted' => true,
			),
			'fetched'            => time(),
			'errored'            => false,
			'consecutive_errors' => 0,
		);
	}

	/**
	 * Assert that shared services expose only the current site's fixture.
	 *
	 * @param array<string,mixed>           $fixture          Site fixture.
	 * @param int                           $user_id          Network user ID.
	 * @param WooPaymentsAccountService     $account_service Account service.
	 * @param WooPaymentsCustomerService    $customer_service Customer service.
	 * @param WooPaymentsTokenService       $token_service    Token service.
	 * @param NativePaymentsRuntimeArbiter  $runtime_arbiter Runtime arbiter.
	 * @param WooPaymentsCutoverStateStore  $cutover_store Cutover state store.
	 * @param OrderPaymentStore             $order_store      Order payment store.
	 * @param WooPaymentsPersistenceProfile $profile    Persistence profile.
	 */
	private function assert_site_fixture(
		array $fixture,
		int $user_id,
		WooPaymentsAccountService $account_service,
		WooPaymentsCustomerService $customer_service,
		WooPaymentsTokenService $token_service,
		NativePaymentsRuntimeArbiter $runtime_arbiter,
		WooPaymentsCutoverStateStore $cutover_store,
		OrderPaymentStore $order_store,
		WooPaymentsPersistenceProfile $profile
	): void {
		$slug = $fixture['slug'];

		$this->assertSame( 'primary' === $slug ? NativePaymentsRuntimeArbiter::OWNER_NATIVE : NativePaymentsRuntimeArbiter::OWNER_NONE, $runtime_arbiter->get_runtime_owner() );
		$this->assertSame( 'acct_' . $slug, $account_service->get_account_id() );
		$this->assertSame( $fixture['country'], $account_service->get_account_country() );
		$this->assertSame( 'live', $account_service->get_mode() );
		$this->assertSame( 'yes', $account_service->get_gateway_setting( 'saved_cards' ) );
		$this->assertSame( 'cus_' . $slug, $customer_service->get_customer_id_by_user_id( $user_id ) );
		$this->assertSame( $fixture['generation'], $cutover_store->get_record()['generation'] ?? null );
		$this->assertSame( 'pm_' . $slug, $token_service->resolve_payment_method_id_from_token_id( (string) $fixture['token_id'], $user_id ) );

		$order = wc_get_order( $fixture['order_id'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$surface = $order_store->read_payment_surface( $order, $profile );
		$this->assertSame( array( $fixture['token_id'] ), $order->get_payment_tokens() );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $surface['payment_method'] );
		$this->assertSame( 'txn_' . $slug, $surface['transaction_id'] );
		$this->assertSame( 'primary' === $slug ? 'USD' : 'GBP', $surface['currency'] );
		$this->assertSame( $fixture['total'], $surface['total'] );
		$this->assertSame( 'pm_' . $slug, $surface['meta']['_payment_method_id'] );
		$this->assertSame( 'pi_' . $slug, $surface['meta']['_intent_id'] );
		$this->assertSame( 'ch_' . $slug, $surface['meta']['_charge_id'] );
	}

	/**
	 * Build a complete site-local cutover record.
	 *
	 * @param int $generation Cutover generation.
	 * @return array<string,mixed>
	 */
	private function valid_cutover_record( int $generation ): array {
		return array(
			'schema_version'         => WooPaymentsCutoverStateStore::SCHEMA_VERSION,
			'generation'             => $generation,
			'revision'               => 1,
			'state'                  => WooPaymentsCutoverState::DEFERRED,
			'started_at'             => 1_700_000_000,
			'updated_at'             => 1_700_000_100,
			'attempt'                => 1,
			'action_id'              => 42,
			'current_step'           => 'deferred',
			'step_log'               => array(
				array(
					'step' => 'deferred',
					'at'   => 1_700_000_100,
				),
			),
			'deferred_codes'         => array( 'native_transport_unavailable' ),
			'informational_outcomes' => array(),
			'next_attempt_at'        => 1_700_001_000,
			'lease_token'            => null,
			'lease_expires_at'       => null,
		);
	}

	/**
	 * Delete the current site's fixture options.
	 */
	private function delete_site_fixture_options(): void {
		delete_option( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		delete_option( NativePaymentsRuntimeArbiter::NATIVE_RUNTIME_KILL_SWITCH_OPTION );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'wcpay_account_data' );
		delete_option( WooPaymentsCutoverStateStore::OPTION_NAME );
		delete_option( WooPaymentsCutoverStateStore::LEASE_OPTION_NAME );
	}

	/**
	 * Read provider payment method IDs from the gateway's current token cache.
	 *
	 * @param NativeWooPaymentsGateway $gateway Gateway instance.
	 * @return string[]
	 */
	private function get_payment_method_ids( NativeWooPaymentsGateway $gateway ): array {
		return array_values(
			array_map(
				static fn ( WC_Payment_Token $token ): string => $token->get_token(),
				$gateway->get_tokens()
			)
		);
	}

	/**
	 * Install WooCommerce's site-scoped tables for a multisite fixture.
	 */
	private function install_woocommerce_tables_for_current_site(): void {
		\WC_Install::create_tables();
		( new \ActionScheduler_StoreSchema() )->register_tables( true );
		( new \ActionScheduler_LoggerSchema() )->register_tables( true );
	}
}
