/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Header } from '~/settings-payments/components/header/header';
import './style.scss';

declare global {
	interface Window {
		ppcpSettings?: Record< string, unknown >;
	}
}

const RELOAD_MARKER_KEY = 'wc_paypal_wallet_settings_reloaded_at';
const RELOAD_RETRY_DELAY_MS = 10000;

/**
 * Loads this page from the server when it was reached without the wallet's settings app on the page.
 *
 * A client-side navigation from another Payments settings screen only loads the app when that screen's request enqueued
 * it. A page load enqueues it, so one reload fixes the page. A marker keeps a missing bundle from reloading in a loop.
 */
const reloadWhenSettingsAppIsMissing = () => {
	if ( window.ppcpSettings ) {
		return;
	}

	try {
		const reloadedAt = Number(
			window.sessionStorage.getItem( RELOAD_MARKER_KEY )
		);

		if ( Date.now() - reloadedAt < RELOAD_RETRY_DELAY_MS ) {
			return;
		}

		window.sessionStorage.setItem(
			RELOAD_MARKER_KEY,
			String( Date.now() )
		);
	} catch {
		// Without storage a loop cannot be ruled out, so leave the page as it is.
		return;
	}

	window.location.reload();
};

/**
 * Runs the check once the page has finished loading. On a page load the localized settings are printed after the admin
 * app first renders, so an earlier check would reload a page that is about to have them.
 *
 * @return A cleanup that removes the pending load listener.
 */
const reloadWhenSettingsAppIsMissingAfterLoad = () => {
	if ( document.readyState === 'complete' ) {
		reloadWhenSettingsAppIsMissing();
		return undefined;
	}

	window.addEventListener( 'load', reloadWhenSettingsAppIsMissing, {
		once: true,
	} );

	return () =>
		window.removeEventListener( 'load', reloadWhenSettingsAppIsMissing );
};

/**
 * The PayPal Wallet settings route of the Payments settings shell. The wallet's own settings app (built by
 * `@woocommerce/paypal-wallet`, enqueued by core on this page) mounts into the container below and renders the page
 * header itself, with the title, the back arrow, Save and the tabs.
 */
export const PayPalWalletSettingsRoute = () => {
	useEffect( reloadWhenSettingsAppIsMissingAfterLoad, [] );

	return (
		<>
			<Header title={ __( 'Settings', 'woocommerce' ) } />
			<div className="paypal-wallet-settings">
				<div id="ppcp-settings-container" />
			</div>
		</>
	);
};
