/**
 * External dependencies
 */
import {
	BaseControl,
	Button,
	CheckboxControl,
	ExternalLink,
	Modal,
	Notice,
	TextControl,
} from '@wordpress/components';
import {
	Children,
	createInterpolateElement,
	useEffect,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { PhoneNumberInput, validatePhoneNumber } from '@woocommerce/components';

/**
 * Internal dependencies
 */
import {
	ACCOUNT_STATEMENT_INPUT_ID,
	ACCOUNT_STATEMENT_KANA_INPUT_ID,
	ACCOUNT_STATEMENT_KANJI_INPUT_ID,
	SUPPORT_EMAIL_INPUT_ID,
	SUPPORT_PHONE_INPUT_ID,
} from '../save-settings-section';
import { SettingsSection, type FieldValidationError } from '../settings-shell';
import { TextLink } from '../text-link';
import {
	useAccountBusinessSupportEmail,
	useAccountBusinessSupportPhone,
	useAccountStatementDescriptor,
	useAccountStatementDescriptorKana,
	useAccountStatementDescriptorKanji,
	useCardPresentEligible,
	useGetSavingError,
	useGetSettings,
	useManualCapture,
	useSavedCards,
	useStripeBilling,
	useTestModeOnboarding,
} from '../data/hooks';
import { asSettingsRecord, asString } from '../settings-values';
import { FieldGroup } from './field-group';
import { TransactionsSectionDescription } from './section-descriptions';
import {
	type ValidationSetter,
	type BooleanSetting,
	type StringSetting,
	getSavingErrorDetailMessage,
	isValidEmailAddress,
} from './utils';

const ACCOUNT_STATEMENT_MAX_LENGTH = 22;
const ACCOUNT_STATEMENT_MAX_LENGTH_KANJI = 17;
const ACCOUNT_STATEMENT_MAX_LENGTH_KANA = 22;

const SUPPORT_EMAIL_ERROR_ID = 'woopayments-support-email-error';
const SUPPORT_PHONE_ERROR_ID = 'woopayments-support-phone-error';
const MANUAL_CAPTURE_DOC_URL =
	'https://woocommerce.com/document/woopayments/settings-guide/authorize-and-capture/';

const IN_PERSON_PAYMENTS_DOC_URL =
	'https://woocommerce.com/in-person-payments/';

const ManualCaptureConfirmationModal = ( {
	onClose,
	onConfirm,
}: {
	onClose: () => void;
	onConfirm: () => void;
} ) => (
	<Modal
		title={ __( 'Enable manual capture', 'woocommerce' ) }
		onRequestClose={ onClose }
	>
		<div className="woopayments-settings-modal">
			<p>
				<strong>
					{ __(
						'Payments must be captured on the order details screen within 7 days of authorization',
						'woocommerce'
					) }
				</strong>
				{ __(
					', otherwise the authorization and order will be canceled.',
					'woocommerce'
				) }
				<br />
				{ createInterpolateElement(
					__(
						'<learnMoreLink>Learn more about manual capture</learnMoreLink>.',
						'woocommerce'
					),
					{
						learnMoreLink: (
							<TextLink href={ MANUAL_CAPTURE_DOC_URL } />
						),
					}
				) }
			</p>
			<Notice status="info" isDismissible={ false }>
				{ __(
					"Manual capture is available for card payments only. Payment methods that don't support it will be disabled.",
					'woocommerce'
				) }
			</Notice>
			<div className="woopayments-settings-modal__actions">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'woocommerce' ) }
				</Button>
				<Button variant="primary" onClick={ onConfirm }>
					{ __( 'Enable manual capture', 'woocommerce' ) }
				</Button>
			</div>
		</div>
	</Modal>
);

const TransactionsSettingsSection = ( {
	onValidationChange,
}: {
	onValidationChange?: ValidationSetter;
} ) => {
	const settings = asSettingsRecord( useGetSettings() );
	const [ isSavedCardsEnabled, setIsSavedCardsEnabled ] =
		useSavedCards() as BooleanSetting;
	const [ isManualCaptureEnabled, setIsManualCaptureEnabled ] =
		useManualCapture() as BooleanSetting;
	const [ isCardPresentEligible ] =
		useCardPresentEligible() as BooleanSetting;
	const [ isStripeBillingEnabled ] = useStripeBilling() as BooleanSetting;
	const [ isManualCaptureModalVisible, setManualCaptureModalVisible ] =
		useState( false );
	const [ accountStatementDescriptor, setAccountStatementDescriptor ] =
		useAccountStatementDescriptor() as StringSetting;
	const [
		accountStatementDescriptorKanji,
		setAccountStatementDescriptorKanji,
	] = useAccountStatementDescriptorKanji() as StringSetting;
	const [
		accountStatementDescriptorKana,
		setAccountStatementDescriptorKana,
	] = useAccountStatementDescriptorKana() as StringSetting;
	const [ supportEmail, setSupportEmail ] =
		useAccountBusinessSupportEmail() as StringSetting;
	const [ supportPhone, setSupportPhone ] =
		useAccountBusinessSupportPhone() as StringSetting;
	const [ initialSupportEmail ] = useState( supportEmail );
	const [ hasSupportEmailBlurred, setHasSupportEmailBlurred ] =
		useState( false );
	const [ hasSupportPhoneChanged, setHasSupportPhoneChanged ] =
		useState( false );
	const [ supportPhoneCountry, setSupportPhoneCountry ] = useState<
		string | undefined
	>();
	const savingError = useGetSavingError();
	// The descriptor, support email and support phone errors show at their own fields; any other save error is the page
	// notice (client 11.1.0 client/settings/transactions/index.js:47-49).
	const serverErrorMessage = getSavingErrorDetailMessage(
		savingError,
		'account_statement_descriptor'
	);
	const accountCountry = asString( settings.account_country );
	const isTestModeOnboarding = Boolean( useTestModeOnboarding() );
	const supportEmailServerError = getSavingErrorDetailMessage(
		savingError,
		'account_business_support_email'
	);
	const supportPhoneServerError = getSavingErrorDetailMessage(
		savingError,
		'account_business_support_phone'
	);
	const isTestSupportPhoneValid =
		isTestModeOnboarding && supportPhone === '+10000000000';
	const isSupportPhoneEmpty = supportPhone === '';
	const isSupportPhoneFormatValid =
		isSupportPhoneEmpty ||
		isTestSupportPhoneValid ||
		validatePhoneNumber( supportPhone, supportPhoneCountry );
	const supportEmailInvalidFormat =
		supportEmail !== '' && ! isValidEmailAddress( supportEmail );
	const isSupportEmailValid =
		! supportEmailServerError &&
		! ( supportEmail === '' && initialSupportEmail !== '' ) &&
		! supportEmailInvalidFormat;
	const isSupportPhoneValid =
		! supportPhoneServerError &&
		! isSupportPhoneEmpty &&
		isSupportPhoneFormatValid;
	const hasSupportPhoneLocalError =
		isSupportPhoneEmpty ||
		( hasSupportPhoneChanged && ! isSupportPhoneFormatValid );
	const supportEmailError =
		supportEmailServerError ||
		( supportEmail === '' && initialSupportEmail !== ''
			? __(
					'Support email cannot be empty once it has been set before, please specify.',
					'woocommerce'
				)
			: '' ) ||
		( hasSupportEmailBlurred && supportEmailInvalidFormat
			? __( 'Please enter a valid email address.', 'woocommerce' )
			: '' );
	const supportPhoneError =
		supportPhoneServerError ||
		( hasSupportPhoneLocalError
			? __(
					'A support phone number is required. Please enter a valid phone number.',
					'woocommerce'
				)
			: '' );

	const supportEmailValidationMessage =
		supportEmailError ||
		( supportEmailInvalidFormat
			? __( 'Please enter a valid email address.', 'woocommerce' )
			: '' );
	const supportPhoneValidationMessage =
		supportPhoneError ||
		__(
			'A support phone number is required. Please enter a valid phone number.',
			'woocommerce'
		);
	let validationError: FieldValidationError | null = null;
	if ( ! isSupportEmailValid ) {
		validationError = {
			inputId: SUPPORT_EMAIL_INPUT_ID,
			message: supportEmailValidationMessage,
		};
	} else if ( ! isSupportPhoneValid ) {
		validationError = {
			inputId: SUPPORT_PHONE_INPUT_ID,
			message: supportPhoneValidationMessage,
		};
	}
	const validationInputId = validationError?.inputId;
	const validationMessage = validationError?.message;

	useEffect( () => {
		onValidationChange?.(
			validationInputId && validationMessage
				? { inputId: validationInputId, message: validationMessage }
				: null
		);
	}, [ validationInputId, validationMessage, onValidationChange ] );

	return (
		<SettingsSection
			id="transactions"
			title={ __( 'Transactions', 'woocommerce' ) }
			description={ <TransactionsSectionDescription /> }
		>
			<FieldGroup
				title={ __( 'Transaction preferences', 'woocommerce' ) }
			>
				<CheckboxControl
					checked={ isSavedCardsEnabled }
					help={ __(
						'When enabled, users will be able to pay with a saved card during checkout. Card details are stored in our platform, not on your store.',
						'woocommerce'
					) }
					label={ __(
						'Enable payments via saved cards',
						'woocommerce'
					) }
					onChange={ ( value ) =>
						setIsSavedCardsEnabled( Boolean( value ) )
					}
					__nextHasNoMarginBottom
				/>
				<CheckboxControl
					checked={ isManualCaptureEnabled }
					disabled={ isStripeBillingEnabled }
					help={
						<>
							{ Children.toArray(
								createInterpolateElement(
									__(
										'Issue an authorization on checkout and capture later. <learnMoreLink>Learn more</learnMoreLink>.',
										'woocommerce'
									),
									{
										learnMoreLink: (
											<TextLink
												href={ MANUAL_CAPTURE_DOC_URL }
											/>
										),
									}
								)
							) }
							{ isCardPresentEligible && (
								<>
									{ ' ' }
									{ Children.toArray(
										createInterpolateElement(
											__(
												'The setting is not applied to <inPersonPaymentsLink>In-Person Payments</inPersonPaymentsLink> (please note that In-Person Payments should be captured within 2 days of authorization).',
												'woocommerce'
											),
											{
												inPersonPaymentsLink: (
													<ExternalLink
														href={
															IN_PERSON_PAYMENTS_DOC_URL
														}
													>
														<></>
													</ExternalLink>
												),
											}
										)
									) }
								</>
							) }
						</>
					}
					label={ __( 'Enable manual capture', 'woocommerce' ) }
					onChange={ ( value ) => {
						if ( ! value ) {
							setIsManualCaptureEnabled( false );
							return;
						}

						setManualCaptureModalVisible( true );
					} }
					__nextHasNoMarginBottom
				/>
				{ isStripeBillingEnabled && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Manual capture is not available when Stripe Billing is active.',
							'woocommerce'
						) }
					</Notice>
				) }
				{ isManualCaptureModalVisible && (
					<ManualCaptureConfirmationModal
						onClose={ () => setManualCaptureModalVisible( false ) }
						onConfirm={ () => {
							setIsManualCaptureEnabled( true );
							setManualCaptureModalVisible( false );
						} }
					/>
				) }
			</FieldGroup>
			<FieldGroup title={ __( 'Customer statements', 'woocommerce' ) }>
				<p className="woopayments-settings-muted">
					{ __(
						"Edit the way your store name appears on your customers' bank statements.",
						'woocommerce'
					) }
				</p>
				{ serverErrorMessage && (
					<Notice status="error" isDismissible={ false }>
						{ serverErrorMessage }
					</Notice>
				) }
				<TextControl
					id={ ACCOUNT_STATEMENT_INPUT_ID }
					className="woopayments-settings-field-narrow"
					label={ __( 'Customer bank statement', 'woocommerce' ) }
					value={ accountStatementDescriptor }
					maxLength={ ACCOUNT_STATEMENT_MAX_LENGTH }
					onChange={ setAccountStatementDescriptor }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<p className="woopayments-settings-character-count">
					{ sprintf(
						/* translators: 1: Current character count, 2: Maximum character count. */
						__( '%1$d of %2$d characters', 'woocommerce' ),
						accountStatementDescriptor.length,
						ACCOUNT_STATEMENT_MAX_LENGTH
					) }
				</p>
				{ accountCountry === 'JP' && (
					<div className="woopayments-settings-control-grid">
						<TextControl
							id={ ACCOUNT_STATEMENT_KANJI_INPUT_ID }
							label={ __(
								'Customer bank statement (kanji)',
								'woocommerce'
							) }
							value={ accountStatementDescriptorKanji }
							maxLength={ ACCOUNT_STATEMENT_MAX_LENGTH_KANJI }
							onChange={ setAccountStatementDescriptorKanji }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<TextControl
							id={ ACCOUNT_STATEMENT_KANA_INPUT_ID }
							label={ __(
								'Customer bank statement (kana)',
								'woocommerce'
							) }
							value={ accountStatementDescriptorKana }
							maxLength={ ACCOUNT_STATEMENT_MAX_LENGTH_KANA }
							onChange={ setAccountStatementDescriptorKana }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</div>
				) }
			</FieldGroup>
			<FieldGroup title={ __( 'Customer support', 'woocommerce' ) }>
				<p className="woopayments-settings-muted">
					{ __(
						'Provide contact information where customers can reach you for support.',
						'woocommerce'
					) }
				</p>
				<div className="woopayments-settings-control-grid woopayments-settings-control-grid--stacked">
					<div>
						<div id={ SUPPORT_EMAIL_ERROR_ID }>
							{ supportEmailError && (
								<Notice status="error" isDismissible={ false }>
									{ supportEmailError }
								</Notice>
							) }
						</div>
						<TextControl
							id={ SUPPORT_EMAIL_INPUT_ID }
							label={ __( 'Support email', 'woocommerce' ) }
							help={ __(
								'This may be visible on receipts, invoices, and automated emails from your store.',
								'woocommerce'
							) }
							type="email"
							value={ supportEmail }
							onChange={ setSupportEmail }
							onBlur={ () => setHasSupportEmailBlurred( true ) }
							aria-invalid={
								supportEmailError ? true : undefined
							}
							aria-describedby={
								supportEmailError
									? SUPPORT_EMAIL_ERROR_ID
									: undefined
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</div>
					<div>
						<div id={ SUPPORT_PHONE_ERROR_ID }>
							{ supportPhoneError && (
								<Notice status="error" isDismissible={ false }>
									{ supportPhoneError }
								</Notice>
							) }
						</div>
						<BaseControl
							label={ __(
								'Support phone number (required)',
								'woocommerce'
							) }
							help={
								<>
									{ __(
										"This number may appear on customer bank statements and in-person purchase receipts, but not in order emails. Use a number you're comfortable sharing publicly.",
										'woocommerce'
									) }
									{ isTestModeOnboarding && (
										<>
											<br />
											{ __(
												'(+1 0000000000 can be used for test accounts)',
												'woocommerce'
											) }
										</>
									) }
								</>
							}
							id={ SUPPORT_PHONE_INPUT_ID }
							__nextHasNoMarginBottom
						>
							<PhoneNumberInput
								id={ SUPPORT_PHONE_INPUT_ID }
								value={ supportPhone }
								onChange={ ( value, e164, country ) => {
									const localDigits = value
										.replace( /^\+\d+\s*/, '' )
										.replace( /\D/g, '' );

									setSupportPhone(
										localDigits === '' ? '' : e164
									);
									setSupportPhoneCountry( country );
									setHasSupportPhoneChanged( true );
								} }
								inputProps={ {
									'aria-invalid': supportPhoneError
										? true
										: undefined,
									'aria-describedby': supportPhoneError
										? SUPPORT_PHONE_ERROR_ID
										: undefined,
									onBlur: () =>
										setHasSupportPhoneChanged( true ),
								} }
							/>
						</BaseControl>
					</div>
				</div>
			</FieldGroup>
		</SettingsSection>
	);
};

export { TransactionsSettingsSection };
