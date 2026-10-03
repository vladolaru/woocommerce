/**
 * External dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { asSettingsRecord, asString } from './express-checkout/settings-utils';
import { useGetSavingError, useGetSettings, useSettings } from './data/hooks';
import {
	focusField,
	SettingsSaveBar,
	type FieldValidationError,
} from './settings-shell';
import { WooPayDisableFeedback } from './woopay-disable-feedback';

export const NOTIFICATIONS_EMAIL_INPUT_ID =
	'account-communications-email-input';
export const ACCOUNT_STATEMENT_INPUT_ID = 'account-statement-descriptor-input';
export const ACCOUNT_STATEMENT_KANJI_INPUT_ID =
	'account-statement-descriptor-kanji-input';
export const ACCOUNT_STATEMENT_KANA_INPUT_ID =
	'account-statement-descriptor-kana-input';
export const SUPPORT_EMAIL_INPUT_ID = 'account-business-support-email-input';
export const SUPPORT_PHONE_INPUT_ID = 'account-business-support-phone-input';
const FEEDBACK_THROTTLE_DAYS = 7;

const getDaysSinceDate = ( date: string, now = new Date() ) => {
	const parsedDate = new Date( date );

	if ( Number.isNaN( parsedDate.getTime() ) ) {
		return Number.POSITIVE_INFINITY;
	}

	const diffTime = Math.abs( now.getTime() - parsedDate.getTime() );

	return Math.ceil( diffTime / ( 1000 * 60 * 60 * 24 ) );
};

const isWooPayDisableFeedbackThrottled = ( date: string ) =>
	date !== '' && getDaysSinceDate( date ) < FEEDBACK_THROTTLE_DAYS;

const getSavingErrorDetails = ( value: unknown ) => {
	const error = asSettingsRecord( value );
	const data = asSettingsRecord( error.data );

	return asSettingsRecord( data.details );
};

const getFieldInputId = ( settingKey: string ) => {
	const fieldInputIds: Record< string, string > = {
		account_statement_descriptor: ACCOUNT_STATEMENT_INPUT_ID,
		account_statement_descriptor_kanji: ACCOUNT_STATEMENT_KANJI_INPUT_ID,
		account_statement_descriptor_kana: ACCOUNT_STATEMENT_KANA_INPUT_ID,
		account_communications_email: NOTIFICATIONS_EMAIL_INPUT_ID,
		account_business_support_email: SUPPORT_EMAIL_INPUT_ID,
		account_business_support_phone: SUPPORT_PHONE_INPUT_ID,
	};

	return (
		fieldInputIds[ settingKey ] ||
		`${ settingKey.replace( /_/g, '-' ) }-input`
	);
};

const focusFirstSavingErrorField = ( savingError: unknown ) => {
	const details = getSavingErrorDetails( savingError );

	focusField(
		Object.keys( details )
			.map( ( fieldKey ) =>
				document.getElementById( getFieldInputId( fieldKey ) )
			)
			.find( Boolean )
	);
};

/**
 * Saves the WooPayments settings store, as client 11.1.0's SaveSettingsSection does on the settings page and the
 * express checkout pages: on success it asks for WooPay disable feedback and records an Apple Pay / Google Pay
 * change; on failure it focuses the first field the server rejected.
 */
export const SaveSettingsSection = ( {
	disabled,
	validationError,
}: {
	disabled?: boolean;
	validationError?: FieldValidationError | null;
} ) => {
	const { saveSettings, isSaving, isLoading, isDirty } = useSettings();
	const settings = asSettingsRecord( useGetSettings() );
	const savingError = useGetSavingError();
	const [ initialIsWooPayEnabled, setInitialIsWooPayEnabled ] = useState<
		boolean | null
	>( null );
	const [
		initialIsPaymentRequestEnabled,
		setInitialIsPaymentRequestEnabled,
	] = useState< boolean | null >( null );
	const [ isWooPayDisableFeedbackOpen, setWooPayDisableFeedbackOpen ] =
		useState( false );
	const [ shouldFocusSavingError, setShouldFocusSavingError ] =
		useState( false );
	const [ localWooPayLastDisableDate, setLocalWooPayLastDisableDate ] =
		useState( asString( settings.woopay_last_disable_date ) );
	const hasWooPayEnabledSetting = Object.prototype.hasOwnProperty.call(
		settings,
		'is_woopay_enabled'
	);
	const hasPaymentRequestEnabledSetting =
		Object.prototype.hasOwnProperty.call(
			settings,
			'is_payment_request_enabled'
		);
	const isWooPayEnabled = Boolean( settings.is_woopay_enabled );
	const isPaymentRequestEnabled = Boolean(
		settings.is_payment_request_enabled
	);
	const wooPayLastDisableDate = asString( settings.woopay_last_disable_date );

	useEffect( () => {
		if ( initialIsWooPayEnabled !== null || ! hasWooPayEnabledSetting ) {
			return;
		}

		setInitialIsWooPayEnabled( isWooPayEnabled );
	}, [ hasWooPayEnabledSetting, initialIsWooPayEnabled, isWooPayEnabled ] );

	useEffect( () => {
		if (
			initialIsPaymentRequestEnabled !== null ||
			! hasPaymentRequestEnabledSetting
		) {
			return;
		}

		setInitialIsPaymentRequestEnabled( isPaymentRequestEnabled );
	}, [
		hasPaymentRequestEnabledSetting,
		initialIsPaymentRequestEnabled,
		isPaymentRequestEnabled,
	] );

	useEffect( () => {
		setLocalWooPayLastDisableDate( wooPayLastDisableDate );
	}, [ wooPayLastDisableDate ] );

	useEffect( () => {
		if ( ! shouldFocusSavingError ) {
			return;
		}

		focusFirstSavingErrorField( savingError );
		setShouldFocusSavingError( false );
	}, [ savingError, shouldFocusSavingError ] );

	const save = async () => {
		// The save outcome is announced by the snackbar only, as in the client.
		const isSuccess = await saveSettings();

		if ( ! isSuccess ) {
			setShouldFocusSavingError( true );
			return;
		}

		if (
			initialIsWooPayEnabled &&
			! isWooPayEnabled &&
			! isWooPayDisableFeedbackThrottled( localWooPayLastDisableDate )
		) {
			setWooPayDisableFeedbackOpen( true );
			setLocalWooPayLastDisableDate(
				new Date().toISOString().slice( 0, 10 )
			);
		}

		if ( hasWooPayEnabledSetting ) {
			setInitialIsWooPayEnabled( isWooPayEnabled );
		}

		if (
			hasPaymentRequestEnabledSetting &&
			initialIsPaymentRequestEnabled !== null &&
			initialIsPaymentRequestEnabled !== isPaymentRequestEnabled
		) {
			recordEvent( 'wcpay_payment_request_settings_change', {
				enabled: isPaymentRequestEnabled ? 'yes' : 'no',
			} );
		}

		if ( hasPaymentRequestEnabledSetting ) {
			setInitialIsPaymentRequestEnabled( isPaymentRequestEnabled );
		}
	};

	return (
		<SettingsSaveBar
			isDirty={ Boolean( isDirty ) }
			isSaving={ Boolean( isSaving ) }
			isDisabled={ Boolean( isLoading || disabled ) }
			validationError={ validationError }
			onSave={ save }
		>
			{ isWooPayDisableFeedbackOpen && (
				<WooPayDisableFeedback
					onRequestClose={ () =>
						setWooPayDisableFeedbackOpen( false )
					}
				/>
			) }
		</SettingsSaveBar>
	);
};
