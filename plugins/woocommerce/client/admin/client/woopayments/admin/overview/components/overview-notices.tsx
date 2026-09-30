/**
 * External dependencies
 */
import { Button, Dropdown, ExternalLink, Notice } from '@wordpress/components';
import { createInterpolateElement, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { help } from '@wordpress/icons';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { SetupLivePaymentsModal } from '~/woopayments/settings/account-mode-notice';
import BannerNotice from '~/settings-payments/onboarding/providers/woopayments/components/banner-notice';
import type { WooPaymentsOverviewAccount } from '../types';

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
	].filter( Boolean ) as Array< { className: string; message: string } >;

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

// Client 11.1.0 components/sandbox-mode-switch-to-live-notice/index.tsx, four account/dev-mode variants.
const SandboxModeNotice = ( {
	account,
	isDevMode,
	setupUrl,
}: {
	account: WooPaymentsOverviewAccount;
	isDevMode: boolean;
	setupUrl?: string;
} ) => {
	const [ isModalVisible, setModalVisible ] = useState( false );
	const accountType = account.test_drive ? 'test' : 'sandbox';
	const devHelp = [
		__( 'Learn more about development mode', 'woocommerce' ),
		__(
			'To begin accepting real payments, please go to the live store or change your <wpEnvLink>WordPress environment</wpEnvLink> to a production one. <learnMoreLink>Learn more</learnMoreLink>',
			'woocommerce'
		),
		`${ TEST_ACCOUNTS_URL }#developer-notes`,
	];
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
		testDev: [
			__(
				"<strong>You're using a test account.</strong> ⚠️ Development mode is enabled for the store! There can be no live onboarding process while using development, testing, or staging WordPress environments!",
				'woocommerce'
			),
			...devHelp,
		],
		sandboxDev: [
			__(
				'<strong>You are using a sandbox test account.</strong> ⚠️ Development mode is enabled for the store! There can be no live onboarding process while using development, testing, or staging WordPress environments!',
				'woocommerce'
			),
			...devHelp,
		],
	}[ isDevMode ? `${ accountType }Dev` : accountType ] as string[];
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
					<Dropdown
						renderToggle={ ( { isOpen, onToggle } ) => (
							<Button
								icon={ help }
								size="small"
								label={ helpLabel }
								aria-expanded={ isOpen }
								onClick={ onToggle }
							/>
						) }
						renderContent={ () =>
							createInterpolateElement( helpText, {
								wpEnvLink: link(
									'https://make.wordpress.org/core/2020/08/27/wordpress-environment-types/'
								),
								learnMoreLink: link( helpUrl, () =>
									recordEvent(
										'wcpay_overview_sandbox_mode_learn_more_clicked',
										{
											account_type: accountType,
											is_dev_mode: isDevMode,
										}
									)
								),
							} )
						}
					/>
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
	// Client Mode.php:114: dev mode implies test-mode onboarding.
	if ( account.test_mode_onboarding || account.dev_mode ) {
		return account.connected && ! account.live ? (
			<SandboxModeNotice
				account={ account }
				isDevMode={ account.dev_mode }
				setupUrl={ setupUrl }
			/>
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
