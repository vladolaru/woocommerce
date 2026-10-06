<?php
/**
 * WooPaymentsVatDetailsRedirect class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Sends a `?woopayments-vat-details-redirect` link to the WooPayments settings with the VAT details modal open.
 *
 * The link lands on a front-end URL, where `template_redirect` fires, so this loads on front-end requests of a
 * connected store, at the client's priority (client 11.1.0 `includes/class-wc-payments-vat-redirect-service.php:25-27`).
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsVatDetailsRedirect implements RegisterHooksInterface {

	private const REDIRECT_QUERY_ARG = 'woopayments-vat-details-redirect';

	private const MODAL_QUERY_ARG = 'woopayments-vat-details-modal';

	/**
	 * Register the redirect.
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'handle_template_redirect' ), 1 );
	}

	/**
	 * Handle the template_redirect action.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function handle_template_redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect, as in the client.
		if ( ! isset( $_GET[ self::REDIRECT_QUERY_ARG ] ) ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( wp_doing_ajax() ) {
			return;
		}

		wp_safe_redirect( Utils::wc_payments_settings_url( '/woopayments/settings', array( self::MODAL_QUERY_ARG => 'true' ) ) );
		exit;
	}
}
