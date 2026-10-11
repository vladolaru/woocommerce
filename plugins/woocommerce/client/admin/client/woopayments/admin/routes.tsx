/**
 * External dependencies
 */
import { Card, CardBody } from '@wordpress/components';
import { lazy, Suspense, useEffect } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { ProviderRouteLoading } from '~/settings-payments/components/provider-route-loading';
import { getSettingsPaymentsProviderRouteUrl } from './utils';
import { registerPaymentsRuntimeTracksPropertyWhenPreloaded } from '../tracks-runtime';

// A native link on a store the plugin owns also loads these routes, so the core preload decides.
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
	wcpaySettings?: {
		featureFlags?: {
			reportsArea?: boolean;
		};
	};
};

const getReportsAreaFeatureFlag = () => {
	const settings = globalThis as WooPaymentsRouteWindow;
	const nativeFlag =
		settings.wcSettings?.admin?.woopaymentsSettings?.featureFlags
			?.reportsArea;

	if ( typeof nativeFlag === 'boolean' ) {
		return nativeFlag;
	}

	const legacyFlag = settings.wcpaySettings?.featureFlags?.reportsArea;

	return typeof legacyFlag === 'boolean' ? legacyFlag : true;
};

const getAdminRouteAvailability = () => {
	const settings = globalThis as WooPaymentsRouteWindow;

	return settings.wcSettings?.admin?.woopaymentsSettings
		?.adminRouteAvailability;
};

const getAllowedRoutes = () => getAdminRouteAvailability()?.allowedRoutes;

const isRouteAvailable = ( routePath: string ) => {
	return getAllowedRoutes()?.[ routePath ] === true;
};

const getFallbackRoutePath = () => {
	return getAllowedRoutes()?.[ '/woopayments/overview' ] === true
		? '/woopayments/overview'
		: '/woopayments/settings';
};

const LoadingFallback = () => (
	<ProviderRouteLoading providerName="WooPayments" />
);

const WooPaymentsAdminAreaUnavailable = () => {
	const fallbackPath = getFallbackRoutePath();
	const fallbackLabel =
		fallbackPath === '/woopayments/overview'
			? __( 'Go to WooPayments overview', 'woocommerce' )
			: __( 'Go to WooPayments settings', 'woocommerce' );

	// Monitor row L13: the message sits in a card, as the client's unavailable pages do.
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
			<Suspense fallback={ <LoadingFallback /> }>{ children }</Suspense>
		) : (
			<WooPaymentsAdminAreaUnavailable />
		) }
	</>
);

const WooPaymentsReportsRoute = () => {
	if ( ! isRouteAvailable( '/woopayments/reports' ) ) {
		return <WooPaymentsAdminAreaUnavailable />;
	}

	if ( getReportsAreaFeatureFlag() ) {
		return (
			<Suspense fallback={ <LoadingFallback /> }>
				<WooPaymentsReportsChunk />
			</Suspense>
		);
	}

	return <WooPaymentsReportsUnavailable />;
};

/**
 * The WooPayments routes of the Payments settings app, which reads them once this chunk loads, in this order.
 */
export const woopaymentsProviderRoutes: Array< {
	path: string;
	element: ReactNode;
} > = [
	{
		path: '/woopayments/settings',
		element: (
			<Suspense fallback={ <LoadingFallback /> }>
				<WooPaymentsSettingsChunk />
			</Suspense>
		),
	},
	{
		path: '/woopayments/settings/express-checkout/:methodId',
		element: (
			<Suspense fallback={ <LoadingFallback /> }>
				<WooPaymentsExpressCheckoutSettingsChunk />
			</Suspense>
		),
	},
	{
		path: '/woopayments/settings/fraud-protection',
		element: (
			<Suspense fallback={ <LoadingFallback /> }>
				<WooPaymentsFraudProtectionSettingsChunk />
			</Suspense>
		),
	},
	{
		path: '/woopayments/overview',
		element: (
			<WooPaymentsProtectedRoute path="/woopayments/overview">
				<WooPaymentsOverviewChunk />
			</WooPaymentsProtectedRoute>
		),
	},
	{
		path: '/woopayments/payouts',
		element: (
			<WooPaymentsProtectedRoute path="/woopayments/payouts">
				<WooPaymentsPayoutsChunk />
			</WooPaymentsProtectedRoute>
		),
	},
	{
		path: '/woopayments/payouts/details',
		element: (
			<WooPaymentsProtectedRoute path="/woopayments/payouts/details">
				<WooPaymentsPayoutDetailsChunk />
			</WooPaymentsProtectedRoute>
		),
	},
	{
		path: '/woopayments/transactions',
		element: (
			<WooPaymentsProtectedRoute path="/woopayments/transactions">
				<WooPaymentsTransactionsChunk />
			</WooPaymentsProtectedRoute>
		),
	},
	{
		path: '/woopayments/transactions/details',
		element: (
			<WooPaymentsProtectedRoute path="/woopayments/transactions/details">
				<WooPaymentsTransactionDetailsChunk />
			</WooPaymentsProtectedRoute>
		),
	},
	{
		path: '/woopayments/reports',
		element: (
			<>
				<WooPaymentsDocumentTitle path="/woopayments/reports" />
				<WooPaymentsReportsRoute />
			</>
		),
	},
	{
		path: '/woopayments/disputes',
		element: (
			<WooPaymentsProtectedRoute path="/woopayments/disputes">
				<WooPaymentsDisputesChunk />
			</WooPaymentsProtectedRoute>
		),
	},
	{
		path: '/woopayments/disputes/details',
		element: (
			<WooPaymentsProtectedRoute path="/woopayments/disputes/details">
				<WooPaymentsDisputeDetailsChunk />
			</WooPaymentsProtectedRoute>
		),
	},
	{
		path: '/woopayments/disputes/challenge',
		element: (
			<WooPaymentsProtectedRoute path="/woopayments/disputes/challenge">
				<WooPaymentsDisputeChallengeChunk />
			</WooPaymentsProtectedRoute>
		),
	},
	{
		path: '/woopayments/card-readers',
		element: (
			<WooPaymentsProtectedRoute path="/woopayments/card-readers">
				<WooPaymentsCardReadersChunk />
			</WooPaymentsProtectedRoute>
		),
	},
	{
		path: '/woopayments/loans',
		element: (
			<WooPaymentsProtectedRoute path="/woopayments/loans">
				<WooPaymentsCapitalChunk />
			</WooPaymentsProtectedRoute>
		),
	},
	{
		path: '/woopayments/documents',
		element: (
			<WooPaymentsProtectedRoute path="/woopayments/documents">
				<WooPaymentsDocumentsChunk />
			</WooPaymentsProtectedRoute>
		),
	},
];
