/**
 * External dependencies
 */
import {
	Button,
	CheckboxControl,
	ExternalLink,
	Modal,
} from '@wordpress/components';
import { createInterpolateElement, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { SettingsSection } from '../settings-shell';
import { TextLink } from '../text-link';
import { WooPaymentsDisableConfirmationModal } from '../disable-woopayments-modal';
import { useWooPaymentsAffectedCheckoutMethods } from '../affected-payment-methods';
import {
	useDevMode,
	useIsWCPayEnabled,
	useTestMode,
	useTestModeOnboarding,
} from '../data/hooks';
import {
	PROVIDER_NAME,
	GeneralSectionDescription,
} from './section-descriptions';
import type { BooleanSetting } from './utils';
import { WORDPRESS_ENVIRONMENT_URL } from '../account-mode-notice';

const TESTING_DOC_URL =
	'https://woocommerce.com/document/woopayments/testing-and-troubleshooting/testing/';
const TEST_CARDS_DOC_URL =
	'https://woocommerce.com/document/woopayments/testing-and-troubleshooting/testing/#test-cards';

const TestModeConfirmationModal = ( {
	onClose,
	onConfirm,
}: {
	onClose: () => void;
	onConfirm: () => void;
} ) => (
	<Modal
		title={ __( 'Enable test mode', 'woocommerce' ) }
		onRequestClose={ onClose }
		shouldCloseOnClickOutside={ false }
	>
		<div className="woopayments-settings-modal">
			<h2>
				{ __(
					'Are you sure you want to enable test mode?',
					'woocommerce'
				) }
			</h2>
			<p>
				{ __(
					"Test mode lets you try out payments, refunds, disputes, and similar processes while you're working on your store without handling live payment information. All incoming orders will be simulated, and test mode must be disabled before you can accept real orders.",
					'woocommerce'
				) }
			</p>
			<ExternalLink href={ TESTING_DOC_URL }>
				{ __( 'Learn more about test mode', 'woocommerce' ) }
			</ExternalLink>
			<div className="woopayments-settings-modal__actions">
				<Button variant="secondary" onClick={ onClose }>
					{ __( 'Cancel', 'woocommerce' ) }
				</Button>
				<Button variant="primary" onClick={ onConfirm }>
					{ __( 'Enable', 'woocommerce' ) }
				</Button>
			</div>
		</div>
	</Modal>
);

const GeneralSettingsSection = () => {
	const [ isWCPayEnabled, setIsWCPayEnabled ] =
		useIsWCPayEnabled() as BooleanSetting;
	const [ isTestModeEnabled, setIsTestModeEnabled ] =
		useTestMode() as BooleanSetting;
	const isTestModeOnboarding = Boolean( useTestModeOnboarding() );
	const isDevModeEnabled = Boolean( useDevMode() );
	const [ isTestModeModalVisible, setTestModeModalVisible ] =
		useState( false );
	const [ isDisableConfirmationVisible, setDisableConfirmationVisible ] =
		useState( false );
	const affectedMethods = useWooPaymentsAffectedCheckoutMethods();

	const handleWooPaymentsEnabledChange = ( value: boolean ) => {
		if ( ! value ) {
			setDisableConfirmationVisible( true );
			return;
		}

		setIsWCPayEnabled( true );
		recordEvent( 'wcpay_gateway_toggle', {
			action: 'enable',
			context: 'wcpay-settings',
		} );
	};

	const handleConfirmDisableWooPayments = () => {
		setIsWCPayEnabled( false );
		recordEvent( 'wcpay_gateway_toggle', {
			action: 'disable',
			context: 'wcpay-settings',
		} );
		setDisableConfirmationVisible( false );
	};

	const handleCloseTestModeModal = () => {
		recordEvent( 'wcpay_test_mode_modal_exit', {
			source: 'wcadmin-settings-page',
		} );
		setTestModeModalVisible( false );
	};

	const handleConfirmTestMode = () => {
		recordEvent( 'wcpay_test_mode_enabled', {
			source: 'wcadmin-settings-page',
		} );
		setIsTestModeEnabled( true );
		setTestModeModalVisible( false );
	};

	return (
		<>
			<SettingsSection
				id="general"
				title={ __( 'General', 'woocommerce' ) }
				description={ <GeneralSectionDescription /> }
			>
				<CheckboxControl
					checked={ isWCPayEnabled }
					label={ sprintf(
						/* translators: %s: Payment provider name. */
						__( 'Enable %s', 'woocommerce' ),
						PROVIDER_NAME
					) }
					help={ sprintf(
						/* translators: %s: Payment provider name. */
						__(
							'When enabled, payment methods powered by %s will appear on checkout.',
							'woocommerce'
						),
						PROVIDER_NAME
					) }
					onChange={ ( value ) =>
						handleWooPaymentsEnabledChange( Boolean( value ) )
					}
					__nextHasNoMarginBottom
				/>
				{ ! isTestModeOnboarding && (
					<>
						<h4>{ __( 'Test mode', 'woocommerce' ) }</h4>
						<CheckboxControl
							checked={ isDevModeEnabled || isTestModeEnabled }
							disabled={ isDevModeEnabled }
							help={
								isDevModeEnabled
									? createInterpolateElement(
											__(
												'Test mode is active because your store is running in a development or staging environment. To disable it, switch to a production <wpEnvLink>WordPress environment</wpEnvLink> or remove the WCPAY_DEV_MODE constant. <learnMoreLink>Learn more</learnMoreLink>',
												'woocommerce'
											),
											{
												wpEnvLink: (
													<TextLink
														href={
															WORDPRESS_ENVIRONMENT_URL
														}
													/>
												),
												learnMoreLink: (
													<TextLink
														href={ TESTING_DOC_URL }
													/>
												),
											}
										)
									: createInterpolateElement(
											__(
												'Use <testCardHelpLink>test card numbers</testCardHelpLink> to simulate various transactions. <learnMoreLink>Learn more</learnMoreLink>',
												'woocommerce'
											),
											{
												testCardHelpLink: (
													<TextLink
														href={
															TEST_CARDS_DOC_URL
														}
													/>
												),
												learnMoreLink: (
													<TextLink
														href={ TESTING_DOC_URL }
													/>
												),
											}
										)
							}
							label={
								isDevModeEnabled
									? __(
											'Enable test mode (enabled by development mode)',
											'woocommerce'
										)
									: __( 'Enable test mode', 'woocommerce' )
							}
							onChange={ ( value ) => {
								if ( value ) {
									setTestModeModalVisible( true );
									return;
								}

								recordEvent( 'wcpay_test_mode_disabled', {
									source: 'wcadmin-settings-page',
								} );
								setIsTestModeEnabled( false );
							} }
							__nextHasNoMarginBottom
						/>
					</>
				) }
			</SettingsSection>
			{ isDisableConfirmationVisible && (
				<WooPaymentsDisableConfirmationModal
					affectedMethods={ affectedMethods }
					onClose={ () => setDisableConfirmationVisible( false ) }
					onConfirm={ handleConfirmDisableWooPayments }
				/>
			) }
			{ isTestModeModalVisible && (
				<TestModeConfirmationModal
					onClose={ handleCloseTestModeModal }
					onConfirm={ handleConfirmTestMode }
				/>
			) }
		</>
	);
};

export { GeneralSettingsSection };
