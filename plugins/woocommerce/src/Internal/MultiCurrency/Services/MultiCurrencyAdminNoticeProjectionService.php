<?php
/**
 * MultiCurrencyAdminNoticeProjectionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAdminNoticesController;

/**
 * Projects multi-currency admin notices without registering hooks.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyAdminNoticeProjectionService {

	private const NOTICE_KEY_CURRENCY_CHANGED = 'currency_changed';
	private const NOTICE_OPTION_HIDDEN_VALUE  = 'no';

	/**
	 * Project the manual-rate currency-changed notice.
	 *
	 * @param mixed $manual_rate_currencies Manual-rate currency labels.
	 * @return array{key:string,class:string,message:string,dismissible:bool}|null
	 *
	 * @since 11.0.0
	 */
	public static function get_manual_rate_notice( $manual_rate_currencies ): ?array {
		$currency_names = self::get_currency_names( $manual_rate_currencies );
		if ( empty( $currency_names ) ) {
			return null;
		}

		return array(
			'key'         => self::NOTICE_KEY_CURRENCY_CHANGED,
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
	 * Project notices visible to the current user.
	 *
	 * @param bool  $can_manage_woocommerce Whether the user can manage WooCommerce.
	 * @param mixed $manual_rate_currencies Manual-rate currency labels.
	 * @return array<int,array{key:string,class:string,message:string,dismissible:bool}>
	 *
	 * @since 11.0.0
	 */
	public static function get_notices_for_user( bool $can_manage_woocommerce, $manual_rate_currencies ): array {
		if ( ! $can_manage_woocommerce ) {
			return array();
		}

		$notice = self::get_manual_rate_notice( $manual_rate_currencies );

		return null === $notice ? array() : array( $notice );
	}

	/**
	 * Project admin notice markup.
	 *
	 * @param array<string,mixed> $notice      Notice metadata.
	 * @param string              $dismiss_url Optional dismiss URL.
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public static function get_notice_markup( array $notice, string $dismiss_url = '' ): string {
		$dismiss_link = self::get_dismiss_link_markup( (bool) ( $notice['dismissible'] ?? false ), $dismiss_url );
		// A dismissible notice keeps core's 38px on the right for the dismiss control, as `.notice.is-dismissible` does.
		$style   = '' === $dismiss_link ? 'position:relative;' : 'position:relative;padding-right:38px;';
		$markup  = '<div class="' . esc_attr( (string) ( $notice['class'] ?? '' ) ) . '" style="' . $style . '">';
		$markup .= $dismiss_link;
		$markup .= '<p>';
		$markup .= wp_kses(
			(string) ( $notice['message'] ?? '' ),
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
	 * Project a notice dismissal intent.
	 *
	 * @param array<string,mixed> $query                  Query arguments.
	 * @param bool                $nonce_valid            Whether the nonce was validated by the caller.
	 * @param bool                $can_manage_woocommerce Whether the user can manage WooCommerce.
	 * @return array{should_hide: bool, option_name: string|null, option_value: string|null, error: string|null}
	 *
	 * @since 11.0.0
	 */
	public static function get_hide_notice_intent( array $query, bool $nonce_valid, bool $can_manage_woocommerce ): array {
		$notice_key = self::get_clean_query_arg( $query, MultiCurrencyAdminNoticesController::CANONICAL_NOTICE_QUERY, MultiCurrencyAdminNoticesController::LEGACY_NOTICE_QUERY );
		$nonce      = self::get_clean_query_arg( $query, MultiCurrencyAdminNoticesController::CANONICAL_NONCE_QUERY, MultiCurrencyAdminNoticesController::LEGACY_NONCE_QUERY );
		if ( null === $notice_key || null === $nonce ) {
			return self::get_noop_hide_intent();
		}

		if ( ! $nonce_valid ) {
			return self::get_noop_hide_intent( 'invalid_nonce' );
		}

		if ( ! $can_manage_woocommerce ) {
			return self::get_noop_hide_intent( 'forbidden' );
		}

		if ( self::NOTICE_KEY_CURRENCY_CHANGED === $notice_key ) {
			return array(
				'should_hide'  => true,
				'option_name'  => MultiCurrencyStoreCurrencyLifecycleService::NOTICE_OPTION,
				'option_value' => self::NOTICE_OPTION_HIDDEN_VALUE,
				'error'        => null,
			);
		}

		return self::get_noop_hide_intent( 'unsupported_notice' );
	}

	/**
	 * Project dismiss link markup.
	 *
	 * @param bool   $dismissible Whether the notice is dismissible.
	 * @param string $dismiss_url Dismiss URL.
	 * @return string
	 */
	private static function get_dismiss_link_markup( bool $dismissible, string $dismiss_url ): string {
		if ( ! $dismissible || '' === $dismiss_url ) {
			return '';
		}

		// Core's dismiss placement (top right, aligned with the first line) instead of client 11.1.0's floated link, which
		// WooCommerce's `.woocommerce-message-close` rule pushes 12px down. The `is-dismissible`
		// class is left off so WordPress does not add its own JavaScript-only dismiss button next to this nonce link.
		return '<a href="' . esc_url( $dismiss_url ) . '" class="notice-dismiss" style="text-decoration:none;"><span class="screen-reader-text">' . esc_html__( 'Dismiss this notice.', 'woocommerce' ) . '</span></a>';
	}

	/**
	 * Project a no-op hide intent.
	 *
	 * @param string|null $error Error key.
	 * @return array{should_hide: bool, option_name: string|null, option_value: string|null, error: string|null}
	 */
	private static function get_noop_hide_intent( ?string $error = null ): array {
		return array(
			'should_hide'  => false,
			'option_name'  => null,
			'option_value' => null,
			'error'        => $error,
		);
	}

	/**
	 * Get a sanitized scalar query argument.
	 *
	 * @param array<string,mixed> $query        Query arguments.
	 * @param string              $canonical_key Canonical query argument key.
	 * @param string              $legacy_key    Legacy query argument key.
	 * @return string|null
	 */
	private static function get_clean_query_arg( array $query, string $canonical_key, string $legacy_key ): ?string {
		$raw_value = $query[ $canonical_key ] ?? null;
		if ( is_scalar( $raw_value ) ) {
			return self::clean_query_arg( $raw_value );
		}

		$raw_value = $query[ $legacy_key ] ?? null;
		if ( ! is_scalar( $raw_value ) ) {
			return null;
		}

		return self::clean_query_arg( $raw_value );
	}

	/**
	 * Sanitize a scalar query argument.
	 *
	 * @param scalar $raw_value Query argument value.
	 * @return string|null
	 */
	private static function clean_query_arg( $raw_value ): ?string {
		$clean_value = wc_clean( wp_unslash( (string) $raw_value ) );

		return is_string( $clean_value ) && '' !== $clean_value ? $clean_value : null;
	}

	/**
	 * Get manual-rate currency names.
	 *
	 * @param mixed $manual_rate_currencies Manual-rate currency labels.
	 * @return array<int,string>
	 */
	private static function get_currency_names( $manual_rate_currencies ): array {
		if ( ! is_array( $manual_rate_currencies ) ) {
			return array();
		}

		$currency_names = array();
		foreach ( $manual_rate_currencies as $currency_name ) {
			if ( is_scalar( $currency_name ) && '' !== (string) $currency_name ) {
				$currency_names[] = (string) $currency_name;
			}
		}

		return $currency_names;
	}
}
