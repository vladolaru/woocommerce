<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAddressProvider;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use WC_Unit_Test_Case;
use WP_Error;

/**
 * Tests for the WooPaymentsAddressProvider class.
 */
class WooPaymentsAddressProviderTest extends WC_Unit_Test_Case {

	/**
	 * Providers created during tests.
	 *
	 * @var WooPaymentsAddressProvider[]
	 */
	private array $providers = array();

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->providers as $provider ) {
			$this->remove_provider_hooks( $provider );
		}

		delete_option( 'wcpay_address_autocomplete_jwt' );
		delete_option( 'woocommerce_payments_address_autocomplete_jwt' );

		parent::tearDown();
	}

	/**
	 * @testdox Should register the WooPayments address provider only when native owns the runtime and the account is eligible.
	 */
	public function test_registers_provider_only_when_native_owns_runtime_and_account_is_eligible(): void {
		$plugin_provider = $this->create_provider( false, true, true, false, false );
		$plugin_provider->register();

		$this->assertFalse( has_filter( 'woocommerce_address_providers', array( $plugin_provider, 'add_address_provider' ) ) );

		$native_provider = $this->create_provider( true, true, true, false, false );
		$native_provider->register();

		$providers = apply_filters( 'woocommerce_address_providers', array() );

		$this->assertSame( 10, has_filter( 'woocommerce_address_providers', array( $native_provider, 'add_address_provider' ) ) );
		$this->assertCount( 1, $providers );
		$this->assertSame( $native_provider, $providers[0] );
		$this->assertSame( 'woocommerce_payments', $providers[0]->id );
		$this->assertSame( 'WooCommerce Payments', $providers[0]->name );
	}

	/**
	 * @testdox Should not add the address provider when the gateway is disabled or the account is restricted.
	 *
	 * @dataProvider ineligible_account_provider
	 *
	 * @param bool   $gateway_enabled      Whether the gateway is enabled.
	 * @param bool   $account_rejected     Whether the account is rejected.
	 * @param bool   $account_under_review Whether the account is under review.
	 * @param string $message              Assertion message.
	 */
	public function test_does_not_add_provider_when_gateway_disabled_or_account_restricted( bool $gateway_enabled, bool $account_rejected, bool $account_under_review, string $message ): void {
		$provider = $this->create_provider( true, $gateway_enabled, true, $account_rejected, $account_under_review );
		$provider->register();

		$this->assertSame( array(), apply_filters( 'woocommerce_address_providers', array() ), $message );
	}

	/**
	 * @testdox Should fetch the address service JWT from the native API client.
	 */
	public function test_get_address_service_jwt_fetches_token_from_api_client(): void {
		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Number of token requests.
			 *
			 * @var int
			 */
			public int $request_count = 0;

			/**
			 * Get the address autocomplete token.
			 *
			 * @return array<string,string>
			 */
			public function get_address_autocomplete_token(): array {
				++$this->request_count;

				return array( 'token' => 'address.jwt.token' );
			}
		};

		$provider = $this->create_provider( true, true, true, false, false, $api_client );

		$this->assertSame( 'address.jwt.token', $provider->get_address_service_jwt() );
		$this->assertSame( 1, $api_client->request_count );
	}

	/**
	 * @testdox Should return an error and clear cached address JWT data when the account is not connected.
	 */
	public function test_get_address_service_jwt_returns_error_when_account_is_not_connected(): void {
		update_option( 'wcpay_address_autocomplete_jwt', 'cached-token' );

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Number of token requests.
			 *
			 * @var int
			 */
			public int $request_count = 0;

			/**
			 * Get the address autocomplete token.
			 *
			 * @return array<string,string>
			 */
			public function get_address_autocomplete_token(): array {
				++$this->request_count;

				return array( 'token' => 'address.jwt.token' );
			}
		};

		$provider = $this->create_provider( true, true, false, false, false, $api_client );
		$result   = $provider->get_address_service_jwt();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wcpay_address_service_error', $result->get_error_code() );
		$this->assertFalse( get_option( 'wcpay_address_autocomplete_jwt' ) );
		$this->assertSame( 0, $api_client->request_count );
	}

	/**
	 * Ineligible account scenarios.
	 *
	 * @return array<string,array{0:bool,1:bool,2:bool,3:string}>
	 */
	public function ineligible_account_provider(): array {
		return array(
			'gateway disabled' => array( false, false, false, 'Gateway-disabled accounts should not register the provider.' ),
			'rejected account' => array( true, true, false, 'Rejected accounts should not register the provider.' ),
			'under review'     => array( true, false, true, 'Under-review accounts should not register the provider.' ),
		);
	}

	/**
	 * Create the System Under Test.
	 *
	 * @param bool                      $native_register      Whether native should register.
	 * @param bool                      $gateway_enabled      Whether the gateway is enabled.
	 * @param bool                      $has_account          Whether the account is connected.
	 * @param bool                      $account_rejected     Whether the account is rejected.
	 * @param bool                      $account_under_review Whether the account is under review.
	 * @param WooPaymentsApiClient|null $api_client           Optional API client.
	 * @return WooPaymentsAddressProvider
	 */
	private function create_provider(
		bool $native_register,
		bool $gateway_enabled,
		bool $has_account,
		bool $account_rejected,
		bool $account_under_review,
		?WooPaymentsApiClient $api_client = null
	): WooPaymentsAddressProvider {
		$this->assertTrue( class_exists( WooPaymentsAddressProvider::class ), 'WooPaymentsAddressProvider should exist.' );

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_gateway_enabled', 'has_account', 'is_account_rejected', 'is_account_under_review' ) )
			->getMock();
		$account_service->method( 'is_gateway_enabled' )->willReturn( $gateway_enabled );
		$account_service->method( 'has_account' )->willReturn( $has_account );
		$account_service->method( 'is_account_rejected' )->willReturn( $account_rejected );
		$account_service->method( 'is_account_under_review' )->willReturn( $account_under_review );

		if ( null === $api_client ) {
			$api_client = new class() extends WooPaymentsApiClient {
				/**
				 * Get the address autocomplete token.
				 *
				 * @return array<string,string>
				 */
				public function get_address_autocomplete_token(): array {
					return array( 'token' => 'address.jwt.token' );
				}
			};
		}

		$provider = new WooPaymentsAddressProvider();
		$provider->init( new StaticNativeRuntimeArbiter( $native_register ), $api_client, $account_service );

		$this->providers[] = $provider;

		return $provider;
	}

	/**
	 * Remove hooks registered by a provider instance.
	 *
	 * @param WooPaymentsAddressProvider $provider Provider instance.
	 */
	private function remove_provider_hooks( WooPaymentsAddressProvider $provider ): void {
		remove_filter( 'woocommerce_address_providers', array( $provider, 'add_address_provider' ) );
		remove_filter( 'pre_update_option_woocommerce_address_autocomplete_enabled', array( $provider, 'refresh_cache' ) );
		remove_action( 'wp_enqueue_scripts', array( $provider, 'load_scripts' ) );
	}
}
