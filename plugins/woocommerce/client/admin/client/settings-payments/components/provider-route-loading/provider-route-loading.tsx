/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Shows that a payments provider page is loading.
 */
export const ProviderRouteLoading = ( {
	providerName,
}: {
	providerName: string;
} ) => (
	<div role="status" aria-live="polite" aria-busy="true">
		{ sprintf(
			/* translators: %s: payments provider name. */
			__( 'Loading %s…', 'woocommerce' ),
			providerName
		) }
	</div>
);
