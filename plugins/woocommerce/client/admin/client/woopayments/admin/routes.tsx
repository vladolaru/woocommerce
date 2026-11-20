/**
 * External dependencies
 */
import { Card, CardBody } from '@wordpress/components';
import { lazy, Suspense, useEffect } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';
import type { ComponentType, ReactNode } from 'react';
import { Route, Routes } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { WooPaymentsRouteLoading } from '~/settings-payments/components/woopayments-route-loading';
import { getSettingsPaymentsProviderRouteUrl } from './utils';
import { registerPaymentsRuntimeTracksPropertyWhenPreloaded } from '../tracks-runtime';

// A built-in store not yet connected also loads these routes, without the core preload, so the preload decides.
registerPaymentsRuntimeTracksPropertyWhenPreloaded();

// Share the chunk name with the Payments settings tab in `settings-payments/index.tsx`
// so the settings page ships once instead of in two identical chunks.
const WooPaymentsSettingsChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments" */ '../settings'
		)
);

const WooPaymentsExpressCheckoutSettingsChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-express-checkout-settings" */ '../settings/express-checkout'
		)
);

const WooPaymentsFraudProtectionSettingsChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-fraud-protection-settings" */ '../settings/fraud-protection/advanced'
		)
);

const WooPaymentsOverviewChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-overview" */ './overview'
		)
);

const WooPaymentsPayoutsChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-payouts" */ './payouts'
		)
);

const WooPaymentsPayoutDetailsChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-payouts" */ './payout-details'
		)
);

const WooPaymentsTransactionsChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-money-movement" */ './money-movement/transactions'
		)
);

const WooPaymentsTransactionDetailsChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-money-movement" */ './money-movement/transaction-details'
		)
);

const WooPaymentsReportsChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-reports" */ './reports'
		)
);

const WooPaymentsDisputesChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-money-movement" */ './money-movement/disputes'
		)
);

const WooPaymentsDisputeDetailsChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-money-movement" */ './money-movement/disputes-details'
		)
);

const WooPaymentsDisputeChallengeChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-money-movement" */ './money-movement/dispute-challenge'
		)
);

const WooPaymentsCardReadersChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-card-readers" */ './card-readers'
		)
);

const WooPaymentsCapitalChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-capital" */ './capital'
		)
);

const WooPaymentsDocumentsChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-documents" */ './documents'
		)
);

type WooPaymentsRouteWindow = typeof globalThis & {
	wcSettings?: {
		siteTitle?: string;
		admin?: {
			woopaymentsSettings?: {
				featureFlags?: {
					reportsArea?: boolean;
				};
				adminRouteAvailability?: {
					gatewayEnabled?: boolean;
					accountState?: string;
					allowedRoutes?: Record< string, boolean >;
				};
			};
		};
	};
};

// The admin preload sends the flag with the route availability, so it is set wherever the Reports route is available.
const getReportsAreaFeatureFlag = () =>
	( globalThis as WooPaymentsRouteWindow ).wcSettings?.admin
		?.woopaymentsSettings?.featureFlags?.reportsArea === true;

const isRouteAvailable = ( routePath: string ) =>
	( globalThis as WooPaymentsRouteWindow ).wcSettings?.admin
		?.woopaymentsSettings?.adminRouteAvailability?.allowedRoutes?.[
		routePath
	] === true;

const getFallbackRoutePath = () =>
	isRouteAvailable( '/woopayments/overview' )
		? '/woopayments/overview'
		: '/woopayments/settings';

const WooPaymentsAdminAreaUnavailable = () => {
	const fallbackPath = getFallbackRoutePath();
	const fallbackLabel =
		fallbackPath === '/woopayments/overview'
			? __( 'Go to WooPayments overview', 'woocommerce' )
			: __( 'Go to WooPayments settings', 'woocommerce' );

	// The message sits in a card.
	return (
		<Card>
			<CardBody role="status" aria-live="polite">
				<p>
					{ __(
						'This WooPayments admin area is unavailable.',
						'woocommerce'
					) }
				</p>
				<p>
					{ __(
						'Your current account status does not allow access to this page.',
						'woocommerce'
					) }
				</p>
				<a href={ getSettingsPaymentsProviderRouteUrl( fallbackPath ) }>
					{ fallbackLabel }
				</a>
			</CardBody>
		</Card>
	);
};

const WooPaymentsReportsUnavailable = () => (
	<Card>
		<CardBody role="status" aria-live="polite">
			{ __( 'Reports are unavailable.', 'woocommerce' ) }
		</CardBody>
	</Card>
);

// Client 11.1.0 `client/index.js:184-350` page breadcrumbs, the page first.
const getRouteTitleSections = ( routePath: string ): string[] =>
	( {
		'/woopayments/overview': [ __( 'Overview', 'woocommerce' ) ],
		'/woopayments/payouts': [ __( 'Payouts', 'woocommerce' ) ],
		'/woopayments/payouts/details': [
			__( 'Payout details', 'woocommerce' ),
			__( 'Payouts', 'woocommerce' ),
		],
		'/woopayments/transactions': [ __( 'Transactions', 'woocommerce' ) ],
		'/woopayments/transactions/details': [
			__( 'Payment details', 'woocommerce' ),
			__( 'Transactions', 'woocommerce' ),
		],
		'/woopayments/reports': [ __( 'Reports', 'woocommerce' ) ],
		'/woopayments/disputes': [ __( 'Disputes', 'woocommerce' ) ],
		'/woopayments/disputes/details': [
			__( 'Dispute details', 'woocommerce' ),
			__( 'Disputes', 'woocommerce' ),
		],
		'/woopayments/disputes/challenge': [
			__( 'Challenge dispute', 'woocommerce' ),
			__( 'Disputes', 'woocommerce' ),
		],
		'/woopayments/card-readers': [ __( 'Card readers', 'woocommerce' ) ],
		'/woopayments/loans': [ __( 'Capital Loans', 'woocommerce' ) ],
		'/woopayments/documents': [ __( 'Documents', 'woocommerce' ) ],
	} )[ routePath ] ?? [];

const getRouteTitle = ( routePath: string ) =>
	[
		...getRouteTitleSections( routePath ),
		__( 'Payments', 'woocommerce' ),
	].join( ' &lsaquo; ' );

/**
 * Names the browser tab after the page, as client 11.1.0 `client/index.js` breadcrumbs do through WooCommerce admin's header.
 * The tab gets its previous title back when the page unmounts.
 *
 * @param props      The component props.
 * @param props.path The route path, such as `/woopayments/transactions/details`.
 */
const WooPaymentsDocumentTitle = ( { path: routePath }: { path: string } ) => {
	const title = getRouteTitle( routePath );

	useEffect( () => {
		const previousTitle = document.title;
		const siteTitle =
			( globalThis as WooPaymentsRouteWindow ).wcSettings?.siteTitle ??
			'';

		document.title = decodeEntities(
			sprintf(
				/* translators: 1: The page title. 2: The name of the website. */
				__( '%1$s &lsaquo; %2$s &#8212; WooCommerce', 'woocommerce' ),
				title,
				siteTitle
			)
		);

		return () => {
			document.title = previousTitle;
		};
	}, [ title ] );

	return null;
};

const WooPaymentsProtectedRoute = ( {
	children,
	path: routePath,
}: {
	children: JSX.Element;
	path: string;
} ) => (
	<>
		<WooPaymentsDocumentTitle path={ routePath } />
		{ isRouteAvailable( routePath ) ? (
			<Suspense fallback={ <WooPaymentsRouteLoading /> }>
				{ children }
			</Suspense>
		) : (
			<WooPaymentsAdminAreaUnavailable />
		) }
	</>
);

/**
 * Shows the Reports page only when the route table allows it and `featureFlags.reportsArea` is on. The two can differ: the route also needs full account access, and a `wcpay_js_settings` callback may turn the flag off.
 */
const WooPaymentsReportsRoute = () => {
	if ( ! isRouteAvailable( '/woopayments/reports' ) ) {
		return <WooPaymentsAdminAreaUnavailable />;
	}

	if ( getReportsAreaFeatureFlag() ) {
		return (
			<Suspense fallback={ <WooPaymentsRouteLoading /> }>
				<WooPaymentsReportsChunk />
			</Suspense>
		);
	}

	return <WooPaymentsReportsUnavailable />;
};

type WooPaymentsRoute = { path: string; element: ReactNode };

const settingsRoute = (
	path: string,
	Chunk: ComponentType
): WooPaymentsRoute => ( {
	path,
	element: (
		<Suspense fallback={ <WooPaymentsRouteLoading /> }>
			<Chunk />
		</Suspense>
	),
} );

const protectedRoute = (
	path: string,
	Chunk: ComponentType
): WooPaymentsRoute => ( {
	path,
	element: (
		<WooPaymentsProtectedRoute path={ path }>
			<Chunk />
		</WooPaymentsProtectedRoute>
	),
} );

/**
 * The WooPayments routes of the Payments settings app, in the order `WooPaymentsRouter` registers them.
 */
export const wooPaymentsRoutes: WooPaymentsRoute[] = [
	settingsRoute( '/woopayments/settings', WooPaymentsSettingsChunk ),
	settingsRoute(
		'/woopayments/settings/express-checkout/:methodId',
		WooPaymentsExpressCheckoutSettingsChunk
	),
	settingsRoute(
		'/woopayments/settings/fraud-protection',
		WooPaymentsFraudProtectionSettingsChunk
	),
	protectedRoute( '/woopayments/overview', WooPaymentsOverviewChunk ),
	protectedRoute( '/woopayments/payouts', WooPaymentsPayoutsChunk ),
	protectedRoute(
		'/woopayments/payouts/details',
		WooPaymentsPayoutDetailsChunk
	),
	protectedRoute( '/woopayments/transactions', WooPaymentsTransactionsChunk ),
	protectedRoute(
		'/woopayments/transactions/details',
		WooPaymentsTransactionDetailsChunk
	),
	{
		path: '/woopayments/reports',
		element: (
			<>
				<WooPaymentsDocumentTitle path="/woopayments/reports" />
				<WooPaymentsReportsRoute />
			</>
		),
	},
	protectedRoute( '/woopayments/disputes', WooPaymentsDisputesChunk ),
	protectedRoute(
		'/woopayments/disputes/details',
		WooPaymentsDisputeDetailsChunk
	),
	protectedRoute(
		'/woopayments/disputes/challenge',
		WooPaymentsDisputeChallengeChunk
	),
	protectedRoute( '/woopayments/card-readers', WooPaymentsCardReadersChunk ),
	protectedRoute( '/woopayments/loans', WooPaymentsCapitalChunk ),
	protectedRoute( '/woopayments/documents', WooPaymentsDocumentsChunk ),
];

/**
 * Renders the WooPayments routes under the Payments settings route `/woopayments/*`, and `fallback` on any other path there.
 *
 * @param props          The component props.
 * @param props.fallback What a WooPayments path without a page renders.
 */
const WooPaymentsRouter = ( { fallback }: { fallback: ReactNode } ) => (
	<Routes>
		{ wooPaymentsRoutes.map( ( route ) => (
			<Route
				key={ route.path }
				// The parent route matched `/woopayments`, so these paths are relative to it.
				path={ route.path.slice( '/woopayments'.length ) }
				element={ route.element }
			/>
		) ) }
		<Route path="*" element={ fallback } />
	</Routes>
);

export default WooPaymentsRouter;
