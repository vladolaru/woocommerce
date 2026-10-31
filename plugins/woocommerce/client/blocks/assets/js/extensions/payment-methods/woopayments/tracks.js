/**
 * Internal dependencies
 */
import { tracks } from './common';

// The tracking values the shared Tracks helpers read, taken from a Blocks payment method's settings.
const getTracksArgs = ( paymentSettings ) => ( {
	isShopperTrackingEnabled: paymentSettings?.isShopperTrackingEnabled,
	tracksUrl: paymentSettings?.tracksUrl,
	platformTrackerNonce: paymentSettings?.platformTrackerNonce,
	tracksRestNonce: paymentSettings?.tracksRestNonce,
	ajaxUrl: paymentSettings?.ajaxUrl,
} );

/**
 * Record a WooPayments shopper event through the shopper Tracks REST route.
 *
 * @param {Object} paymentSettings Payment method settings.
 * @param {string} eventName       Event name without the wcpay_ prefix.
 * @param {Object} eventProperties Event properties.
 */
export const recordWooPaymentsUserEvent = (
	paymentSettings,
	eventName,
	eventProperties = {}
) =>
	tracks.recordEvent(
		getTracksArgs( paymentSettings ),
		eventName,
		eventProperties
	);

/**
 * Resolve the stringified Tracks identity WooPay stitches its own events to. Never rejects.
 *
 * @param {Object} paymentSettings Payment method settings.
 * @return {Promise<string|undefined>} The identity, or undefined when unavailable.
 */
export const getTracksIdentity = ( paymentSettings ) =>
	tracks.getIdentity( getTracksArgs( paymentSettings ) );
