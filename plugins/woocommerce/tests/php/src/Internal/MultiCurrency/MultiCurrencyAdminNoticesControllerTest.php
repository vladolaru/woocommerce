<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProvider;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyCacheInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAdminNoticesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRateService;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyAdminNoticesController class.
 */
class MultiCurrencyAdminNoticesControllerTest extends WC_Unit_Test_Case {

	private const NOTICE_OPTION                = 'wcpay_multi_currency_show_store_currency_changed_notice';
	private const CANONICAL_NOTICE_QUERY       = 'wc-multi-currency-hide-notice';
	private const LEGACY_NOTICE_QUERY          = 'wcpay-multi-currency-hide-notice';
	private const CANONICAL_NONCE_QUERY        = 'wc-multi-currency-notice-nonce';
	private const LEGACY_NONCE_QUERY           = '_wcpay_multi_currency_notice_nonce';
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
		unset( $_GET[ self::CANONICAL_NOTICE_QUERY ], $_GET[ self::LEGACY_NOTICE_QUERY ], $_GET[ self::CANONICAL_NONCE_QUERY ], $_GET[ self::LEGACY_NONCE_QUERY ] );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * @testdox Should not register admin notice hooks when plugin owns runtime.
	 */
	public function test_does_not_register_admin_notice_hooks_when_plugin_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION );

		$sut->register();

		$this->assertFalse( has_action( self::ADMIN_NOTICES, array( $sut, 'handle_admin_notices' ) ) );
		$this->assertFalse( has_action( self::WP_LOADED, array( $sut, 'handle_wp_loaded' ) ) );
	}

	/**
	 * @testdox Should register admin notice hooks once when core owns runtime.
	 */
	public function test_registers_admin_notice_hooks_once_when_core_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

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
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		ob_start();
		$sut->handle_admin_notices();
		$markup = ob_get_clean();

		$this->assertIsString( $markup );
		$this->assertStringContainsString( 'class="notice notice-warning"', $markup );
		$this->assertStringContainsString( self::NOTICE_MESSAGE, $markup );
		$this->assertStringContainsString( self::CANONICAL_NOTICE_QUERY . '=currency_changed', $markup );
		$this->assertStringContainsString( self::CANONICAL_NONCE_QUERY, $markup );
		$this->assertStringNotContainsString( self::LEGACY_NOTICE_QUERY, $markup );
		$this->assertStringNotContainsString( self::LEGACY_NONCE_QUERY, $markup );
		$this->assertStringContainsString( 'class="notice-dismiss" style="text-decoration:none;"', $markup );
		$this->assertSame( 1, substr_count( $markup, '<div class=' ), 'One notice.' );
		// Core's dismiss placement (top right, aligned with the text, room kept on the right) instead of the client's
		// floated link; the dismissal still goes through the nonce link.
		$this->assertStringContainsString( '<div class="notice notice-warning" style="position:relative;padding-right:38px;">', $markup );
		$this->assertMatchesRegularExpression( '#<a href="[^"]+" class="notice-dismiss" style="text-decoration:none;"><span class="screen-reader-text">Dismiss this notice\.</span></a><p>#', $markup );
		$this->assertStringNotContainsString( 'woocommerce-message-close', $markup );
		$this->assertStringNotContainsString( 'float:right', $markup );
		$this->assertStringContainsString( '<p>' . self::NOTICE_MESSAGE . '</p>', $markup );
	}

	/**
	 * @testdox The manual-rate notice keeps links in the stored currency names and strips anything else.
	 */
	public function test_manual_rate_notice_keeps_only_links_in_its_message(): void {
		$this->set_current_user_can_manage_woocommerce();
		update_option( self::NOTICE_OPTION, array( '<a href="https://example.test" target="_blank">Allowed</a><script>Blocked</script>' ) );
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		ob_start();
		$sut->handle_admin_notices();
		$markup = ob_get_clean();

		$this->assertIsString( $markup );
		$this->assertStringContainsString( '<a href="https://example.test" target="_blank">Allowed</a>', $markup );
		$this->assertStringNotContainsString( '<script>', $markup );
	}

	/**
	 * @testdox With no manual-rate currency names stored ($label), no notice shows.
	 * @testWith ["no option"]
	 *           ["empty list"]
	 *
	 * @param string $label Readable case name.
	 */
	public function test_does_not_render_manual_rate_notice_without_currency_names( string $label ): void {
		$this->set_current_user_can_manage_woocommerce();
		if ( 'empty list' === $label ) {
			update_option( self::NOTICE_OPTION, array() );
		}
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		ob_start();
		$sut->handle_admin_notices();
		$markup = ob_get_clean();

		$this->assertSame( '', $markup );
	}

	/**
	 * @testdox Should replace notice query arguments while preserving unrelated request arguments.
	 */
	public function test_renders_dismissal_url_with_only_canonical_notice_query_arguments(): void {
		$this->set_current_user_can_manage_woocommerce();
		update_option( self::NOTICE_OPTION, array( 'Canadian dollar' ) );
		$original_request_uri   = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : null;
		$_SERVER['REQUEST_URI'] = '/wp-admin/?unrelated=preserved&wc-multi-currency-hide-notice=old-notice&wc-multi-currency-notice-nonce=old-nonce';
		$sut                    = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		try {
			ob_start();
			$sut->handle_admin_notices();
			$markup = ob_get_clean();

			$this->assertIsString( $markup );
			$this->assertStringContainsString( 'unrelated=preserved', $markup );
			$this->assertStringContainsString( self::CANONICAL_NOTICE_QUERY . '=currency_changed', $markup );
			$this->assertStringContainsString( self::CANONICAL_NONCE_QUERY, $markup );
			$this->assertSame( 1, substr_count( $markup, self::CANONICAL_NOTICE_QUERY ) );
			$this->assertSame( 1, substr_count( $markup, self::CANONICAL_NONCE_QUERY ) );
		} finally {
			if ( null === $original_request_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $original_request_uri;
			}
		}
	}

	/**
	 * @testdox While an automatic-rate currency has no provider, the rates-unavailable notice shows with both ways out and no dismiss control, a stored dismissal or not: $dismissed.
	 * @testWith ["none"]
	 *           ["yes"]
	 *
	 * @param string $dismissed The stored dismissal option, or none.
	 */
	public function test_renders_rate_provider_unavailable_notice_for_automatic_currencies_without_provider( string $dismissed ): void {
		$this->set_current_user_can_manage_woocommerce();
		$this->enable_multi_currency_with_rate_type( 'automatic' );
		if ( 'none' !== $dismissed ) {
			update_option( self::RATE_NOTICE_DISMISSED_OPTION, $dismissed );
		}
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		ob_start();
		$sut->handle_admin_notices();
		$markup = ob_get_clean();

		$this->assertIsString( $markup );
		$this->assertStringContainsString( 'class="notice notice-warning"', $markup );
		$this->assertStringContainsString( 'Automatic exchange rates are currently unavailable; rates were last updated ', $markup );
		$this->assertStringContainsString( '. Manual rates keep working. Set a manual rate for each affected currency in Multi-currency settings, or connect a rate provider.', $markup );
		$this->assertStringNotContainsString( 'notice-dismiss', $markup, 'The notice has no dismiss control.' );
		$this->assertStringNotContainsString( self::RATE_NOTICE_KEY, $markup, 'No dismissal link for the notice.' );
		$this->assertStringContainsString( '<div class="notice notice-warning" style="position:relative;"><p>', $markup );
	}

	/**
	 * @testdox With no automatic-rate currency left without a provider ($label), the rates-unavailable notice is gone.
	 * @testWith ["manual rate", "manual", false]
	 *           ["provider available", "automatic", true]
	 *
	 * @param string $label     Readable case name.
	 * @param string $rate_type The currency's rate type.
	 * @param bool   $provider  Whether an automatic-rate provider is available.
	 */
	public function test_does_not_render_rate_provider_unavailable_notice_once_rates_have_a_source( string $label, string $rate_type, bool $provider ): void {
		unset( $label );
		$this->set_current_user_can_manage_woocommerce();
		$this->enable_multi_currency_with_rate_type( $rate_type );
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, RateServices::with( $provider ? $this->create_available_rate_provider() : null ) );

		ob_start();
		$sut->handle_admin_notices();
		$markup = ob_get_clean();

		$this->assertIsString( $markup );
		$this->assertStringNotContainsString( 'Automatic exchange rates are currently unavailable;', $markup );
	}

	/**
	 * @testdox A dismissal request for the rates-unavailable notice stores nothing.
	 */
	public function test_dismissal_request_for_rate_provider_unavailable_notice_stores_nothing(): void {
		$this->set_current_user_can_manage_woocommerce();
		update_option( self::NOTICE_OPTION, array( 'Canadian dollar' ) );
		$_GET[ self::CANONICAL_NOTICE_QUERY ] = self::RATE_NOTICE_KEY;
		$_GET[ self::CANONICAL_NONCE_QUERY ]  = wp_create_nonce( self::NONCE_ACTION );
		$sut                                  = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		$sut->handle_wp_loaded();

		$this->assertFalse( get_option( self::RATE_NOTICE_DISMISSED_OPTION ) );
		$this->assertSame( array( 'Canadian dollar' ), get_option( self::NOTICE_OPTION ) );
	}

	/**
	 * @testdox A request without a usable notice key and nonce does nothing ($label).
	 *
	 * @dataProvider incomplete_dismissal_request_provider
	 *
	 * @param string               $label Readable case name.
	 * @param array<string,string> $query Query arguments; VALID stands for a valid nonce.
	 */
	public function test_incomplete_dismissal_request_does_nothing( string $label, array $query ): void {
		unset( $label );
		$this->set_current_user_can_manage_woocommerce();
		update_option( self::NOTICE_OPTION, array( 'Canadian dollar' ) );
		foreach ( $query as $key => $value ) {
			$_GET[ $key ] = 'VALID' === $value ? wp_create_nonce( self::NONCE_ACTION ) : $value;
		}
		$messages = array();
		$sut      = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$sut->set_die_handler( $this->create_die_handler( $messages ) );

		$sut->handle_wp_loaded();

		$this->assertSame( array(), $messages );
		$this->assertSame( array( 'Canadian dollar' ), get_option( self::NOTICE_OPTION ) );
	}

	/**
	 * Requests that name no notice or carry no nonce.
	 *
	 * @return array<string,array{0:string,1:array<string,string>}>
	 */
	public function incomplete_dismissal_request_provider(): array {
		return array(
			'no arguments'           => array( 'no arguments', array() ),
			'notice without nonce'   => array( 'notice without nonce', array( self::CANONICAL_NOTICE_QUERY => 'currency_changed' ) ),
			'nonce without notice'   => array( 'nonce without notice', array( self::CANONICAL_NONCE_QUERY => 'VALID' ) ),
			'empty canonical notice' => array(
				'empty canonical notice',
				array(
					self::CANONICAL_NOTICE_QUERY => '',
					self::CANONICAL_NONCE_QUERY  => 'VALID',
				),
			),
			// The dismiss link WooPayments 11.1.0 renders (includes/multi-currency/AdminNotices.php:48,70-79).
			'extension query keys'   => array(
				'extension query keys',
				array(
					self::LEGACY_NOTICE_QUERY => 'currency_changed',
					self::LEGACY_NONCE_QUERY  => 'VALID',
				),
			),
			'empty canonical nonce'  => array(
				'empty canonical nonce',
				array(
					self::CANONICAL_NOTICE_QUERY => 'currency_changed',
					self::CANONICAL_NONCE_QUERY  => '',
				),
			),
		);
	}

	/**
	 * @testdox Should not render notices for users who cannot manage WooCommerce.
	 */
	public function test_does_not_render_notices_for_users_who_cannot_manage_woocommerce(): void {
		$this->set_current_user_cannot_manage_woocommerce();
		update_option( self::NOTICE_OPTION, array( 'Canadian dollar', 'Euro' ) );
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

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
		$_GET[ self::CANONICAL_NOTICE_QUERY ] = 'currency_changed';
		$_GET[ self::CANONICAL_NONCE_QUERY ]  = wp_create_nonce( self::NONCE_ACTION );
		$sut                                  = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		$sut->handle_wp_loaded();

		$this->assertSame( 'no', get_option( self::NOTICE_OPTION ) );
	}

	/**
	 * @testdox Should die for an invalid dismissal nonce.
	 */
	public function test_dies_for_invalid_dismissal_nonce(): void {
		$this->set_current_user_can_manage_woocommerce();
		update_option( self::NOTICE_OPTION, array( 'Canadian dollar' ) );
		$_GET[ self::CANONICAL_NOTICE_QUERY ] = 'currency_changed';
		$_GET[ self::CANONICAL_NONCE_QUERY ]  = 'invalid';
		$messages                             = array();
		$sut                                  = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$sut->set_die_handler( $this->create_die_handler( $messages ) );

		$this->expectException( \RuntimeException::class );

		try {
			$sut->handle_wp_loaded();
		} finally {
			$this->assertSame( array( self::NONCE_ERROR ), $messages );
			$this->assertSame( array( 'Canadian dollar' ), get_option( self::NOTICE_OPTION ) );
			$this->assertFalse( get_option( self::RATE_NOTICE_DISMISSED_OPTION, false ) );
		}
	}

	/**
	 * @testdox Should die for a forbidden dismissal request.
	 */
	public function test_dies_for_forbidden_dismissal_request(): void {
		$this->set_current_user_cannot_manage_woocommerce();
		$_GET[ self::CANONICAL_NOTICE_QUERY ] = 'currency_changed';
		$_GET[ self::CANONICAL_NONCE_QUERY ]  = wp_create_nonce( self::NONCE_ACTION );
		$messages                             = array();
		$sut                                  = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
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
	 * @param string                        $owner        Runtime owner.
	 * @param MultiCurrencyRateService|null $rate_service Rate service, or null for one without a provider.
	 * @return MultiCurrencyAdminNoticesController
	 */
	private function create_controller( string $owner, ?MultiCurrencyRateService $rate_service = null ): MultiCurrencyAdminNoticesController {
		$controller = new MultiCurrencyAdminNoticesController();
		$controller->init( $this->create_arbiter( $owner ), $rate_service ?? RateServices::with() );

		return $controller;
	}

	/**
	 * Enable multi-currency with one GBP rate type and preserved cache timestamp.
	 *
	 * @param string $rate_type Exchange rate type.
	 */
	private function enable_multi_currency_with_rate_type( string $rate_type ): void {
		update_option( '_wcpay_feature_customer_multi_currency', '0' );
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
	 * Create an available automatic-rate provider.
	 *
	 * @return CurrencyRateProvider
	 */
	private function create_available_rate_provider(): CurrencyRateProvider {
		return new class() implements CurrencyRateProvider {
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
			 * Tell whether the provider's account is connected.
			 *
			 * @return bool
			 */
			public function is_connected(): bool {
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
		};
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
				return MultiCurrencyRuntimeArbiter::OWNER_BUILTIN === $this->owner;
			}

			/**
			 * Tell whether the independent core multi-currency feature is enabled.
			 *
			 * @return bool
			 */
			public function feature_is_enabled(): bool {
				return MultiCurrencyRuntimeArbiter::OWNER_BUILTIN === $this->owner;
			}
		};
	}
}
