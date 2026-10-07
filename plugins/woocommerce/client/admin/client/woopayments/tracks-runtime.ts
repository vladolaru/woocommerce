/**
 * External dependencies
 */
import { addFilter, hasFilter } from '@wordpress/hooks';

// Core's admin recorder runs every event through this filter, with the prefixed event name
// (`includes/tracks/class-wc-site-tracking.php`, `window.wcTracks.recordEvent`).
const EVENT_PROPERTIES_FILTER = 'woocommerce_tracks_client_event_properties';
const FILTER_NAMESPACE = 'woocommerce/woopayments/payments-runtime';

export const PAYMENTS_RUNTIME_PROPERTY = 'payments_runtime';
export const PAYMENTS_RUNTIME = 'woocommerce_core';

const isRecord = ( value: unknown ): value is Record< string, unknown > =>
	typeof value === 'object' && value !== null && ! Array.isArray( value );

// The native WooPayments admin events keep the client's names; trunk's own payments events
// (such as `payments_task_stepper_view`) do not match.
const isWooPaymentsAdminEvent = (
	eventName: string,
	properties: Record< string, unknown >
) =>
	eventName.startsWith( 'wcadmin_wcpay_' ) ||
	eventName.startsWith( 'wcadmin_payments_transactions_' ) ||
	( eventName === 'wcadmin_page_view' &&
		typeof properties.path === 'string' &&
		properties.path.startsWith( 'payments_' ) );

/**
 * Mark a native WooPayments admin event as recorded by the WooCommerce core runtime.
 *
 * @param properties The event properties, as another filter callback may have left them.
 * @param eventName  The prefixed event name.
 * @return The properties, with the runtime for a native WooPayments event.
 */
export const addPaymentsRuntimeProperty = (
	properties: unknown,
	eventName: unknown
) => {
	if (
		! isRecord( properties ) ||
		typeof eventName !== 'string' ||
		! isWooPaymentsAdminEvent( eventName, properties )
	) {
		return properties;
	}

	return {
		...properties,
		[ PAYMENTS_RUNTIME_PROPERTY ]: PAYMENTS_RUNTIME,
	};
};

/**
 * Register the filter once per page; each native entry bundles its own copy of this module. Call it only where native
 * owns the runtime: the plugin's own `wcadmin_wcpay_*` events carry the same names.
 */
export const registerPaymentsRuntimeTracksProperty = () => {
	if ( hasFilter( EVENT_PROPERTIES_FILTER, FILTER_NAMESPACE ) ) {
		return;
	}

	addFilter(
		EVENT_PROPERTIES_FILTER,
		FILTER_NAMESPACE,
		addPaymentsRuntimeProperty
	);
};

/**
 * Register the filter on a Payments settings page only when core preloaded `woopaymentsSettings`, which it does only
 * while native owns the runtime (`WooPaymentsAdminNavigationController::preload_shared_settings()`). A native route
 * reached on a plugin-owned store has no preload.
 */
export const registerPaymentsRuntimeTracksPropertyWhenPreloaded = () => {
	const preloaded = (
		window as typeof window & {
			wcSettings?: { admin?: { woopaymentsSettings?: unknown } };
		}
	 ).wcSettings?.admin?.woopaymentsSettings;

	if ( isRecord( preloaded ) ) {
		registerPaymentsRuntimeTracksProperty();
	}
};
