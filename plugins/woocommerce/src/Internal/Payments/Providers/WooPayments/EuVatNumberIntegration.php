<?php
/**
 * EU VAT Number integration for built-in WooPayments.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Hide wallets that cannot collect a VAT number until EU VAT Number supports the built-in provider directly.
 *
 * @since 11.4.0
 * @internal
 */
class EuVatNumberIntegration implements RegisterHooksInterface {
	/**
	 * Wallet restriction for this request.
	 *
	 * @var string|null
	 */
	private $restriction;

	/**
	 * Expose EU VAT Number's express-pay setting on connected stores.
	 */
	public function register(): void {
		if ( ! did_action( 'plugins_loaded' ) ) {
			add_action( 'plugins_loaded', array( $this, 'register' ), 0 );
			return;
		}
		if ( class_exists( 'WC_EU_VAT_Number_Init', false ) ) {
			add_filter( 'wc_eu_vat_has_supported_express_payment_gateway', '__return_true' );
		}
	}

	/**
	 * Whether EU VAT hides the express locations and list gateways.
	 */
	public function suppresses_express(): bool {
		return 'all' === $this->get_restriction();
	}

	/**
	 * Whether EU VAT disables WooPay, including on older plugin versions.
	 */
	public function suppresses_woopay(): bool {
		return '' !== $this->get_restriction();
	}

	/**
	 * Read the plugin's decision once, after it has loaded its dependencies.
	 *
	 * @return string Empty, woopay, or all.
	 */
	private function get_restriction(): string {
		if ( is_admin() || ! class_exists( 'WC_EU_VAT_Number_Init', false ) || ! class_exists( 'WC_EU_VAT_Number', false ) ) {
			return '';
		}

		// EU VAT reads its filters before installing the settings mask. Wallets queried there still see their configuration.
		if ( doing_filter( 'wc_eu_vat_prevent_incompatible_payment_methods' ) ) {
			return '';
		}

		if ( null === $this->restriction ) {
			// Option callbacks made by the plugin can also ask which wallets are configured.
			$this->restriction = '';

			$decide = array( 'WC_EU_VAT_Number_Init', 'should_prevent_incompatible_payment_methods' );
			if ( is_callable( $decide ) ) {
				$this->restriction = $decide() ? 'all' : '';
			} else {
				// Before 3.2.2, EU VAT disabled WooPay only, and prevention defaulted to off.
				$prevent = 'no' !== get_option( 'woocommerce_eu_vat_number_b2b', 'no' )
					&& 'no' !== get_option( 'woocommerce_eu_vat_number_prevent_incompatible_payment_methods', 'no' );

				$this->restriction = $prevent ? 'woopay' : '';
			}
		}

		return $this->restriction;
	}
}
