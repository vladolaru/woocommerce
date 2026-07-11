<?php
/**
 * WooPaymentsWooPayAdaptedExtensions class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry;
use WP_User;

/**
 * Collects extension data adapted for WooPay checkout sessions.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsWooPayAdaptedExtensions extends IntegrationRegistry {

	private const ENABLED_ADAPTED_EXTENSIONS_OPTION = 'woopay_enabled_adapted_extensions';

	private const POINTS_AND_REWARDS_PLUGIN = 'woocommerce-points-and-rewards';

	private const POINTS_AND_REWARDS_API = 'points-and-rewards';

	private const GIFT_CARDS_API = 'woocommerce-gift-cards';

	private const GIFT_CARDS_BLOCKS = 'wc-gift-cards-blocks';

	/**
	 * Register adapted checkout integrations through the preserved Blocks hook.
	 */
	public function register_integrations(): void {
		$registry = $this;

		/**
		 * Fires when checkout block integrations are registered.
		 *
		 * @since 5.0.0
		 *
		 * @param IntegrationRegistry $registry Temporary adapted-extension registry.
		 */
		do_action( 'woocommerce_blocks_checkout_block_registration', $registry );
	}

	/**
	 * Get enabled adapted extension data for a shopper email.
	 *
	 * @param string $email Shopper email.
	 * @return array<string,mixed>
	 */
	public function get_adapted_extensions_data( string $email ): array {
		$enabled_extensions = get_option( self::ENABLED_ADAPTED_EXTENSIONS_OPTION, array() );
		if ( ! is_array( $enabled_extensions ) || array() === $enabled_extensions ) {
			return array();
		}

		$user = wp_get_current_user();
		if ( ! is_user_logged_in() ) {
			$user_by_email = get_user_by( 'email', $email );
			if ( $user_by_email instanceof WP_User ) {
				$user = $user_by_email;
			}
		}

		$extension_data = array();
		if ( in_array( self::POINTS_AND_REWARDS_PLUGIN, $enabled_extensions, true ) ) {
			$points_data = $this->get_points_and_rewards_data( $user );
			if ( null !== $points_data ) {
				$extension_data[ self::POINTS_AND_REWARDS_API ] = $points_data;
			}
		}

		if ( in_array( self::GIFT_CARDS_API, $enabled_extensions, true ) ) {
			$gift_cards_data = $this->get_gift_cards_data( $user );
			if ( null !== $gift_cards_data ) {
				$extension_data[ self::GIFT_CARDS_API ] = $gift_cards_data;
			}
		}

		return $extension_data;
	}

	/**
	 * Get Points and Rewards data for a shopper.
	 *
	 * @param WP_User $user Shopper user.
	 * @return array<string,mixed>|null
	 */
	public function get_points_and_rewards_data( WP_User $user ): ?array {
		$integration     = $this->get_registered( self::POINTS_AND_REWARDS_API );
		$points_callback = array( 'WC_Points_Rewards_Manager', 'get_users_points' );
		if ( ! $integration instanceof IntegrationInterface || ! is_callable( $points_callback ) ) {
			return null;
		}

		$script_data = $integration->get_script_data();
		$ratio       = get_option( 'wc_points_rewards_redeem_points_ratio', '' );
		$ratio_parts = is_string( $ratio ) ? array_pad( explode( ':', $ratio, 2 ), 2, '0' ) : array( '0', '0' );

		$script_data['points_ratio'] = array(
			'points'         => (float) $ratio_parts[0],
			'monetary_value' => (float) $ratio_parts[1],
		);

		$available_points = (int) call_user_func( $points_callback, $user->ID );
		$minimum_points   = (float) ( $script_data['minimum_points_amount'] ?? 0 );
		if ( $available_points > 0 && $available_points > $minimum_points ) {
			$script_data['should_verify_email'] = ! is_user_logged_in();
			$script_data['points_available']    = $available_points;
		}

		return $script_data;
	}

	/**
	 * Get Gift Cards data for a shopper.
	 *
	 * @param WP_User $user Shopper user.
	 * @return array<string,mixed>|null
	 */
	public function get_gift_cards_data( WP_User $user ): ?array {
		$integration = $this->get_registered( self::GIFT_CARDS_BLOCKS );
		if ( ! $integration instanceof IntegrationInterface || ! function_exists( 'WC_GC' ) ) {
			return null;
		}

		$gift_cards            = WC_GC();
		$account               = is_object( $gift_cards ) && isset( $gift_cards->account ) && is_object( $gift_cards->account )
			? $gift_cards->account
			: null;
		$get_active_gift_cards = array( $account, 'get_active_giftcards' );
		if ( null === $account || ! is_callable( $get_active_gift_cards ) ) {
			return null;
		}

		$script_data                        = $integration->get_script_data();
		$script_data['should_verify_email'] = false;
		if ( is_user_logged_in() ) {
			return $script_data;
		}

		$balance           = 0.0;
		$active_gift_cards = call_user_func( $get_active_gift_cards, $user->ID );
		foreach ( is_iterable( $active_gift_cards ) ? $active_gift_cards : array() as $gift_card ) {
			if ( is_object( $gift_card ) && is_callable( array( $gift_card, 'get_balance' ) ) ) {
				$balance += (float) $gift_card->get_balance();
			}
		}

		if ( $balance > 0 ) {
			$script_data['should_verify_email'] = true;
		}

		return $script_data;
	}

	/**
	 * Get custom extension data that does not use WooPay email verification.
	 *
	 * @return array<string,mixed>
	 */
	public function get_extension_data(): array {
		$extension_data = array();

		if ( defined( 'WOOCOMMERCE_MULTICURRENCY_VERSION' ) ) {
			$extension_data['woocommerce-multicurrency'] = array(
				'currency' => get_woocommerce_currency(),
			);
		}

		if ( $this->is_affiliate_for_woocommerce_enabled() ) {
			$extension_data['affiliate-for-woocommerce'] = array(
				'affiliate-user' => $this->call_optional_function( 'afwc_get_referrer_id' ),
			);
		}

		if ( $this->is_automatewoo_referrals_enabled() ) {
			$extension_data['automatewoo-referrals'] = array(
				'advocate_id' => $this->get_automatewoo_advocate_id(),
			);
		}

		return $extension_data;
	}

	/**
	 * Register only integrations WooPay can adapt.
	 *
	 * @param IntegrationInterface $integration Blocks integration.
	 * @return bool
	 */
	public function register( IntegrationInterface $integration ) {
		$name = $integration->get_name();
		if ( self::POINTS_AND_REWARDS_API === $name || self::GIFT_CARDS_BLOCKS === $name ) {
			$this->registered_integrations[ $name ] = $integration;
		}

		return true;
	}

	/**
	 * Tell whether Affiliate for WooCommerce can provide referral data.
	 */
	private function is_affiliate_for_woocommerce_enabled(): bool {
		$api_class = 'AFWC_API';

		return defined( 'AFWC_PLUGIN_FILE' ) &&
			$this->is_optional_function_available( 'afwc_get_referrer_id' ) &&
			$this->optional_class_has_method( $api_class, 'get_instance' ) &&
			$this->optional_class_has_method( $api_class, 'track_conversion' );
	}

	/**
	 * Tell whether AutomateWoo Referrals can provide advocate data.
	 */
	private function is_automatewoo_referrals_enabled(): bool {
		$advocate_callback = array( 'AutomateWoo\Referrals\Referral_Manager', 'get_advocate_key_from_cookie' );
		if ( ! $this->is_optional_function_available( 'AW_Referrals' ) || ! is_callable( $advocate_callback ) ) {
			return false;
		}

		$referrals = $this->call_optional_function( 'AW_Referrals' );

		return is_object( $referrals ) &&
			is_callable( array( $referrals, 'options' ) ) &&
			is_object( $referrals->options() ) &&
			'link' === ( $referrals->options()->type ?? null );
	}

	/**
	 * Get an AutomateWoo referral advocate ID from its cookie key.
	 *
	 * @return mixed
	 */
	private function get_automatewoo_advocate_id() {
		$callback = array( 'AutomateWoo\Referrals\Referral_Manager', 'get_advocate_key_from_cookie' );
		$advocate = is_callable( $callback ) ? call_user_func( $callback ) : null;

		return is_object( $advocate ) && is_callable( array( $advocate, 'get_advocate_id' ) )
			? $advocate->get_advocate_id()
			: null;
	}

	/**
	 * Tell whether an optional extension function is available.
	 *
	 * @param string $function_name Function name.
	 */
	private function is_optional_function_available( string $function_name ): bool {
		return function_exists( $function_name );
	}

	/**
	 * Invoke an optional extension function when available.
	 *
	 * @param string $function_name Function name.
	 * @return mixed
	 */
	private function call_optional_function( string $function_name ) {
		if ( ! $this->is_optional_function_available( $function_name ) ) {
			return null;
		}

		return ( new \ReflectionFunction( $function_name ) )->invoke();
	}

	/**
	 * Tell whether an optional extension class exposes a method.
	 *
	 * @param string $class_name  Class name.
	 * @param string $method_name Method name.
	 */
	private function optional_class_has_method( string $class_name, string $method_name ): bool {
		return class_exists( $class_name ) && method_exists( $class_name, $method_name );
	}
}
