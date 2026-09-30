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
	 * Account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter         Runtime owner arbiter.
	 * @param WooPaymentsAccountService    $account_service Account service.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsAccountService $account_service ): void {
		$this->arbiter         = $arbiter;
		$this->account_service = $account_service;
	}

	/**
	 * Register currency compliance notice hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		add_action( 'admin_notices', array( $this, 'display_not_supported_currency_notice' ), 9999 );
		add_action( 'admin_notices', array( $this, 'display_isk_decimal_notice' ) );
	}

	/**
	 * Warn when the connected account does not support the store currency.
	 *
	 * Client 11.1.0 `WC_Payments_Admin::display_not_supported_currency_notice()`: an empty
	 * supported list never warns, and the bold label carries no currency code.
	 *
	 * @internal
	 */
	public function display_not_supported_currency_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$supported_currencies = $this->account_service->get_customer_supported_currencies();
		if ( array() === $supported_currencies || in_array( strtolower( get_woocommerce_currency() ), $supported_currencies, true ) ) {
			return;
		}

		?>
		<div id="wcpay-unsupported-currency-notice" class="notice notice-warning">
			<p>
				<b><?php esc_html_e( 'Unsupported currency:', 'woocommerce' ); ?></b>
				<?php
				printf(
					/* translators: %s: WooPayments */
					esc_html__( 'The selected currency is not available for the country set in your %s account.', 'woocommerce' ),
					'WooPayments'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Warn when the store pairs ISK with a non-zero decimals setting.
	 *
	 * The provider treats ISK as a two-decimal minor-unit currency that only
	 * accepts whole-krona amounts, so a store left at WooCommerce's default
	 * two price decimals mints amounts the provider rejects on every charge.
	 * Like the client, the bold label carries no currency code: the sentence names the currency.
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
					<b><?php esc_html_e( 'Unsupported currency:', 'woocommerce' ); ?></b>
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
