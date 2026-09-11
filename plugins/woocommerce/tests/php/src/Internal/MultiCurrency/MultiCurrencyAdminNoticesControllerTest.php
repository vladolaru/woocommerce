<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProvider;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyCacheInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAdminNoticesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistrarInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistry;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyAdminNoticesController class.
 */
class MultiCurrencyAdminNoticesControllerTest extends WC_Unit_Test_Case {

	private const NOTICE_OPTION                = 'wcpay_multi_currency_show_store_currency_changed_notice';
	private const NOTICE_QUERY                 = 'wcpay-multi-currency-hide-notice';
	private const NONCE_QUERY                  = '_wcpay_multi_currency_notice_nonce';
	private const NONCE_ACTION                 = 'wcpay_multi_currency_hide_notices_nonce';
	private const ADMIN_NOTICES                = 'admin_notices';
	private const WP_LOADED                    = 'wp_loaded';
	private const NOTICE_MESSAGE               = 'The store currency was recently changed. The following currencies are set to manual rates and may need updates: Canadian dollar, Euro';
	private const RATE_NOTICE_KEY              = 'rate_provider_unavailable';
	private const RATE_NOTICE_DISMISSED_OPTION = 'wcpay_multi_currency_rate_provider_unavailable_notice_dismissed';
	private const FORBIDDEN_ERROR              = 'Sorry, you are not allowed to do that.';
	private const NONCE_ERROR                  = 'Action failed. Please refresh the page and retry.';

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		remove_all_filters( self::ADMIN_NOTICES );
		remove_all_filters( self::WP_LOADED );
		delete_option( self::NOTICE_OPTION );
		delete_option( self::RATE_NOTICE_DISMISSED_OPTION );
		delete_option( '_wcpay_feature_customer_multi_currency' );
		delete_option( 'wcpay_multi_currency_enabled_currencies' );
		delete_option( 'wcpay_multi_currency_exchange_rate_gbp' );
		delete_option( MultiCurrencyCacheInterface::CURRENCIES_KEY );
		unset( $_GET[ self::NOTICE_QUERY ], $_GET[ self::NONCE_QUERY ] );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * @testdox Should not register admin notice hooks when plugin owns runtime.
	 */
	public function test_does_not_register_admin_notice_hooks_when_plugin_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN );

		$sut->register();

		$this->assertFalse( has_action( self::ADMIN_NOTICES, array( $sut, 'handle_admin_notices' ) ) );
		$this->assertFalse( has_action( self::WP_LOADED, array( $sut, 'handle_wp_loaded' ) ) );
	}

	/**
	 * @testdox Should register admin notice hooks once when core owns runtime.
	 */
	public function test_registers_admin_notice_hooks_once_when_core_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

		$sut->register();
		$sut->register();

		$this->assertSame( 10, has_action( self::ADMIN_NOTICES, array( $sut, 'handle_admin_notices' ) ) );
		$this->assertSame( 10, has_action( self::WP_LOADED, array( $sut, 'handle_wp_loaded' ) ) );
	}

	/**
	 * @testdox Should render the manual rate notice for users who can manage WooCommerce.
	 */
	public function test_renders_manual_rate_notice_for_users_who_can_manage_woocommerce(): void {
		$this->set_current_user_can_manage_woocommerce();
		update_option( self::NOTICE_OPTION, array( 'Canadian dollar', 'Euro' ) );
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

		ob_start();
		$sut->handle_admin_notices();
		$markup = ob_get_clean();

		$this->assertIsString( $markup );
		$this->assertStringContainsString( 'class="notice notice-warning"', $markup );
		$this->assertStringContainsString( self::NOTICE_MESSAGE, $markup );
		$this->assertStringContainsString( self::NOTICE_QUERY . '=currency_changed', $markup );
		$this->assertStringContainsString( self::NONCE_QUERY, $markup );
		$this->assertStringContainsString( 'class="woocommerce-message-close notice-dismiss"', $markup );
	}

	/**
	 * @testdox Should render the rate-provider unavailable notice for automatic currencies without a provider.
	 */
	public function test_renders_rate_provider_unavailable_notice_for_automatic_currencies_without_provider(): void {
		$this->set_current_user_can_manage_woocommerce();
		$this->enable_multi_currency_with_rate_type( 'automatic' );
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

		ob_start();
		$sut->handle_admin_notices();
		$markup = ob_get_clean();

		$this->assertIsString( $markup );
		$this->assertStringContainsString( 'class="notice notice-warning"', $markup );
		$this->assertStringContainsString( 'Automatic exchange rates are currently unavailable;', $markup );
		$this->assertStringContainsString( 'Manual rates keep working.', $markup );
		$this->assertStringContainsString( self::NOTICE_QUERY . '=' . self::RATE_NOTICE_KEY, $markup );
		$this->assertStringContainsString( self::NONCE_QUERY, $markup );
	}

	/**
	 * @testdox Should not render the rate-provider unavailable notice for manual currencies.
	 */
	public function test_does_not_render_rate_provider_unavailable_notice_for_manual_currencies(): void {
		$this->set_current_user_can_manage_woocommerce();
		$this->enable_multi_currency_with_rate_type( 'manual' );
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

		ob_start();
		$sut->handle_admin_notices();
		$markup = ob_get_clean();

		$this->assertIsString( $markup );
		$this->assertStringNotContainsString( 'Automatic exchange rates are currently unavailable;', $markup );
	}

	/**
	 * @testdox Should not render the rate-provider unavailable notice when a provider is available.
	 */
	public function test_does_not_render_rate_provider_unavailable_notice_when_provider_is_available(): void {
		$this->set_current_user_can_manage_woocommerce();
		$this->enable_multi_currency_with_rate_type( 'automatic' );
		$provider_registry_factory = new CurrencyRateProviderRegistryFactory();
		$this->register_available_rate_provider( $provider_registry_factory );
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, $provider_registry_factory );

		ob_start();
		$sut->handle_admin_notices();
		$markup = ob_get_clean();

		$this->assertIsString( $markup );
		$this->assertStringNotContainsString( 'Automatic exchange rates are currently unavailable;', $markup );
	}

	/**
	 * @testdox Should hide the rate-provider unavailable notice for a valid dismissal request.
	 */
	public function test_hides_rate_provider_unavailable_notice_for_valid_dismissal_request(): void {
		$this->set_current_user_can_manage_woocommerce();
		$_GET[ self::NOTICE_QUERY ] = self::RATE_NOTICE_KEY;
		$_GET[ self::NONCE_QUERY ]  = wp_create_nonce( self::NONCE_ACTION );
		$sut                        = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

		$sut->handle_wp_loaded();

		$this->assertSame( 'yes', get_option( self::RATE_NOTICE_DISMISSED_OPTION ) );
	}

	/**
	 * @testdox Should not render notices for users who cannot manage WooCommerce.
	 */
	public function test_does_not_render_notices_for_users_who_cannot_manage_woocommerce(): void {
		$this->set_current_user_cannot_manage_woocommerce();
		update_option( self::NOTICE_OPTION, array( 'Canadian dollar', 'Euro' ) );
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

		ob_start();
		$sut->handle_admin_notices();
		$markup = ob_get_clean();

		$this->assertSame( '', $markup );
	}

	/**
	 * @testdox Should hide the currency changed notice for a valid dismissal request.
	 */
	public function test_hides_currency_changed_notice_for_valid_dismissal_request(): void {
		$this->set_current_user_can_manage_woocommerce();
		update_option( self::NOTICE_OPTION, array( 'Canadian dollar' ) );
		$_GET[ self::NOTICE_QUERY ] = 'currency_changed';
		$_GET[ self::NONCE_QUERY ]  = wp_create_nonce( self::NONCE_ACTION );
		$sut                        = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

		$sut->handle_wp_loaded();

		$this->assertSame( 'no', get_option( self::NOTICE_OPTION ) );
	}

	/**
	 * @testdox Should die for an invalid dismissal nonce.
	 */
	public function test_dies_for_invalid_dismissal_nonce(): void {
		$this->set_current_user_can_manage_woocommerce();
		$_GET[ self::NOTICE_QUERY ] = 'currency_changed';
		$_GET[ self::NONCE_QUERY ]  = 'invalid';
		$messages                   = array();
		$sut                        = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );
		$sut->set_die_handler( $this->create_die_handler( $messages ) );

		$this->expectException( \RuntimeException::class );

		try {
			$sut->handle_wp_loaded();
		} finally {
			$this->assertSame( array( self::NONCE_ERROR ), $messages );
		}
	}

	/**
	 * @testdox Should die for a forbidden dismissal request.
	 */
	public function test_dies_for_forbidden_dismissal_request(): void {
		$this->set_current_user_cannot_manage_woocommerce();
		$_GET[ self::NOTICE_QUERY ] = 'currency_changed';
		$_GET[ self::NONCE_QUERY ]  = wp_create_nonce( self::NONCE_ACTION );
		$messages                   = array();
		$sut                        = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );
		$sut->set_die_handler( $this->create_die_handler( $messages ) );

		$this->expectException( \RuntimeException::class );

		try {
			$sut->handle_wp_loaded();
		} finally {
			$this->assertSame( array( self::FORBIDDEN_ERROR ), $messages );
		}
	}

	/**
	 * Create an admin notices controller.
	 *
	 * @param string                                   $owner                     Runtime owner.
	 * @param CurrencyRateProviderRegistryFactory|null $provider_registry_factory Rate provider registry factory.
	 * @return MultiCurrencyAdminNoticesController
	 */
	private function create_controller( string $owner, ?CurrencyRateProviderRegistryFactory $provider_registry_factory = null ): MultiCurrencyAdminNoticesController {
		$controller = new MultiCurrencyAdminNoticesController();
		$controller->init( $this->create_arbiter( $owner ), $provider_registry_factory ?? new CurrencyRateProviderRegistryFactory() );

		return $controller;
	}

	/**
	 * Enable multi-currency with one GBP rate type and preserved cache timestamp.
	 *
	 * @param string $rate_type Exchange rate type.
	 */
	private function enable_multi_currency_with_rate_type( string $rate_type ): void {
		update_option( '_wcpay_feature_customer_multi_currency', '1' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', $rate_type );
		update_option(
			MultiCurrencyCacheInterface::CURRENCIES_KEY,
			array(
				'data'               => array(
					'currencies' => array(
						'gbp' => 0.82,
					),
					'updated'    => 123456,
				),
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			),
			false
		);
	}

	/**
	 * Register an available automatic-rate provider.
	 *
	 * @param CurrencyRateProviderRegistryFactory $provider_registry_factory Rate provider registry factory.
	 */
	private function register_available_rate_provider( CurrencyRateProviderRegistryFactory $provider_registry_factory ): void {
		$provider_registry_factory->set_provider_registrars(
			array(
				new class() implements CurrencyRateProviderRegistrarInterface {
					/**
					 * Register an available rate provider.
					 *
					 * @param CurrencyRateProviderRegistry $registry Rate provider registry.
					 */
					public function register( CurrencyRateProviderRegistry $registry ): void {
						$registry->register(
							new class() implements CurrencyRateProvider {
								/**
								 * Get the provider identifier.
								 *
								 * @return string
								 */
								public function get_id(): string {
									return 'test';
								}

								/**
								 * Tell whether automatic rates are currently available.
								 *
								 * @return bool
								 */
								public function is_available(): bool {
									return true;
								}

								/**
								 * Get supported currencies.
								 *
								 * @return string[]
								 */
								public function get_supported_currencies(): array {
									return array( 'GBP' );
								}

								/**
								 * Get currency rates.
								 *
								 * @param string        $currency_from Currency to convert from.
								 * @param string[]|null $currencies_to Currencies to convert into, or null for all supported.
								 * @return array<string,mixed>
								 */
								public function get_currency_rates( string $currency_from, ?array $currencies_to = null ): array {
									unset( $currency_from, $currencies_to );

									return array( 'gbp' => 0.82 );
								}
							}
						);
					}
				},
			)
		);
	}

	/**
	 * Create a die handler test double.
	 *
	 * @param array<int,string> $messages Captured die messages.
	 * @return callable
	 */
	private function create_die_handler( array &$messages ): callable {
		return static function ( $message ) use ( &$messages ): void {
			$messages[] = wp_strip_all_tags( (string) $message );

			throw new \RuntimeException( 'wp_die intercepted' );
		};
	}

	/**
	 * Set the current user to one who can manage WooCommerce.
	 *
	 * @return void
	 */
	private function set_current_user_can_manage_woocommerce(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );

		if ( $user instanceof \WP_User ) {
			$user->add_cap( 'manage_woocommerce' );
		}

		wp_set_current_user( $user_id );
	}

	/**
	 * Set the current user to one who cannot manage WooCommerce.
	 *
	 * @return void
	 */
	private function set_current_user_cannot_manage_woocommerce(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );

		if ( $user instanceof \WP_User ) {
			$user->remove_cap( 'manage_woocommerce' );
		}

		wp_set_current_user( $user_id );
	}

	/**
	 * Create a static multi-currency runtime arbiter.
	 *
	 * @param string $owner Runtime owner.
	 * @return MultiCurrencyRuntimeArbiter
	 */
	private function create_arbiter( string $owner ): MultiCurrencyRuntimeArbiter {
		return new class( $owner ) extends MultiCurrencyRuntimeArbiter {
			/**
			 * Runtime owner.
			 *
			 * @var string
			 */
			private string $owner;

			/**
			 * Constructor.
			 *
			 * @param string $owner Runtime owner.
			 */
			public function __construct( string $owner ) {
				$this->owner = $owner;
			}

			/**
			 * Get the multi-currency runtime owner for the current site.
			 *
			 * @return string
			 */
			public function get_runtime_owner(): string {
				return $this->owner;
			}

			/**
			 * Tell whether core multi-currency may register hooks.
			 *
			 * @return bool
			 */
			public function should_core_register(): bool {
				return MultiCurrencyRuntimeArbiter::OWNER_CORE === $this->owner;
			}
		};
	}
}
