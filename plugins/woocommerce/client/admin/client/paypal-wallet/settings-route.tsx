/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import { Component, lazy, Suspense } from '@wordpress/element';
import { Notice, Spinner } from '@wordpress/components';
import type { ReactNode } from 'react';

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

/**
 * The wallet's settings app, loaded on the first visit to the route. Its data (`window.ppcpSettings`) and stylesheet
 * come with the `ppcp-admin-settings` script that core enqueues on this route.
 */
const PayPalWalletSettingsApp = lazy(
	() => import( /* webpackChunkName: "paypal-wallet-settings-app" */ './app' )
);

/**
 * Tells the merchant the settings could not be shown, with a link that loads this route from the server. The link is
 * a full page load because both failures it covers need one: the page request is what enqueues the settings data,
 * and a failed chunk import stays failed until the page loads again.
 */
const SettingsUnavailableNotice = () => (
	<Notice
		className="paypal-wallet-settings__notice"
		status="error"
		isDismissible={ false }
	>
		{ __(
			'The PayPal Wallet settings could not be loaded.',
			'woocommerce'
		) }{ ' ' }
		<a href={ window.location.href.split( '#' )[ 0 ] }>
			{ __( 'Reload the page', 'woocommerce' ) }
		</a>
	</Notice>
);

/**
 * Shows the notice instead of a blank screen when the app throws, for example when its chunk fails to download.
 */
class SettingsAppBoundary extends Component<
	{ children: ReactNode },
	{ hasError: boolean }
> {
	state = { hasError: false };

	static getDerivedStateFromError() {
		return { hasError: true };
	}

	render() {
		return this.state.hasError ? (
			<SettingsUnavailableNotice />
		) : (
			this.props.children
		);
	}
}

/**
 * The PayPal Wallet settings route of the Payments settings shell. The wallet's settings app renders the page header
 * itself, with the title, the back arrow, Save and the tabs. Its styles and scroll targets are scoped to the
 * `ppcp-settings-container` ID.
 *
 * Every link to the route is a full page load today, so `ppcpSettings` is on the page. A client-side navigation would
 * reach the route without it, and the app cannot run then, so the route shows the notice instead.
 */
export const PayPalWalletSettingsRoute = () => (
	<>
		<Header title={ __( 'Settings', 'woocommerce' ) } />
		<div className="paypal-wallet-settings" id="ppcp-settings-container">
			{ window.ppcpSettings ? (
				<SettingsAppBoundary>
					<Suspense fallback={ <Spinner /> }>
						<PayPalWalletSettingsApp />
					</Suspense>
				</SettingsAppBoundary>
			) : (
				<SettingsUnavailableNotice />
			) }
		</div>
	</>
);
