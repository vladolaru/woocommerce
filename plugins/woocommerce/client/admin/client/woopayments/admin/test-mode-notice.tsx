/**
 * External dependencies
 */
import { Notice } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getWooPaymentsSettingsBootstrap } from '../settings/bootstrap';
import { DevModeMessage } from './dev-mode-message';
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

// Client index.tsx:173-175 and 221-223: the page value is the resource, with deposits read as payouts.
const resourceLabels: Record< WooPaymentsTestModeNoticePage, string > = {
	documents: __( 'documents', 'woocommerce' ),
	deposits: __( 'payouts', 'woocommerce' ),
	disputes: __( 'disputes', 'woocommerce' ),
	loans: __( 'loans', 'woocommerce' ),
	payments: __( 'payments', 'woocommerce' ),
	transactions: __( 'transactions', 'woocommerce' ),
};

// Client 11.2.0 components/test-mode-notice/index.tsx:34-41.
const pluralNounToUse: Record< WooPaymentsTestModeNoticePage, string > = {
	...resourceLabels,
	payments: resourceLabels.transactions,
};

// Client 11.3.0 client/components/test-mode-notice/index.tsx:131-166.
const getNoticeMessage = (
	currentPage: WooPaymentsTestModeNoticePage,
	isDetailsView: boolean
) => {
	const resource = resourceLabels[ currentPage ];

	if ( isDetailsView ) {
		return sprintf(
			/* translators: 1: WooPayments, 2: plural record type, such as "transactions". */
			__(
				'%1$s is in test mode, so only test %2$s are shown. To view live %2$s, disable test mode in <settingsLink>%1$s settings</settingsLink>.',
				'woocommerce'
			),
			'WooPayments',
			pluralNounToUse[ currentPage ]
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
 * Shown only while WooPayments runs in test mode.
 */
export const WooPaymentsTestModeNotice = ( {
	currentPage,
	isDetailsView = false,
}: {
	currentPage: WooPaymentsTestModeNoticePage;
	isDetailsView?: boolean;
} ) => {
	// Preloaded by SharedSettings::preload_shared_settings() as `testMode` and
	// `devMode`: no request of its own.
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
				{ settings.devMode === true ? (
					<>
						{ sprintf(
							/* translators: %1$s: plural record type, such as "transactions". */
							__( 'Viewing test %1$s.', 'woocommerce' ),
							pluralNounToUse[ currentPage ]
						) }{ ' ' }
						<DevModeMessage />
					</>
				) : (
					createInterpolateElement(
						getNoticeMessage( currentPage, isDetailsView ),
						{
							settingsLink: (
								// eslint-disable-next-line jsx-a11y/anchor-has-content -- Content comes from the interpolated message.
								<a
									href={ getSettingsPaymentsProviderRouteUrl(
										'/woopayments/settings'
									) }
								/>
							),
						}
					)
				) }
			</span>
		</Notice>
	);
};
