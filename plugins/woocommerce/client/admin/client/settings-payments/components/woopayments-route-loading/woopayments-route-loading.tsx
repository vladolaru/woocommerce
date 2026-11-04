/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Shows that a WooPayments page is loading.
 */
export const WooPaymentsRouteLoading = () => (
	<div role="status" aria-live="polite" aria-busy="true">
		{ sprintf(
			/* translators: %s: payments provider name. */
			__( 'Loading %s…', 'woocommerce' ),
			'WooPayments'
		) }
	</div>
);
