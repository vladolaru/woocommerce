/**
 * External dependencies
 */
import { Gridicon } from '@automattic/components';
import { Button, Placeholder, SelectControl } from '@wordpress/components';
import React, { lazy, Suspense, useEffect, useState } from '@wordpress/element';
import type { ReactNode } from 'react';
import {
	unstable_HistoryRouter as HistoryRouter,
	Route,
	Routes,
	useInRouterContext,
	useLocation,
} from 'react-router-dom';
import { getHistory, getNewPath } from '@woocommerce/navigation';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Header } from './components/header/header';
import { BackButton } from './components/buttons/back-button';
import { ListPlaceholder } from '~/settings-payments/components/list-placeholder';
import { ProviderRouteLoading } from '~/settings-payments/components/provider-route-loading';
import { getSettingsPaymentsProviderRoutes } from '~/settings-payments/provider-routes';
import { WOOPAYMENTS_SETTINGS_HEADING_ID } from '~/settings-payments/constants';
import './settings-payments-main.scss';

/**
 * Lazy-loaded chunk for the main settings page of payment gateways.
 */
const SettingsPaymentsMainChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-main" */ './settings-payments-main'
		)
);

/**
 * Lazy-loaded chunk for the offline payment gateways settings page.
 */
const SettingsPaymentsOfflineChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-offline" */ './settings-payments-offline'
		)
);

/**
 * Lazy-loaded chunk for the WooPayments settings page.
 */
const SettingsPaymentsWooPaymentsChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments" */ './settings-payments-woopayments'
		)
);

const SettingsPaymentsBacsChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-bacs" */ './offline/settings-payments-bacs'
		)
);

const SettingsPaymentsCodChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-cod" */ './offline/settings-payments-cod'
		)
);

const SettingsPaymentsChequeChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-cheque" */ './offline/settings-payments-cheque'
		)
);

interface OfflinePaymentGatewayWrapperProps {
	title: string;
	chunkComponent: React.ComponentType;
}

const OfflinePaymentGatewayWrapper = ( {
	title,
	chunkComponent: ChunkComponent,
}: OfflinePaymentGatewayWrapperProps ) => {
	useEffect( () => {
		window.scrollTo( 0, 0 ); // Scrolls to the top of the page.
	}, [] );

	return (
		<>
			<div className="settings-payments-offline__container">
				<div className="settings-payment-gateways">
					<div className="settings-payments-offline__header">
						<h1 className="components-truncate components-text woocommerce-layout__header-heading woocommerce-layout__header-left-align settings-payments-offline__header-title">
							<BackButton
								href={ getNewPath( {}, '/offline' ) }
								tooltipText={ __(
									'Return to offline payment methods',
									'woocommerce'
								) }
								isRoute={ true }
								from={ 'woopayments_payment_methods' }
							>
								<span className="woocommerce-settings-payments-header__title">
									{ title }
								</span>
							</BackButton>
						</h1>
					</div>
					<Suspense fallback={ <Placeholder /> }>
						<ChunkComponent />
					</Suspense>
				</div>
			</div>
		</>
	);
};

/**
 * Hides or displays the WooCommerce navigation tab based on the provided display style.
 */
const hideWooCommerceNavTab = ( display: string ) => {
	const externalElement = document.querySelector< HTMLElement >(
		'.woo-nav-tab-wrapper'
	);

	// Add the 'hidden' class to hide the element.
	if ( externalElement ) {
		externalElement.style.display = display;
	}
};

/**
 * Renders the main payment settings page with a fallback while loading.
 */
const SettingsPaymentsMain = () => {
	const location = useLocation();

	useEffect( () => {
		if ( location.pathname === '' ) {
			hideWooCommerceNavTab( 'flex' );
		}
	}, [ location ] );
	return (
		<>
			<Suspense
				fallback={
					<>
						<div className="settings-payments-main__container">
							<div className="settings-payment-gateways">
								<div className="settings-payment-gateways__header">
									<div className="settings-payment-gateways__header-title">
										{ __(
											'Payment providers',
											'woocommerce'
										) }
									</div>
									<div className="settings-payment-gateways__header-select-container">
										<SelectControl
											className="woocommerce-select-control__country"
											prefix={ __(
												'Business location :',
												'woocommerce'
											) }
											// @ts-expect-error placeholder was removed from SelectControl's public types but is still accepted at runtime.
											placeholder={ '' }
											label={ '' }
											options={ [] }
											onChange={ () => {} }
										/>
									</div>
								</div>
								<ListPlaceholder rows={ 5 } />
							</div>
							<div className="other-payment-gateways">
								<div className="other-payment-gateways__header">
									<div className="other-payment-gateways__header__title">
										<span>
											{ __(
												'More payment options',
												'woocommerce'
											) }
										</span>
										<>
											<div className="other-payment-gateways__header__title__image-placeholder" />
											<div className="other-payment-gateways__header__title__image-placeholder" />
											<div className="other-payment-gateways__header__title__image-placeholder" />
										</>
									</div>
									<Button
										variant={ 'link' }
										onClick={ () => {} }
										aria-expanded={ false }
									>
										<Gridicon icon="chevron-down" />
									</Button>
								</div>
							</div>
						</div>
					</>
				}
			>
				<SettingsPaymentsMainChunk />
			</Suspense>
		</>
	);
};

/**
 * Wraps the offline payment gateways settings page.
 */
export const SettingsPaymentsOfflineWrapper = () => {
	useEffect( () => {
		window.scrollTo( 0, 0 ); // Scrolls to the top of the page.
	}, [] );

	return (
		<>
			<div className="settings-payments-offline__container">
				<div className="settings-payments-offline__header">
					<h1 className="components-truncate components-text woocommerce-layout__header-heading woocommerce-layout__header-left-align">
						<BackButton
							href={ getNewPath(
								{ page: 'wc-settings', tab: 'checkout' },
								'/',
								{}
							) }
							tooltipText={ __(
								'Return to payments settings',
								'woocommerce'
							) }
							isRoute={ true }
							from={ 'woopayments_payment_methods' }
						>
							<span className="woocommerce-settings-payments-header__title">
								{ __( 'Take offline payments', 'woocommerce' ) }
							</span>
						</BackButton>
					</h1>
				</div>
				<Suspense fallback={ <ListPlaceholder rows={ 3 } /> }>
					<SettingsPaymentsOfflineChunk />
				</Suspense>
			</div>
		</>
	);
};

interface SettingsPaymentsWooPaymentsWrapperProps {
	/**
	 * The page body. Defaults to the lazy-loaded WooPayments settings chunk.
	 */
	children?: ReactNode;
}

/**
 * Wraps the WooPayments settings page under the same header as the offline payments page.
 */
export const SettingsPaymentsWooPaymentsWrapper = ( {
	children,
}: SettingsPaymentsWooPaymentsWrapperProps ) => {
	// The `section` URL renders this outside the Payments router, where a pushed route would not render.
	const isInRouter = useInRouterContext();

	useEffect( () => {
		// A section link (`anchor` or hash) is scrolled to by the page itself once settings load.
		if (
			! window.location.hash &&
			! new URLSearchParams( window.location.search ).has( 'anchor' )
		) {
			window.scrollTo( 0, 0 );
		}
	}, [] );

	return (
		<>
			<Header title={ __( 'Settings', 'woocommerce' ) } />
			<div className="settings-payments-offline__container">
				<div className="settings-payments-offline__header">
					<h1
						id={ WOOPAYMENTS_SETTINGS_HEADING_ID }
						className="components-truncate components-text woocommerce-layout__header-heading woocommerce-layout__header-left-align"
					>
						<BackButton
							href={ getNewPath(
								{ page: 'wc-settings', tab: 'checkout' },
								'/',
								{}
							) }
							tooltipText={ __(
								'Return to payments settings',
								'woocommerce'
							) }
							isRoute={ isInRouter }
							from={ 'woopayments_settings' }
						>
							<span className="woocommerce-settings-payments-header__title">
								WooPayments
							</span>
						</BackButton>
					</h1>
				</div>
				{ children ?? (
					<Suspense
						fallback={
							<div>
								{ sprintf(
									/* translators: %s: WooPayments */
									__( 'Loading %s settings…', 'woocommerce' ),
									'WooPayments'
								) }
							</div>
						}
					>
						<SettingsPaymentsWooPaymentsChunk />
					</Suspense>
				) }
			</div>
		</>
	);
};

export const SettingsPaymentsBacsWrapper = () =>
	OfflinePaymentGatewayWrapper( {
		title: __( 'Direct bank transfer', 'woocommerce' ),
		chunkComponent: SettingsPaymentsBacsChunk,
	} );

export const SettingsPaymentsCodWrapper = () =>
	OfflinePaymentGatewayWrapper( {
		title: __( 'Cash on delivery', 'woocommerce' ),
		chunkComponent: SettingsPaymentsCodChunk,
	} );

export const SettingsPaymentsChequeWrapper = () =>
	OfflinePaymentGatewayWrapper( {
		title: __( 'Check payments', 'woocommerce' ),
		chunkComponent: SettingsPaymentsChequeChunk,
	} );

let nativeProviderRoutesLoaded = false;
let nativeProviderRoutesRequest: Promise< void > | undefined;

/**
 * Loads the Core-owned WooPayments routes once, only when a Payments path needs them.
 */
const loadNativeProviderRoutes = () => {
	if ( ! nativeProviderRoutesRequest ) {
		nativeProviderRoutesRequest = import(
			/* webpackChunkName: "settings-payments-woopayments-routes" */ './register-provider-routes'
		).then( () => {
			nativeProviderRoutesLoaded = true;
		} );
	}

	return nativeProviderRoutesRequest;
};

// The WooPayments onboarding modal opens over the main page, which owns `/woopayments/onboarding`.
const needsNativeProviderRoutes = ( pathname: string ) =>
	( pathname === '/woopayments' || pathname.startsWith( '/woopayments/' ) ) &&
	! pathname.startsWith( '/woopayments/onboarding' );

/**
 * Renders the Payments routes, loading the WooPayments routes first when the current path needs them.
 */
const SettingsPaymentsRoutes = () => {
	// The router's location also updates on in-app navigation, so this covers direct loads and pushes alike.
	const { pathname } = useLocation();
	const [ hasNativeProviderRoutes, setHasNativeProviderRoutes ] = useState(
		nativeProviderRoutesLoaded
	);
	const isLoadingNativeProviderRoutes =
		! hasNativeProviderRoutes && needsNativeProviderRoutes( pathname );

	useEffect( () => {
		if ( ! isLoadingNativeProviderRoutes ) {
			return;
		}

		let isMounted = true;
		void loadNativeProviderRoutes().then( () => {
			if ( isMounted ) {
				setHasNativeProviderRoutes( true );
			}
		} );

		return () => {
			isMounted = false;
		};
	}, [ isLoadingNativeProviderRoutes ] );

	if ( isLoadingNativeProviderRoutes ) {
		return <ProviderRouteLoading providerName="WooPayments" />;
	}

	const providerRoutes = getSettingsPaymentsProviderRoutes();

	return (
		<Routes>
			<Route
				path="/offline"
				element={ <SettingsPaymentsOfflineWrapper /> }
			/>
			<Route
				path="/offline/bacs"
				element={ <SettingsPaymentsBacsWrapper /> }
			/>
			<Route
				path="/offline/cod"
				element={ <SettingsPaymentsCodWrapper /> }
			/>
			<Route
				path="/offline/cheque"
				element={ <SettingsPaymentsChequeWrapper /> }
			/>
			{ providerRoutes.map( ( route ) => (
				<Route
					key={ route.id }
					path={ route.path }
					element={ route.element }
				/>
			) ) }
			<Route path="/*" element={ <SettingsPaymentsMain /> } />
		</Routes>
	);
};

/**
 * Wraps the main payment settings and payment methods settings pages.
 */
export const SettingsPaymentsMainWrapper = () => (
	<>
		<Header title={ __( 'Settings', 'woocommerce' ) } />
		<HistoryRouter history={ getHistory() }>
			<SettingsPaymentsRoutes />
		</HistoryRouter>
	</>
);
