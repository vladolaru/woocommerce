/**
 * External dependencies
 */
import { Button, Modal, Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { DevModeMessage } from '../admin/dev-mode-message';
import { getWooPaymentsAccountSettings } from './api';
import { getWooPaymentsSettingsBootstrap } from './bootstrap';
import { TextLink } from './text-link';
import './setup-live-payments-modal.scss';

type AccountModeNoticeState = {
	kind: 'live' | 'test' | 'sandbox';
	setupUrl?: string;
};

type AccountModeFields = {
	connected?: boolean;
	live?: boolean;
	testDrive?: boolean;
	sandbox?: boolean;
	setupUrl?: string;
};

const getNoticeState = (
	account: AccountModeFields
): AccountModeNoticeState | null => {
	if ( ! account.connected ) {
		return null;
	}

	if ( account.live ) {
		return { kind: 'live' };
	}

	if ( account.testDrive ) {
		return { kind: 'test', setupUrl: account.setupUrl };
	}

	return account.sandbox
		? { kind: 'sandbox', setupUrl: account.setupUrl }
		: null;
};

// Client 11.1.0 renders this notice on the server (`class-wc-payments-admin-settings.php:126-252`); the server preloads the account mode the same way.
const getPreloadedAccountMode = (): AccountModeFields | null => {
	const accountMode = getWooPaymentsSettingsBootstrap().accountMode;

	return accountMode && typeof accountMode === 'object'
		? ( accountMode as AccountModeFields )
		: null;
};

const LEARN_MORE_URL =
	'https://woocommerce.com/document/woopayments/startup-guide/#signup-process';
const RESET_ACCOUNT_URL =
	'https://woocommerce.com/document/woopayments/startup-guide/#resetting';
export const WORDPRESS_ENVIRONMENT_URL =
	'https://make.wordpress.org/core/2020/08/27/wordpress-environment-types/';
const SETUP_LIVE_FROM = 'WCPAY_SETTINGS';
const SETUP_LIVE_SOURCE = 'wcadmin-settings-page';

const getSetupLiveUrl = ( setupUrl?: string, source = SETUP_LIVE_SOURCE ) =>
	setupUrl
		? addQueryArgs( setupUrl, {
				source,
				from: 'wcpay-setup-live-payments',
			} )
		: undefined;

export const SetupLivePaymentsModal = ( {
	onClose,
	setupUrl,
	from = SETUP_LIVE_FROM,
	source = SETUP_LIVE_SOURCE,
}: {
	onClose: () => void;
	setupUrl?: string;
	from?: string;
	source?: string;
} ) => {
	const [ isSubmitted, setSubmitted ] = useState( false );
	const setupLiveUrl = getSetupLiveUrl( setupUrl, source );
	const handleSetup = (
		event: React.MouseEvent< HTMLAnchorElement | HTMLButtonElement >
	) => {
		if ( ! setupLiveUrl || isSubmitted ) {
			event.preventDefault();
			return;
		}

		setSubmitted( true );
		recordEvent( 'wcpay_onboarding_flow_setup_live_payments', {
			from,
			source,
		} );
		window.location.href = setupLiveUrl;
	};
	const handleClose = () => {
		setSubmitted( false );
		recordEvent( 'wcpay_setup_live_payments_modal_exit', {
			from,
			source,
		} );
		onClose();
	};

	return (
		<Modal
			title={ __( 'Activate payments on your store', 'woocommerce' ) }
			className="woopayments-settings-setup-live-modal"
			onRequestClose={ handleClose }
		>
			<div className="woopayments-settings-setup-live-modal__content">
				<p>
					{ __(
						"Before continuing, please make sure that you're aware of the following:",
						'woocommerce'
					) }
				</p>
				<ul>
					<li>
						{ __(
							'Your test account will be deactivated, but your transactions can be found in your order history.',
							'woocommerce'
						) }
					</li>
					<li>
						{ __(
							'To use WooPayments, you will need to verify your business details.',
							'woocommerce'
						) }
					</li>
					<li>
						{ __(
							'In order to receive payouts, you will need to provide your bank details.',
							'woocommerce'
						) }
					</li>
				</ul>
			</div>
			{ setupLiveUrl && (
				<div className="woopayments-settings-setup-live-modal__footer">
					<Button
						variant="primary"
						href={ setupLiveUrl }
						isBusy={ isSubmitted }
						aria-disabled={ isSubmitted }
						onClick={ handleSetup }
					>
						{ __( 'Activate payments', 'woocommerce' ) }
					</Button>
				</div>
			) }
		</Modal>
	);
};

export const AccountModeNotice = ( {
	isDevModeEnabled,
}: {
	isDevModeEnabled: boolean;
} ) => {
	const [ preloadedAccountMode ] = useState( getPreloadedAccountMode );
	const [ noticeState, setNoticeState ] =
		useState< AccountModeNoticeState | null >( () =>
			preloadedAccountMode ? getNoticeState( preloadedAccountMode ) : null
		);
	const [ isModalVisible, setModalVisible ] = useState( false );
	// The settings read carries dev mode; when it fails, the page's own flag still picks the development copy.
	const bootstrap = getWooPaymentsSettingsBootstrap();
	const isDevMode = isDevModeEnabled || bootstrap.devMode === true;

	useEffect( () => {
		if ( preloadedAccountMode ) {
			return;
		}

		let isMounted = true;

		getWooPaymentsAccountSettings()
			.then( ( response ) => {
				if ( ! isMounted || ! response.account ) {
					return;
				}

				setNoticeState(
					getNoticeState( {
						connected: response.account.connected,
						live: response.account.live,
						testDrive: response.account.test_drive,
						sandbox: response.account.sandbox,
						setupUrl: response.urls.setup,
					} )
				);
			} )
			.catch( () => {
				if ( isMounted ) {
					setNoticeState( null );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [ preloadedAccountMode ] );

	useEffect( () => {
		const handleActivatePayments = () => {
			if (
				noticeState?.kind === 'test' &&
				! isDevMode &&
				noticeState.setupUrl
			) {
				recordEvent( 'wcpay_settings_setup_live_payments_click', {
					source: SETUP_LIVE_SOURCE,
				} );
				setModalVisible( true );
			}
		};

		document.addEventListener(
			'wcpay:activate_payments',
			handleActivatePayments
		);

		return () => {
			document.removeEventListener(
				'wcpay:activate_payments',
				handleActivatePayments
			);
		};
	}, [ isDevMode, noticeState ] );

	// Client 11.3.0 includes/admin/class-wc-payments-admin-settings.php:77-92.
	if (
		! noticeState ||
		( noticeState.kind === 'live' &&
			( ! isDevMode || bootstrap.testMode !== true ) )
	) {
		return null;
	}

	const isTestAccount = noticeState.kind === 'test';
	const noticeHeading = isTestAccount
		? __( 'You are using a test account.', 'woocommerce' )
		: __( 'You are using a sandbox test account.', 'woocommerce' );

	const renderNoticeCopy = () => {
		if ( isTestAccount ) {
			return (
				<>
					<span>
						{ __(
							'Provide additional details about your business so you can begin accepting real payments.',
							'woocommerce'
						) }
					</span>{ ' ' }
					<TextLink href={ LEARN_MORE_URL }>
						{ __( 'Learn more', 'woocommerce' ) }
					</TextLink>
				</>
			);
		}

		return (
			<>
				{ __(
					'To begin accepting real payments you will need to first',
					'woocommerce'
				) }{ ' ' }
				<TextLink href={ RESET_ACCOUNT_URL }>
					{ __( 'reset your account', 'woocommerce' ) }
				</TextLink>{ ' ' }
				{ __(
					'and, then, provide additional details about your business.',
					'woocommerce'
				) }{ ' ' }
				<TextLink href={ LEARN_MORE_URL }>
					{ __( 'Learn more', 'woocommerce' ) }
				</TextLink>
			</>
		);
	};

	return (
		<>
			<Notice
				className="woopayments-settings-account-mode-notice"
				status="warning"
				isDismissible={ false }
			>
				<p>
					{ isDevMode ? (
						<DevModeMessage plainLink />
					) : (
						<>
							<strong>{ noticeHeading }</strong>{ ' ' }
							{ renderNoticeCopy() }
						</>
					) }
				</p>
				{ isTestAccount && ! isDevMode && (
					<Button
						variant="secondary"
						onClick={ () => {
							recordEvent(
								'wcpay_setup_live_payments_modal_open',
								{
									from: SETUP_LIVE_FROM,
									source: SETUP_LIVE_SOURCE,
								}
							);
							document.dispatchEvent(
								new CustomEvent( 'wcpay:activate_payments' )
							);
						} }
					>
						{ __( 'Activate payments', 'woocommerce' ) }
					</Button>
				) }
			</Notice>
			{ isModalVisible && (
				<SetupLivePaymentsModal
					onClose={ () => setModalVisible( false ) }
					setupUrl={ noticeState.setupUrl }
				/>
			) }
		</>
	);
};
