/**
 * External dependencies
 */
import { Card, CardBody } from '@wordpress/components';
import { lazy, Suspense } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

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

const WooPaymentsProtectedRoute = ( {
	children,
	path: routePath,
}: {
	children: JSX.Element;
	path: string;
} ) =>
	isRouteAvailable( routePath ) ? (
		<Suspense fallback={ <LoadingFallback /> }>{ children }</Suspense>
	) : (
		<WooPaymentsAdminAreaUnavailable />
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
		<Suspense fallback={ <LoadingFallback /> }>
			<WooPaymentsSettingsChunk />
		</Suspense>
	),
} );

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-express-checkout-settings',
	path: '/woopayments/settings/express-checkout/:methodId',
	order: 91,
	element: (
		<Suspense fallback={ <LoadingFallback /> }>
			<WooPaymentsExpressCheckoutSettingsChunk />
		</Suspense>
	),
} );

registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-fraud-protection-settings',
	path: '/woopayments/settings/fraud-protection',
	order: 92,
	element: (
		<Suspense fallback={ <LoadingFallback /> }>
			<WooPaymentsFraudProtectionSettingsChunk />
		</Suspense>
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
	element: <WooPaymentsReportsRoute />,
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
