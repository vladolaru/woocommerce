<?php
/**
 * WooPaymentsCurrencyComplianceNotice class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Admin notices for store currency configurations the provider rejects.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsCurrencyComplianceNotice implements RegisterHooksInterface {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter Runtime owner arbiter.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Register currency compliance notice hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		add_action( 'admin_notices', array( $this, 'display_isk_decimal_notice' ) );
	}

	/**
	 * Warn when the store pairs ISK with a non-zero decimals setting.
	 *
	 * The provider treats ISK as a two-decimal minor-unit currency that only
	 * accepts whole-krona amounts, so a store left at WooCommerce's default
	 * two price decimals mints amounts the provider rejects on every charge.
	 *
	 * One deviation from the reference client: it calls esc_html() on the
	 * currency code without echoing it (a dropped echo), so its bold prefix
	 * renders without the code; native echoes it.
	 *
	 * @internal
	 */
	public function display_isk_decimal_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( get_woocommerce_currency() === 'ISK' && wc_get_price_decimals() !== 0 ) {
			$url = get_admin_url( null, 'admin.php?page=wc-settings' );

			?>
			<div id="wcpay-unsupported-currency-notice" class="notice notice-error">
				<p>
					<b>
						<?php esc_html_e( 'Unsupported currency:', 'woocommerce' ); ?>
						<?php echo esc_html( ' ' . get_woocommerce_currency() ); ?>
					</b>
					<?php
						echo wp_kses_post(
							sprintf(
								/* Translators: %1$s: Opening anchor tag. %2$s: Closing anchor tag.*/
								__( 'Icelandic Króna does not accept decimals. Please update your currency number of decimals to 0 or select a different currency. %1$sVisit settings%2$s', 'woocommerce' ),
								'<a href="' . $url . '">',
								'</a>'
							)
						);
					?>
				</p>
			</div>
			<?php
		}
	}
}
