<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExtensionRuntime;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use RuntimeException;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsExtensionRuntime class.
 */
class WooPaymentsExtensionRuntimeTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should fail closed when the WooPayments runtime is absent.
	 */
	public function test_fails_closed_when_woopayments_runtime_is_absent(): void {
		$sut = $this->create_runtime( array() );

		$this->assertFalse( $sut->is_loaded() );
		$this->assertNull( $sut->get_gateway() );
		$this->assertNull( $sut->get_account_service() );
		$this->assertNull( $sut->get_payments_api_client() );
	}

	/**
	 * @testdox Should not report the WooPayments extension runtime as loaded when the built-in WooPayments owns payments.
	 */
	public function test_reports_not_loaded_when_builtin_owns_payments(): void {
		$arbiter = $this->createMock( WooPaymentsRuntimeArbiter::class );
		$arbiter->expects( $this->once() )
			->method( 'is_extension_owner' )
			->willReturn( false );

		$sut = $this->create_runtime( array( 'WC_Payments' ), $arbiter );

		$this->assertFalse( $sut->is_loaded() );
	}

	/**
	 * @testdox Should return WooPayments runtime services when available.
	 */
	public function test_returns_woopayments_runtime_services_when_available(): void {
		$gateway    = (object) array( 'id' => 'gateway' );
		$account    = (object) array( 'id' => 'account' );
		$api_client = (object) array( 'id' => 'api_client' );
		$this->register_legacy_proxy_static_mocks(
			array(
				'WC_Payments' => array(
					'get_gateway'             => fn() => $gateway,
					'get_account_service'     => fn() => $account,
					'get_payments_api_client' => fn() => $api_client,
				),
			)
		);
		$sut = $this->create_runtime();

		$this->assertTrue( $sut->is_loaded() );
		$this->assertSame( $gateway, $sut->get_gateway() );
		$this->assertSame( $account, $sut->get_account_service() );
		$this->assertSame( $api_client, $sut->get_payments_api_client() );
	}

	/**
	 * @testdox Should return the WooPayments account connect URL only when the account helpers are callable: $_dataName.
	 * @dataProvider provide_account_url_helpers
	 *
	 * @param string|null $connect_url Connect URL the extension's helper answers, or null when the helper is unavailable.
	 */
	public function test_returns_woopayments_account_urls_only_when_helpers_are_callable( ?string $connect_url ): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'is_callable' => fn( $target ) => null !== $connect_url && '\WC_Payments_Account::get_connect_url' === $target,
			)
		);
		$this->register_legacy_proxy_static_mocks(
			array(
				'WC_Payments_Account' => array(
					'get_connect_url' => fn() => 'https://example.com/connect',
				),
			)
		);
		$sut = $this->create_runtime();

		$this->assertSame( $connect_url, $sut->get_account_connect_url( 'native-payments' ) );
	}

	/**
	 * Provide the account URL helper states: connect URL or null.
	 *
	 * @return array<string,array{0:string|null}>
	 */
	public function provide_account_url_helpers(): array {
		return array(
			'helpers callable'    => array( 'https://example.com/connect' ),
			'helpers unavailable' => array( null ),
		);
	}

	/**
	 * @testdox Should return WooPayments mode flags when available.
	 */
	public function test_returns_woopayments_mode_flags_when_available(): void {
		$mode = new class() {
			/**
			 * Tell whether WooPayments is in test mode.
			 *
			 * @return bool
			 */
			public function is_test(): bool {
				return true;
			}

			/**
			 * Tell whether WooPayments is in dev mode.
			 *
			 * @return bool
			 */
			public function is_dev(): bool {
				return false;
			}

			/**
			 * Tell whether WooPayments is in test-mode onboarding.
			 *
			 * @return bool
			 */
			public function is_test_mode_onboarding(): bool {
				return true;
			}
		};
		$this->register_legacy_proxy_static_mocks(
			array(
				'WC_Payments' => array(
					'mode' => fn() => $mode,
				),
			)
		);
		$sut = $this->create_runtime();

		$this->assertTrue( $sut->is_test_mode() );
		$this->assertFalse( $sut->is_dev_mode() );
		$this->assertTrue( $sut->is_test_mode_onboarding() );
	}

	/**
	 * @testdox Should return account status data and supported countries when available.
	 */
	public function test_returns_account_status_data_and_supported_countries_when_available(): void {
		$account = new class() {
			/**
			 * Get account status data.
			 *
			 * @return array<string,mixed>
			 */
			public function get_account_status_data(): array {
				return array(
					'testDrive' => true,
					'isLive'    => false,
				);
			}
		};
		$this->register_legacy_proxy_function_mocks(
			array(
				'is_callable' => fn( $target ) => in_array( $target, array( 'WC_Payments_Utils::supported_countries', '\WC_Payments_Utils::supported_countries' ), true ) || is_callable( $target ),
			)
		);
		$this->register_legacy_proxy_static_mocks(
			array(
				'WC_Payments'       => array(
					'get_account_service' => fn() => $account,
				),
				'WC_Payments_Utils' => array(
					'supported_countries' => fn() => array(
						'US' => 'United States',
						'CA' => 'Canada',
					),
				),
			)
		);
		$sut = $this->create_runtime( array( 'WC_Payments', 'WC_Payments_Utils' ) );

		$this->assertSame(
			array(
				'testDrive' => true,
				'isLive'    => false,
			),
			$sut->get_account_status_data()
		);
		$this->assertSame(
			array(
				'US' => 'United States',
				'CA' => 'Canada',
			),
			$sut->get_supported_countries()
		);
	}

	/**
	 * @testdox Should report an onboarded account from the cached account data only when its details are submitted: $_dataName.
	 * @dataProvider provide_cached_account_onboarding
	 *
	 * @param mixed $account_data Account data payload.
	 * @param bool  $expected     Whether the account is onboarded.
	 */
	public function test_reports_onboarded_account_from_cache( $account_data, bool $expected ): void {
		$sut = $this->create_runtime_with_account_data( $account_data );

		$this->assertSame( $expected, $sut->is_account_onboarded_from_cache() );
	}

	/**
	 * Provide cached account payloads: payload, expected onboarded.
	 *
	 * @return array<string,array{0:mixed,1:bool}>
	 */
	public function provide_cached_account_onboarding(): array {
		$submitted = static fn( $details_submitted ): array => array(
			'data' => array(
				'account_id'        => 'acct_123',
				'details_submitted' => $details_submitted,
			),
		);

		return array(
			'no submitted details'            => array( array( 'data' => array( 'account_id' => 'acct_123' ) ), false ),
			'details submitted, boolean true' => array( $submitted( true ), true ),
			'details submitted, string true'  => array( $submitted( 'true' ), true ),
			'details submitted, string yes'   => array( $submitted( 'yes' ), true ),
			'details submitted, string 1'     => array( $submitted( '1' ), true ),
			'details submitted, integer 1'    => array( $submitted( 1 ), true ),
			'invalid payload, false'          => array( false, false ),
			'invalid payload, a string'       => array( 'invalid', false ),
			'invalid payload, empty'          => array( array(), false ),
			'invalid payload, data text'      => array( array( 'data' => 'invalid' ), false ),
			'invalid payload, no fields'      => array( array( 'data' => array() ), false ),
		);
	}

	/**
	 * @testdox Should return the admin onboarding context values: $_dataName.
	 * @dataProvider provide_admin_onboarding_contexts
	 *
	 * @param array<string,string>|null $constants Constant values the extension declares, or null when it declares none.
	 * @param array<string,string>      $expected  Expected context.
	 */
	public function test_returns_admin_onboarding_context_values( ?array $constants, array $expected ): void {
		Constants::clear_constants();
		foreach ( $constants ?? array() as $name => $value ) {
			Constants::set_constant( $name, $value );
		}

		try {
			$sut = $this->create_runtime();

			$this->assertSame( $expected, $sut->get_admin_onboarding_context() );
		} finally {
			Constants::clear_constants();
		}
	}

	/**
	 * Provide the admin onboarding context cases: constants, expected context.
	 *
	 * @return array<string,array{0:array<string,string>|null,1:array<string,string>}>
	 */
	public function provide_admin_onboarding_contexts(): array {
		return array(
			'from WooPayments constants' => array(
				array(
					'WC_Payments_Onboarding_Service::FROM_WCADMIN_PAYMENTS_SETTINGS' => 'RUNTIME_FROM',
					'WC_Payments_Onboarding_Service::SOURCE_WCADMIN_SETTINGS_PAGE'   => 'runtime-source',
				),
				array(
					'from'   => 'RUNTIME_FROM',
					'source' => 'runtime-source',
				),
			),
			'Core fallbacks without the WooPayments constants' => array(
				null,
				array(
					'from'   => 'WCADMIN_PAYMENT_SETTINGS',
					'source' => 'wcadmin-settings-page',
				),
			),
		);
	}

	/**
	 * @testdox Should report only live cached WooPayments accounts as live accounts.
	 */
	public function test_reports_only_live_cached_account_data_as_live(): void {
		$accounts = array(
			'live'       => array(
				'account_id'    => 'acct_live',
				'is_live'       => true,
				'is_test_drive' => false,
			),
			'test_drive' => array(
				'account_id'    => 'acct_test_drive',
				'is_live'       => false,
				'is_test_drive' => true,
			),
			'sandbox'    => array(
				'account_id' => 'acct_sandbox',
				'is_live'    => false,
			),
			'legacy'     => array(
				'account_id' => 'acct_legacy',
			),
		);

		foreach ( $accounts as $name => $account ) {
			$sut = $this->create_runtime_with_account_data( array( 'data' => $account ) );

			$this->assertSame( in_array( $name, array( 'live', 'legacy' ), true ), $sut->has_live_cached_account_data() );
		}
	}

	/**
	 * @testdox Should compare the WooPayments extension version against $minimum_version: $_dataName.
	 * @dataProvider provide_extension_versions
	 *
	 * @param mixed     $version         WCPAY_VERSION_NUMBER value, or null when the constant is not defined.
	 * @param string    $minimum_version Minimum version.
	 * @param bool|null $expected        Expected answer.
	 */
	public function test_compares_extension_version( $version, string $minimum_version, ?bool $expected ): void {
		if ( null === $version && 'yes' === getenv( 'WOOPAYMENTS_FEATURE' ) ) {
			$this->markTestSkipped( 'The feature-on run loads the built-in WooPayments at boot, which defines WCPAY_VERSION_NUMBER for the whole process.' );
		}
		Constants::clear_constants();
		if ( null !== $version ) {
			Constants::set_constant( 'WCPAY_VERSION_NUMBER', $version );
		}

		try {
			$sut = $this->create_runtime();

			$this->assertSame( $expected, $sut->is_extension_version_less_than( $minimum_version ) );
		} finally {
			Constants::clear_constants();
		}
	}

	/**
	 * Provide extension versions: constant value, minimum version, expected answer.
	 *
	 * @return array<string,array{0:mixed,1:string,2:bool|null}>
	 */
	public function provide_extension_versions(): array {
		return array(
			'older version'       => array( '10.0.0', '10.1.0', true ),
			'same version'        => array( '10.0.0', '10.0.0', false ),
			'newer version'       => array( '10.0.0', '9.9.0', false ),
			'version unavailable' => array( null, '10.0.0', null ),
			'version not scalar'  => array( array( 'version' => '10.0.0' ), '10.0.0', null ),
		);
	}

	/**
	 * @testdox Should reset WooPayments onboarding test mode option when available.
	 */
	public function test_resets_onboarding_test_mode_option_when_available(): void {
		Constants::set_constant( 'WC_Payments_Onboarding_Service::TEST_MODE_OPTION', 'wcpay_test_mode' );
		$updated_options = array();
		$this->register_legacy_proxy_function_mocks(
			array(
				'update_option' => function ( $option, $value ) use ( &$updated_options ) {
					$updated_options[ $option ] = $value;

					return true;
				},
			)
		);
		$sut = $this->create_runtime( array( 'WC_Payments_Onboarding_Service' ) );

		try {
			$sut->reset_onboarding_test_mode_option();

			$this->assertSame(
				array(
					'wcpay_test_mode' => 'no',
				),
				$updated_options
			);
		} finally {
			Constants::clear_constants();
		}
	}

	/**
	 * @testdox Should answer null when the loaded extension's own lookups throw.
	 */
	public function test_swallows_runtime_lookup_exceptions(): void {
		$proxy = $this->createMock( LegacyProxy::class );
		$proxy->method( 'call_function' )->willReturnCallback(
			static function ( string $function_name ) {
				if ( 'class_exists' === $function_name ) {
					return true;
				}

				throw new RuntimeException( 'Runtime lookup failed.' );
			}
		);
		$proxy->method( 'call_static' )->willThrowException( new RuntimeException( 'Runtime lookup failed.' ) );
		$sut = new WooPaymentsExtensionRuntime();
		$sut->init( $proxy );

		$this->assertNull( $sut->get_gateway() );
		$this->assertNull( $sut->get_account_service() );
		$this->assertNull( $sut->get_payments_api_client() );
		$this->assertNull( $sut->get_account_connect_url( 'native-payments' ) );
		$this->assertNull( $sut->is_test_mode() );
		$this->assertNull( $sut->is_dev_mode() );
		$this->assertNull( $sut->is_test_mode_onboarding() );
		$this->assertNull( $sut->get_account_status_data() );
		$this->assertNull( $sut->get_supported_countries() );
	}

	/**
	 * Create a runtime with injected WooPayments account data.
	 *
	 * The payload is the client's wcpay_account_data cache entry: the account fields sit under 'data' (client 11.1.0
	 * includes/class-database-cache.php:197-204), among them account_id and details_submitted. The client accepts only
	 * details_submitted === true (includes/class-wc-payments-account.php:338-342); the string and numeric forms the tests
	 * also pass are defensive inputs for the lenient check core's PaymentsController made before native payments.
	 *
	 * @param mixed $account_data Account data payload.
	 * @return WooPaymentsExtensionRuntime
	 */
	private function create_runtime_with_account_data( $account_data ): WooPaymentsExtensionRuntime {
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_option' => fn( $option, $default_value = false ) => 'wcpay_account_data' === $option ? $account_data : get_option( $option, $default_value ),
			)
		);

		return $this->create_runtime();
	}

	/**
	 * Create a runtime over the container's legacy proxy, with only the given WooPayments extension classes existing.
	 *
	 * @param string[]                       $extension_classes Extension classes that exist.
	 * @param WooPaymentsRuntimeArbiter|null $arbiter           Runtime arbiter.
	 * @return WooPaymentsExtensionRuntime
	 */
	private function create_runtime( array $extension_classes = array( 'WC_Payments' ), ?WooPaymentsRuntimeArbiter $arbiter = null ): WooPaymentsExtensionRuntime {
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => fn( $class_name ) => in_array( ltrim( (string) $class_name, '\\' ), $extension_classes, true ),
			)
		);

		$sut = new WooPaymentsExtensionRuntime();
		$sut->init( wc_get_container()->get( LegacyProxy::class ), $arbiter );

		return $sut;
	}
}
