/**
 * External dependencies
 */
import { ExternalLink, Notice } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getWooPaymentsSettingsBootstrap } from '../settings/bootstrap';
import { getSettingsPaymentsProviderRouteUrl } from './utils';

// Client 11.1.0 components/test-mode-notice/index.tsx:17-24. The client's `overview` value is
// served by OverviewModeNotice (overview/components/overview-notices.tsx).
export type WooPaymentsTestModeNoticePage =
	| 'documents'
	| 'deposits'
	| 'disputes'
	| 'loans'
	| 'payments'
	| 'transactions';

const TEST_ACCOUNTS_URL =
	'https://woocommerce.com/document/woopayments/testing-and-troubleshooting/test-accounts/';

// Client index.tsx:173-175 and 221-223: the page value is the resource, with deposits read as payouts.
const resourceLabels: Record< WooPaymentsTestModeNoticePage, string > = {
	documents: __( 'documents', 'woocommerce' ),
	deposits: __( 'payouts', 'woocommerce' ),
	disputes: __( 'disputes', 'woocommerce' ),
	loans: __( 'loans', 'woocommerce' ),
	payments: __( 'payments', 'woocommerce' ),
	transactions: __( 'transactions', 'woocommerce' ),
};

// Client index.tsx:33-48.
const nouns: Record< WooPaymentsTestModeNoticePage, string > = {
	documents: __( 'document', 'woocommerce' ),
	deposits: __( 'payout', 'woocommerce' ),
	disputes: __( 'dispute', 'woocommerce' ),
	loans: __( 'loan', 'woocommerce' ),
	payments: __( 'order', 'woocommerce' ),
	transactions: __( 'order', 'woocommerce' ),
};

const verbs: Record< WooPaymentsTestModeNoticePage, string > = {
	documents: __( 'created', 'woocommerce' ),
	deposits: __( 'created', 'woocommerce' ),
	disputes: __( 'created', 'woocommerce' ),
	loans: __( 'created', 'woocommerce' ),
	payments: __( 'placed', 'woocommerce' ),
	transactions: __( 'placed', 'woocommerce' ),
};

// Client index.tsx:163-236: dev mode first, then the details view, then the list sentence.
const getNoticeMessage = (
	currentPage: WooPaymentsTestModeNoticePage,
	isDetailsView: boolean,
	isDevMode: boolean
) => {
	const resource = resourceLabels[ currentPage ];

	if ( isDevMode ) {
		return sprintf(
			/* translators: %1$s: WooPayments admin resource, such as "transactions". */
			__(
				'Viewing test %1$s. Test mode is active because your store is in a development or staging environment. <learnMoreLink>Learn more</learnMoreLink>',
				'woocommerce'
			),
			resource
		);
	}

	if ( isDetailsView ) {
		return sprintf(
			/* translators: 1: WooPayments, 2: resource noun, such as "order", 3: verb, such as "placed". */
			_n(
				'%1$s was in test mode when this %2$s was %3$s. To view live %2$ss, disable test mode in <settingsLink>%1$s settings</settingsLink>.',
				'%1$s was in test mode when these %2$ss were %3$s. To view live %2$ss, disable test mode in <settingsLink>%1$s settings</settingsLink>.',
				currentPage === 'deposits' ? 2 : 1,
				'woocommerce'
			),
			'WooPayments',
			nouns[ currentPage ],
			verbs[ currentPage ]
		);
	}

	return sprintf(
		/* translators: 1: WooPayments admin resource, such as "loans", 2: WooPayments. */
		__(
			'Viewing test %1$s. To view live %1$s, disable test mode in <settingsLink>%2$s settings</settingsLink>.',
			'woocommerce'
		),
		resource,
		'WooPayments'
	);
};

/**
 * Warn that a WooPayments admin page shows test data (client 11.1.0 components/test-mode-notice).
 *
 * Shown only while WooPayments runs in test mode, the client's `isInTestMode()`.
 */
export const WooPaymentsTestModeNotice = ( {
	currentPage,
	isDetailsView = false,
}: {
	currentPage: WooPaymentsTestModeNoticePage;
	isDetailsView?: boolean;
} ) => {
	// Preloaded by WooPaymentsAdminNavigationController::preload_shared_settings(), as the client reads
	// `wcpaySettings.testMode` / `devMode`: no request of its own.
	const settings = getWooPaymentsSettingsBootstrap();

	if ( settings.testMode !== true ) {
		return null;
	}

	return (
		<Notice
			className="woocommerce-woopayments-test-mode-notice"
			status="warning"
			isDismissible={ false }
		>
			<span>
				{ createInterpolateElement(
					getNoticeMessage(
						currentPage,
						isDetailsView,
						settings.devMode === true
					),
					{
						settingsLink: (
							// eslint-disable-next-line jsx-a11y/anchor-has-content -- Content comes from the interpolated message.
							<a
								href={ getSettingsPaymentsProviderRouteUrl(
									'/woopayments/settings'
								) }
							/>
						),
						learnMoreLink: (
							<ExternalLink href={ TEST_ACCOUNTS_URL }>
								<></>
							</ExternalLink>
						),
					}
				) }
			</span>
		</Notice>
	);
};
