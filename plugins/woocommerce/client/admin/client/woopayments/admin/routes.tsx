/**
 * External dependencies
 */
import { Card, CardBody } from '@wordpress/components';
import { lazy, Suspense, useEffect } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { registerSettingsPaymentsProviderRoute } from '~/settings-payments/provider-routes';
import { ProviderRouteLoading } from '~/settings-payments/components/provider-route-loading';
import { getSettingsPaymentsProviderRouteUrl } from './utils';

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

/**
 * Core preloads the native settings only while native owns the WooPayments runtime
 * (`WooPaymentsAdminNavigationController::register()`), so their absence means the plugin owns it.
 */
const isNativeRuntimeOwner = () =>
	( globalThis as WooPaymentsRouteWindow ).wcSettings?.admin
		?.woopaymentsSettings !== undefined;

// The plugin gateway's settings page, the URL `WooPayments::get_settings_url()` gives while the plugin owns the runtime.
const getPluginSettingsUrl = () => {
	const adminUrl = window.wcSettings?.adminUrl || '';
	const separator = adminUrl.endsWith( '/' ) || adminUrl === '' ? '' : '/';

	return `${ adminUrl }${ separator }admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments&from=WCADMIN_PAYMENT_SETTINGS`;
};

/**
 * Renders a native settings route only while native owns the runtime; otherwise a bookmark or a stale link goes to
 * the plugin's settings page instead of a native page with nothing preloaded.
 *
 * @param props          The component props.
 * @param props.children The settings route chunk.
 */
const WooPaymentsSettingsRoute = ( {
	children,
}: {
	children: JSX.Element;
} ) => {
	const isOwner = isNativeRuntimeOwner();

	useEffect( () => {
		if ( ! isOwner ) {
			window.location.replace( getPluginSettingsUrl() );
		}
	}, [ isOwner ] );

	if ( ! isOwner ) {
		return <LoadingFallback />;
	}

	return <Suspense fallback={ <LoadingFallback /> }>{ children }</Suspense>;
};

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

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-settings',
	path: '/woopayments/settings',
	order: 90,
	element: (
		<WooPaymentsSettingsRoute>
			<WooPaymentsSettingsChunk />
		</WooPaymentsSettingsRoute>
	),
} );

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-express-checkout-settings',
	path: '/woopayments/settings/express-checkout/:methodId',
	order: 91,
	element: (
		<WooPaymentsSettingsRoute>
			<WooPaymentsExpressCheckoutSettingsChunk />
		</WooPaymentsSettingsRoute>
	),
} );

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-fraud-protection-settings',
	path: '/woopayments/settings/fraud-protection',
	order: 92,
	element: (
		<WooPaymentsSettingsRoute>
			<WooPaymentsFraudProtectionSettingsChunk />
		</WooPaymentsSettingsRoute>
	),
} );

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-overview',
	path: '/woopayments/overview',
	order: 100,
	element: (
		<WooPaymentsProtectedRoute path="/woopayments/overview">
			<WooPaymentsOverviewChunk />
		</WooPaymentsProtectedRoute>
	),
} );

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-payouts',
	path: '/woopayments/payouts',
	order: 110,
	element: (
		<WooPaymentsProtectedRoute path="/woopayments/payouts">
			<WooPaymentsPayoutsChunk />
		</WooPaymentsProtectedRoute>
	),
} );

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-payout-details',
	path: '/woopayments/payouts/details',
	order: 111,
	element: (
		<WooPaymentsProtectedRoute path="/woopayments/payouts/details">
			<WooPaymentsPayoutDetailsChunk />
		</WooPaymentsProtectedRoute>
	),
} );

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-transactions',
	path: '/woopayments/transactions',
	order: 120,
	element: (
		<WooPaymentsProtectedRoute path="/woopayments/transactions">
			<WooPaymentsTransactionsChunk />
		</WooPaymentsProtectedRoute>
	),
} );

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-transaction-details',
	path: '/woopayments/transactions/details',
	order: 121,
	element: (
		<WooPaymentsProtectedRoute path="/woopayments/transactions/details">
			<WooPaymentsTransactionDetailsChunk />
		</WooPaymentsProtectedRoute>
	),
} );

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-reports',
	path: '/woopayments/reports',
	order: 122,
	element: (
		<>
			<WooPaymentsDocumentTitle path="/woopayments/reports" />
			<WooPaymentsReportsRoute />
		</>
	),
} );

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-disputes',
	path: '/woopayments/disputes',
	order: 123,
	element: (
		<WooPaymentsProtectedRoute path="/woopayments/disputes">
			<WooPaymentsDisputesChunk />
		</WooPaymentsProtectedRoute>
	),
} );

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-dispute-details',
	path: '/woopayments/disputes/details',
	order: 124,
	element: (
		<WooPaymentsProtectedRoute path="/woopayments/disputes/details">
			<WooPaymentsDisputeDetailsChunk />
		</WooPaymentsProtectedRoute>
	),
} );

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-dispute-challenge',
	path: '/woopayments/disputes/challenge',
	order: 125,
	element: (
		<WooPaymentsProtectedRoute path="/woopayments/disputes/challenge">
			<WooPaymentsDisputeChallengeChunk />
		</WooPaymentsProtectedRoute>
	),
} );

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-card-readers',
	path: '/woopayments/card-readers',
	order: 126,
	element: (
		<WooPaymentsProtectedRoute path="/woopayments/card-readers">
			<WooPaymentsCardReadersChunk />
		</WooPaymentsProtectedRoute>
	),
} );

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-capital',
	path: '/woopayments/loans',
	order: 127,
	element: (
		<WooPaymentsProtectedRoute path="/woopayments/loans">
			<WooPaymentsCapitalChunk />
		</WooPaymentsProtectedRoute>
	),
} );

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-documents',
	path: '/woopayments/documents',
	order: 128,
	element: (
		<WooPaymentsProtectedRoute path="/woopayments/documents">
			<WooPaymentsDocumentsChunk />
		</WooPaymentsProtectedRoute>
	),
} );
