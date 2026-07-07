<?php
/**
 * MultiCurrencyAdminNoticesController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyCacheInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyAdminNoticeProjectionService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Registers native multi-currency admin notices when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyAdminNoticesController implements RegisterHooksInterface {

	private const ADMIN_NOTICES_HOOK                         = 'admin_notices';
	private const WP_LOADED_HOOK                             = 'wp_loaded';
	private const NOTICE_OPTION                              = 'wcpay_multi_currency_show_store_currency_changed_notice';
	private const NOTICE_QUERY                               = 'wcpay-multi-currency-hide-notice';
	private const NONCE_QUERY                                = '_wcpay_multi_currency_notice_nonce';
	private const NONCE_ACTION                               = 'wcpay_multi_currency_hide_notices_nonce';
	private const ERROR_INVALID_NONCE                        = 'invalid_nonce';
	private const ERROR_FORBIDDEN                            = 'forbidden';
	private const RATE_PROVIDER_UNAVAILABLE_NOTICE_KEY       = 'rate_provider_unavailable';
	private const RATE_PROVIDER_UNAVAILABLE_DISMISSED_OPTION = 'wcpay_multi_currency_rate_provider_unavailable_notice_dismissed';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Rate provider registry factory.
	 *
	 * @var CurrencyRateProviderRegistryFactory
	 */
	private CurrencyRateProviderRegistryFactory $provider_registry_factory;

	/**
	 * Optional die handler used by tests.
	 *
	 * @var callable|null
	 */
	private $die_handler = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter              $arbiter                   Runtime owner arbiter.
	 * @param CurrencyRateProviderRegistryFactory|null $provider_registry_factory Rate provider registry factory.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, ?CurrencyRateProviderRegistryFactory $provider_registry_factory = null ): void {
		$this->arbiter                   = $arbiter;
		$this->provider_registry_factory = $provider_registry_factory ?? wc_get_container()->get( CurrencyRateProviderRegistryFactory::class );
	}

	/**
	 * Set the die handler.
	 *
	 * @internal Used by tests.
	 *
	 * @param callable $die_handler Die handler.
	 */
	public function set_die_handler( callable $die_handler ): void {
		$this->die_handler = $die_handler;
	}

	/**
	 * Register admin notice hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_core_register() ) {
			return;
		}

		$this->add_action_once( self::ADMIN_NOTICES_HOOK, array( $this, 'handle_admin_notices' ) );
		$this->add_action_once( self::WP_LOADED_HOOK, array( $this, 'handle_wp_loaded' ) );
	}

	/**
	 * Render multi-currency admin notices.
	 *
	 * @internal
	 */
	public function handle_admin_notices(): void {
		$notices              = MultiCurrencyAdminNoticeProjectionService::get_notices_for_user(
			current_user_can( 'manage_woocommerce' ),
			get_option( self::NOTICE_OPTION, false )
		);
		$rate_provider_notice = $this->get_rate_provider_unavailable_notice( current_user_can( 'manage_woocommerce' ) );
		if ( null !== $rate_provider_notice ) {
			$notices[] = $rate_provider_notice;
		}

		foreach ( $notices as $notice ) {
			// Projection markup escapes the individual attributes and message HTML while preserving WooPayments-compatible notice HTML.
			echo MultiCurrencyAdminNoticeProjectionService::get_notice_markup( $notice, $this->get_dismiss_url( $notice ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Handle multi-currency admin notice dismissal.
	 *
	 * @internal
	 */
	public function handle_wp_loaded(): void {
		$intent = MultiCurrencyAdminNoticeProjectionService::get_hide_notice_intent(
			$_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->is_notice_nonce_valid(),
			current_user_can( 'manage_woocommerce' )
		);

		if ( self::ERROR_INVALID_NONCE === $intent['error'] ) {
			$this->die_with_message( __( 'Action failed. Please refresh the page and retry.', 'woocommerce' ) );
			return;
		}

		if ( self::ERROR_FORBIDDEN === $intent['error'] ) {
			$this->die_with_message( __( 'Sorry, you are not allowed to do that.', 'woocommerce' ) );
			return;
		}

		if (
			$intent['should_hide']
			&& is_string( $intent['option_name'] )
			&& is_string( $intent['option_value'] )
		) {
			update_option( $intent['option_name'], $intent['option_value'] );
		}
	}

	/**
	 * Build a dismiss URL for the notice.
	 *
	 * @param array<string,mixed> $notice Notice metadata.
	 * @return string
	 */
	private function get_dismiss_url( array $notice ): string {
		if ( empty( $notice['key'] ) || ! is_scalar( $notice['key'] ) ) {
			return '';
		}

		return wp_nonce_url(
			add_query_arg( self::NOTICE_QUERY, (string) $notice['key'] ),
			self::NONCE_ACTION,
			self::NONCE_QUERY
		);
	}

	/**
	 * Tell whether the current notice dismissal nonce is valid.
	 *
	 * @return bool
	 */
	private function is_notice_nonce_valid(): bool {
		if ( ! isset( $_GET[ self::NONCE_QUERY ] ) || ! is_scalar( $_GET[ self::NONCE_QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		$nonce = wc_clean( wp_unslash( (string) $_GET[ self::NONCE_QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return is_string( $nonce ) && (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}

	/**
	 * Build the automatic-rate-provider unavailable notice when it is currently actionable.
	 *
	 * @param bool $can_manage_woocommerce Whether the user can manage WooCommerce.
	 * @return array{key:string,class:string,message:string,dismissible:bool}|null
	 */
	private function get_rate_provider_unavailable_notice( bool $can_manage_woocommerce ): ?array {
		if ( ! $can_manage_woocommerce ) {
			return null;
		}

		if ( ! $this->has_automatic_multi_currency_rate_currencies() || $this->has_available_rate_provider() ) {
			delete_option( self::RATE_PROVIDER_UNAVAILABLE_DISMISSED_OPTION );
			return null;
		}

		if ( 'yes' === (string) get_option( self::RATE_PROVIDER_UNAVAILABLE_DISMISSED_OPTION, 'no' ) ) {
			return null;
		}

		return array(
			'key'         => self::RATE_PROVIDER_UNAVAILABLE_NOTICE_KEY,
			'class'       => 'notice notice-warning',
			'message'     => sprintf(
				/* translators: %s: Last automatic exchange rate update date and time. */
				__( 'Automatic exchange rates are currently unavailable; rates were last updated %s. Manual rates keep working.', 'woocommerce' ),
				$this->get_rate_provider_last_updated_label()
			),
			'dismissible' => true,
		);
	}

	/**
	 * Tell whether an automatic-rate provider is currently available.
	 *
	 * @return bool
	 */
	private function has_available_rate_provider(): bool {
		try {
			return null !== $this->provider_registry_factory->create()->get_available_provider();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Tell whether enabled multi-currency includes any automatic-rate non-default currency.
	 *
	 * @return bool
	 */
	private function has_automatic_multi_currency_rate_currencies(): bool {
		if ( '1' !== (string) get_option( '_wcpay_feature_customer_multi_currency', '1' ) ) {
			return false;
		}

		$enabled_currencies = get_option( 'wcpay_multi_currency_enabled_currencies', array() );
		if ( ! is_array( $enabled_currencies ) || array() === $enabled_currencies ) {
			return false;
		}

		$store_currency = strtoupper( (string) get_option( 'woocommerce_currency', 'USD' ) );
		foreach ( $enabled_currencies as $currency_code ) {
			if ( ! is_scalar( $currency_code ) ) {
				continue;
			}

			$currency_code = strtoupper( trim( (string) $currency_code ) );
			if ( '' === $currency_code || $store_currency === $currency_code ) {
				continue;
			}

			if ( 'manual' !== (string) get_option( 'wcpay_multi_currency_exchange_rate_' . strtolower( $currency_code ), 'automatic' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the label for the last cached automatic-rate update.
	 *
	 * @return string
	 */
	private function get_rate_provider_last_updated_label(): string {
		$cache_data = get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, array() );
		if ( is_array( $cache_data['data'] ?? null ) ) {
			$cache_data = $cache_data['data'];
		}

		$updated = is_array( $cache_data ) ? ( $cache_data['updated'] ?? null ) : null;
		if ( ! is_numeric( $updated ) ) {
			return __( 'unknown', 'woocommerce' );
		}

		return date_i18n( wc_date_format() . ' ' . wc_time_format(), (int) $updated );
	}

	/**
	 * End the request with a user-facing error message.
	 *
	 * @param string $message Error message.
	 */
	private function die_with_message( string $message ): void {
		if ( null !== $this->die_handler ) {
			call_user_func( $this->die_handler, $message );
			return;
		}

		wp_die( esc_html( $message ) );
	}

	/**
	 * Register an action only once for this controller instance.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Hook callback.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Accepted argument count.
	 */
	private function add_action_once( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		if ( false === has_action( $hook, $callback ) ) {
			add_action( $hook, $callback, $priority, $accepted_args );
		}
	}
}
