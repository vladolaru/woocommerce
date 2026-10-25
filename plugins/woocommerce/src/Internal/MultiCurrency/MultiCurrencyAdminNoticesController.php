<?php
/**
 * MultiCurrencyAdminNoticesController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyCacheInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRateService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStoreCurrencyLifecycleService;

/**
 * Registers native multi-currency admin notices when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyAdminNoticesController implements RegisterHooksInterface {

	private const ADMIN_NOTICES_HOOK                   = 'admin_notices';
	private const WP_LOADED_HOOK                       = 'wp_loaded';
	public const CANONICAL_NOTICE_QUERY                = 'wc-multi-currency-hide-notice';
	public const CANONICAL_NONCE_QUERY                 = 'wc-multi-currency-notice-nonce';
	private const NONCE_ACTION                         = 'wcpay_multi_currency_hide_notices_nonce';
	private const RATE_PROVIDER_UNAVAILABLE_NOTICE_KEY = 'rate_provider_unavailable';
	private const CURRENCY_CHANGED_NOTICE_KEY          = 'currency_changed';
	private const NOTICE_OPTION_HIDDEN_VALUE           = 'no';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Rate service holding the automatic-rate provider.
	 *
	 * @var MultiCurrencyRateService
	 */
	private MultiCurrencyRateService $rate_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter      Runtime owner arbiter.
	 * @param MultiCurrencyRateService    $rate_service Rate service holding the automatic-rate provider.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyRateService $rate_service ): void {
		$this->arbiter      = $arbiter;
		$this->rate_service = $rate_service;
	}

	/**
	 * Register admin notice hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		add_action( self::ADMIN_NOTICES_HOOK, array( $this, 'handle_admin_notices' ) );
		add_action( self::WP_LOADED_HOOK, array( $this, 'handle_wp_loaded' ) );
	}

	/**
	 * Render multi-currency admin notices.
	 *
	 * @internal
	 */
	public function handle_admin_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$notices = array_filter(
			array(
				$this->get_manual_rate_notice( get_option( MultiCurrencyStoreCurrencyLifecycleService::NOTICE_OPTION, false ) ),
				$this->get_rate_provider_unavailable_notice(),
			)
		);

		foreach ( $notices as $notice ) {
			// The markup escapes each attribute and the message HTML while keeping WooPayments-compatible notice HTML.
			echo $this->get_notice_markup( $notice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Handle multi-currency admin notice dismissal.
	 *
	 * @internal
	 */
	public function handle_wp_loaded(): void {
		$notice_key = $this->get_clean_query_arg( self::CANONICAL_NOTICE_QUERY );
		$nonce      = $this->get_clean_query_arg( self::CANONICAL_NONCE_QUERY );
		if ( null === $notice_key || null === $nonce ) {
			return;
		}

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'woocommerce' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'woocommerce' ) );
		}

		if ( self::CURRENCY_CHANGED_NOTICE_KEY === $notice_key ) {
			update_option( MultiCurrencyStoreCurrencyLifecycleService::NOTICE_OPTION, self::NOTICE_OPTION_HIDDEN_VALUE );
		}
	}

	/**
	 * Build the notice for currencies left on manual rates after a store currency change.
	 *
	 * @param mixed $manual_rate_currencies Names of the manual-rate currencies, as stored.
	 * @return array{key:string,class:string,message:string,dismissible:bool}|null
	 */
	private function get_manual_rate_notice( $manual_rate_currencies ): ?array {
		if ( ! is_array( $manual_rate_currencies ) ) {
			return null;
		}

		$currency_names = array();
		foreach ( $manual_rate_currencies as $currency_name ) {
			if ( is_scalar( $currency_name ) && '' !== (string) $currency_name ) {
				$currency_names[] = (string) $currency_name;
			}
		}

		if ( empty( $currency_names ) ) {
			return null;
		}

		return array(
			'key'         => self::CURRENCY_CHANGED_NOTICE_KEY,
			'class'       => 'notice notice-warning',
			'message'     => sprintf(
				/* translators: %s: List of currencies set to manual rates. */
				__( 'The store currency was recently changed. The following currencies are set to manual rates and may need updates: %s', 'woocommerce' ),
				implode( ', ', $currency_names )
			),
			'dismissible' => true,
		);
	}

	/**
	 * Build a notice's markup.
	 *
	 * @param array{key:string,class:string,message:string,dismissible:bool} $notice Notice metadata.
	 * @return string
	 */
	private function get_notice_markup( array $notice ): string {
		$dismiss_link = '';
		if ( $notice['dismissible'] ) {
			$dismiss_url = $this->get_dismiss_url( $notice['key'] );
			// Core's dismiss placement (top right, aligned with the first line) instead of client 11.1.0's floated link, which
			// WooCommerce's `.woocommerce-message-close` rule pushes 12px down. The `is-dismissible`
			// class is left off so WordPress does not add its own JavaScript-only dismiss button next to this nonce link.
			$dismiss_link = '<a href="' . esc_url( $dismiss_url ) . '" class="notice-dismiss" style="text-decoration:none;"><span class="screen-reader-text">' . esc_html__( 'Dismiss this notice.', 'woocommerce' ) . '</span></a>';
		}

		// A dismissible notice keeps core's 38px on the right for the dismiss control, as `.notice.is-dismissible` does.
		$style   = '' === $dismiss_link ? 'position:relative;' : 'position:relative;padding-right:38px;';
		$markup  = '<div class="' . esc_attr( $notice['class'] ) . '" style="' . $style . '">';
		$markup .= $dismiss_link;
		$markup .= '<p>';
		$markup .= wp_kses(
			$notice['message'],
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
				),
			)
		);
		$markup .= '</p></div>';

		return $markup;
	}

	/**
	 * Build the nonce-protected dismiss URL for a notice.
	 *
	 * @param string $notice_key Notice key.
	 * @return string
	 */
	private function get_dismiss_url( string $notice_key ): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$dismiss_url = remove_query_arg( array( self::CANONICAL_NOTICE_QUERY, self::CANONICAL_NONCE_QUERY ), $request_uri );

		return wp_nonce_url(
			add_query_arg( self::CANONICAL_NOTICE_QUERY, $notice_key, $dismiss_url ),
			self::NONCE_ACTION,
			self::CANONICAL_NONCE_QUERY
		);
	}

	/**
	 * Get a sanitized query argument.
	 *
	 * @param string $key Query argument key.
	 * @return string|null Null when the argument is not a scalar or the cleaned value is empty.
	 */
	private function get_clean_query_arg( string $key ): ?string {
		$raw_value = $_GET[ $key ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- The caller verifies the nonce; the value is unslashed and cleaned below.

		if ( ! is_scalar( $raw_value ) ) {
			return null;
		}

		$clean_value = wc_clean( wp_unslash( (string) $raw_value ) );

		return is_string( $clean_value ) && '' !== $clean_value ? $clean_value : null;
	}

	/**
	 * Build the automatic-rate-provider unavailable notice when it is currently actionable.
	 *
	 * The notice cannot be dismissed: it stays while an automatic-rate currency has no available provider.
	 *
	 * @return array{key:string,class:string,message:string,dismissible:bool}|null
	 */
	private function get_rate_provider_unavailable_notice(): ?array {
		if ( ! $this->has_automatic_multi_currency_rate_currencies() || $this->rate_service->has_available_provider() ) {
			return null;
		}

		return array(
			'key'         => self::RATE_PROVIDER_UNAVAILABLE_NOTICE_KEY,
			'class'       => 'notice notice-warning',
			'message'     => sprintf(
				/* translators: %s: Last automatic exchange rate update date and time. */
				__( 'Automatic exchange rates are currently unavailable; rates were last updated %s. Manual rates keep working. Set a manual rate for each affected currency in Multi-currency settings, or connect a rate provider.', 'woocommerce' ),
				$this->get_rate_provider_last_updated_label()
			),
			'dismissible' => false,
		);
	}

	/**
	 * Tell whether enabled multi-currency includes any automatic-rate non-default currency.
	 *
	 * @return bool
	 */
	private function has_automatic_multi_currency_rate_currencies(): bool {
		if ( ! $this->arbiter->feature_is_enabled() ) {
			return false;
		}

		$enabled_currencies = get_option( MultiCurrencyStateBuilder::ENABLED_CURRENCIES_OPTION, array() );
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
}
