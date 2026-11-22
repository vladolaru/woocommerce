/**
 * External dependencies
 */
import { Button, ExternalLink, Notice } from '@wordpress/components';
import { createInterpolateElement, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { recordEvent } from '@woocommerce/tracks';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { SetupLivePaymentsModal } from '~/woopayments/settings/account-mode-notice';
import { getWooPaymentsSettingsBootstrap } from '~/woopayments/settings/bootstrap';
import BannerNotice from '~/settings-payments/onboarding/providers/woopayments/components/banner-notice';
import type { WooPaymentsOverviewAccount } from '../types';
import { DevModeMessage } from '../../dev-mode-message';
import { HelpPopover } from './help-popover';
import { getSettingsPaymentsProviderRouteUrl } from '../../utils';

const TEST_ACCOUNTS_URL =
	'https://woocommerce.com/document/woopayments/testing-and-troubleshooting/test-accounts/';
const link = ( href: string, onClick?: () => void ) => (
	<ExternalLink href={ href } onClick={ onClick }>
		<></>
	</ExternalLink>
);

const getQueryParam = ( key: string ) =>
	new URLSearchParams( window.location.search ).get( key ) === '1';

export const OverviewNotices = () => {
	const notices = [
		getQueryParam( 'wcpay-login-error' ) && {
			className: 'wcpay-login-error',
			message: __(
				'There was a problem redirecting you to the account dashboard. Please try again.',
				'woocommerce'
			),
		},
		// Client 11.1.0 components/jetpack-idc-notice/index.tsx, after the login error (overview/index.js:239-240).
		getWooPaymentsSettingsBootstrap().isJetpackIdcActive === true && {
			className: 'wcpay-jetpack-idc-notice',
			message: (
				<>
					{ __(
						'Your site is currently in Safe Mode.',
						'woocommerce'
					) }
					<span>&nbsp;</span>
					<a
						href={ getSettingsPaymentsProviderRouteUrl(
							'/woopayments/settings'
						) }
					>
						{ __( 'Please take action', 'woocommerce' ) }
					</a>
				</>
			),
		},
		getQueryParam( 'wcpay-loan-offer-error' ) && {
			className: 'wcpay-loan-offer-error',
			message: __(
				'There was a problem redirecting you to the loan offer. Please check that it is not expired and try again.',
				'woocommerce'
			),
		},
		getQueryParam( 'wcpay-server-link-error' ) && {
			className: 'wcpay-server-link-error',
			message: __(
				'There was a problem redirecting you to the requested link. Please check that it is valid and try again.',
				'woocommerce'
			),
		},
		getQueryParam( 'wcpay-reset-account-error' ) && {
			className: 'wcpay-reset-account-error',
			message: __(
				'There was a problem resetting your account. Please wait a few seconds and try again.',
				'woocommerce'
			),
		},
	].filter( Boolean ) as Array< { className: string; message: ReactNode } >;

	if ( notices.length === 0 ) {
		return null;
	}

	return (
		<div className="woocommerce-woopayments-overview-query-notices">
			{ notices.map( ( notice ) => (
				<Notice
					key={ notice.className }
					className={ notice.className }
					status="error"
					isDismissible={ false }
				>
					{ notice.message }
				</Notice>
			) ) }
		</div>
	);
};

// Client 11.3.0 client/components/sandbox-mode-switch-to-live-notice/index.tsx:68-76,232-245.
const SandboxModeNotice = ( {
	account,
	setupUrl,
}: {
	account: WooPaymentsOverviewAccount;
	setupUrl?: string;
} ) => {
	const [ isModalVisible, setModalVisible ] = useState( false );
	const accountType = account.test_drive ? 'test' : 'sandbox';
	if ( account.dev_mode ) {
		return (
			<BannerNotice
				className="woocommerce-woopayments-overview-mode-notice"
				status="warning"
				isDismissible={ false }
			>
				<div>
					<DevModeMessage
						onLearnMoreClick={ () =>
							recordEvent(
								'wcpay_overview_sandbox_mode_learn_more_clicked',
								{
									account_type: account.live
										? 'live'
										: accountType,
									is_dev_mode: true,
								}
							)
						}
					/>
				</div>
			</BannerNotice>
		);
	}
	const [ message, helpLabel, helpText, helpUrl ] = {
		test: [
			sprintf(
				/* translators: %1$s: WooPayments */
				__(
					"<strong>You're using a test account.</strong> To accept payments from shoppers, <switchToLiveLink>activate your %1$s account.</switchToLiveLink>",
					'woocommerce'
				),
				'WooPayments'
			),
			__( 'Learn more about test accounts', 'woocommerce' ),
			sprintf(
				/* translators: %1$s: WooPayments */
				__(
					'A test account gives you access to all %1$s features while checkout transactions are simulated. <learnMoreLink>Learn more</learnMoreLink>',
					'woocommerce'
				),
				'WooPayments'
			),
			TEST_ACCOUNTS_URL,
		],
		sandbox: [
			__(
				"<strong>You're using a sandbox test account.</strong> To accept real payments from shoppers, you will need to first <resetAccountLink>reset your account</resetAccountLink> and, then, provide additional details about your business.",
				'woocommerce'
			),
			__( 'Learn more about sandbox accounts', 'woocommerce' ),
			sprintf(
				/* translators: %1$s: WooPayments */
				__(
					'A sandbox account gives you access to all %1$s features while checkout transactions are simulated. <learnMoreLink>Learn more</learnMoreLink>',
					'woocommerce'
				),
				'WooPayments'
			),
			'https://woocommerce.com/document/woopayments/startup-guide/#signup-process',
		],
	}[ accountType ];
	return (
		<>
			{ /* Client 11.1.0 `components/sandbox-mode-switch-to-live-notice`: a banner notice with the help icon at its end. */ }
			<BannerNotice
				className="woocommerce-woopayments-overview-mode-notice"
				status="warning"
				isDismissible={ false }
			>
				{ /* The interpolated message in its own element: a bare interpolated fragment among siblings trips React's missing-key warning. */ }
				<>
					<span>
						{ createInterpolateElement( message, {
							strong: <strong />,
							switchToLiveLink: (
								<Button
									variant="link"
									onClick={ () => {
										recordEvent(
											'wcpay_setup_live_payments_modal_open',
											{
												from: 'WCPAY_OVERVIEW',
												source: 'wcpay-overview-page',
											}
										);
										setModalVisible( true );
									} }
								/>
							),
							resetAccountLink: link(
								'https://woocommerce.com/document/woopayments/startup-guide/#resetting'
							),
						} ) }
					</span>
					<HelpPopover label={ helpLabel }>
						{ createInterpolateElement( helpText, {
							learnMoreLink: link( helpUrl, () =>
								recordEvent(
									'wcpay_overview_sandbox_mode_learn_more_clicked',
									{
										account_type: accountType,
										is_dev_mode: false,
									}
								)
							),
						} ) }
					</HelpPopover>
				</>
			</BannerNotice>
			{ isModalVisible && (
				<SetupLivePaymentsModal
					from="WCPAY_OVERVIEW"
					source="wcpay-overview-page"
					setupUrl={ setupUrl }
					onClose={ () => setModalVisible( false ) }
				/>
			) }
		</>
	);
};

// Client 11.1.0 overview/index.js:265-276 and components/test-mode-notice/index.tsx:108-135, 239-260.
export const OverviewModeNotice = ( {
	account,
	setupUrl,
}: {
	account: WooPaymentsOverviewAccount;
	setupUrl?: string;
} ) => {
	// Client 11.3.0 client/components/sandbox-mode-switch-to-live-notice/index.tsx:54-65.
	if ( account.test_mode_onboarding || account.dev_mode ) {
		const showNotice = account.live
			? account.dev_mode && account.test_mode
			: account.test_drive || account.sandbox;
		return account.connected && showNotice ? (
			<SandboxModeNotice account={ account } setupUrl={ setupUrl } />
		) : null;
	}

	if ( ! account.test_mode ) {
		return null;
	}

	return (
		<BannerNotice status="warning" isDismissible={ false }>
			{ createInterpolateElement(
				sprintf(
					/* translators: %1$s: WooPayments */
					__(
						'<strong>%1$s is in test mode.</strong> All transactions will be simulated. <learnMoreLink>Learn more</learnMoreLink>',
						'woocommerce'
					),
					'WooPayments'
				),
				{
					strong: <strong />,
					learnMoreLink: link( TEST_ACCOUNTS_URL, () =>
						recordEvent(
							'wcpay_overview_test_mode_learn_more_clicked'
						)
					),
				}
			) }
		</BannerNotice>
	);
};
