/**
 * External dependencies
 */
import { Notice, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { NOTIFICATIONS_EMAIL_INPUT_ID } from '../save-settings-section';
import { SettingsSection, type FieldValidationError } from '../settings-shell';
import {
	useAccountCommunicationsEmail,
	useGetSavingError,
} from '../data/hooks';
import { FieldGroup } from './field-group';
import { NotificationsSectionDescription } from './section-descriptions';
import {
	type ValidationSetter,
	type StringSetting,
	getSavingErrorDetailMessage,
	isValidEmailAddress,
} from './utils';

const NOTIFICATIONS_EMAIL_ERROR_ID = 'woopayments-notifications-email-error';
const NOTIFICATIONS_EMAIL_CONFIRM_ERROR_ID =
	'woopayments-notifications-email-confirm-error';
const NOTIFICATIONS_EMAIL_CONFIRM_INPUT_ID =
	'woopayments-notifications-email-confirm-input';

const NotificationsSettingsSection = ( {
	onValidationChange,
}: {
	onValidationChange?: ValidationSetter;
} ) => {
	const [ email, setEmail ] =
		useAccountCommunicationsEmail() as StringSetting;
	const [ initialEmail ] = useState( email );
	const [ hasBlurred, setHasBlurred ] = useState( false );
	const [ confirmEmail, setConfirmEmail ] = useState( '' );
	const [ hasConfirmBlurred, setHasConfirmBlurred ] = useState( false );
	const savingError = useGetSavingError();
	const serverError = getSavingErrorDetailMessage(
		savingError,
		'account_communications_email'
	);
	const emailHasChanged = email !== initialEmail;
	const emailsMatch = ! emailHasChanged || email === confirmEmail;
	const isNotificationEmailValid =
		emailsMatch && ( email === '' || isValidEmailAddress( email ) );
	const emailError =
		serverError ||
		( hasBlurred && ! isValidEmailAddress( email )
			? __( 'Please enter a valid email address.', 'woocommerce' )
			: '' );
	const confirmEmailError =
		emailHasChanged && hasConfirmBlurred && ! emailsMatch
			? __(
					'Email addresses do not match. Please re-enter your email address.',
					'woocommerce'
				)
			: '';

	let validationError: FieldValidationError | null = null;
	if ( ! isValidEmailAddress( email ) ) {
		validationError = {
			inputId: NOTIFICATIONS_EMAIL_INPUT_ID,
			message: __( 'Please enter a valid email address.', 'woocommerce' ),
		};
	} else if ( ! isNotificationEmailValid ) {
		validationError = {
			inputId: NOTIFICATIONS_EMAIL_CONFIRM_INPUT_ID,
			message: __(
				'Email addresses do not match. Please re-enter your email address.',
				'woocommerce'
			),
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
			id="notifications"
			title={ __( 'Account notifications', 'woocommerce' ) }
			description={ <NotificationsSectionDescription /> }
		>
			<FieldGroup title={ __( 'Notifications email', 'woocommerce' ) }>
				<p className="woopayments-settings-muted">
					{ __(
						'Provide an email address where you would like to receive communications about your WooPayments account.',
						'woocommerce'
					) }
				</p>
				<Notice
					status="warning"
					isDismissible={ false }
					className="woopayments-settings-notifications-email-warning"
				>
					{ __(
						'Anyone with access to this email address will be treated as the account owner. Please verify the address carefully.',
						'woocommerce'
					) }
				</Notice>
				<div id={ NOTIFICATIONS_EMAIL_ERROR_ID }>
					{ emailError && (
						<Notice status="error" isDismissible={ false }>
							{ emailError }
						</Notice>
					) }
				</div>
				<TextControl
					id={ NOTIFICATIONS_EMAIL_INPUT_ID }
					className="woopayments-settings-field-narrow"
					label={ __( 'Email address', 'woocommerce' ) }
					type="email"
					value={ email }
					onChange={ setEmail }
					onBlur={ () => setHasBlurred( true ) }
					aria-invalid={ emailError ? true : undefined }
					aria-describedby={
						emailError ? NOTIFICATIONS_EMAIL_ERROR_ID : undefined
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				{ emailHasChanged && (
					<>
						<div id={ NOTIFICATIONS_EMAIL_CONFIRM_ERROR_ID }>
							{ confirmEmailError && (
								<Notice status="error" isDismissible={ false }>
									{ confirmEmailError }
								</Notice>
							) }
						</div>
						<TextControl
							id={ NOTIFICATIONS_EMAIL_CONFIRM_INPUT_ID }
							className="woopayments-settings-field-narrow"
							label={ __(
								'Confirm email address',
								'woocommerce'
							) }
							type="email"
							value={ confirmEmail }
							onChange={ setConfirmEmail }
							onBlur={ () => setHasConfirmBlurred( true ) }
							aria-invalid={
								confirmEmailError ? true : undefined
							}
							aria-describedby={
								confirmEmailError
									? NOTIFICATIONS_EMAIL_CONFIRM_ERROR_ID
									: undefined
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</>
				) }
			</FieldGroup>
		</SettingsSection>
	);
};

export { NotificationsSettingsSection };
