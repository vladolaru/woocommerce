/**
 * Internal dependencies
 */
import {
	saveOption,
	updateDismissedDuplicatePaymentMethodNotices,
} from '../data/actions';
import { type FieldValidationError } from '../settings-shell';
import type { PmPromotion } from '../../promotions/types';
import {
	useDismissedDuplicatePaymentMethodNotices,
	useGetDuplicatedPaymentMethodIds,
} from '../data/hooks';
import { asSettingsRecord, asString } from '../settings-values';

const EMAIL_ADDRESS_PATTERN =
	/^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9.-]+$/;
type StringSetter = ( value: string ) => void;
type BooleanSetter = ( value: boolean ) => void;
type ValidationSetter = ( error: FieldValidationError | null ) => void;
type StringArraySetter = ( value: string[] ) => void;
type BooleanSetting = [ boolean, BooleanSetter ];
type StringSetting = [ string, StringSetter ];
type StringArraySetting = [ string[], StringArraySetter ];

type PaymentMethodStatus = {
	status?: string;
	requirements?: unknown[];
};
type AccountFees = Record< string, Record< string, unknown > | undefined >;
type DuplicatePaymentMethodNotices = Record< string, string[] | undefined >;

const BNPL_METHOD_IDS = [ 'affirm', 'afterpay_clearpay', 'klarna' ];

const asAccountFees = ( value: unknown ): AccountFees =>
	value && typeof value === 'object' ? ( value as AccountFees ) : {};

const asDuplicatePaymentMethodNotices = (
	value: unknown
): DuplicatePaymentMethodNotices =>
	value && typeof value === 'object'
		? ( value as DuplicatePaymentMethodNotices )
		: {};
const asPmPromotions = ( value: unknown ): PmPromotion[] =>
	Array.isArray( value )
		? value.filter(
				( promotion ): promotion is PmPromotion =>
					Boolean( promotion ) &&
					typeof promotion === 'object' &&
					'id' in promotion &&
					'payment_method' in promotion &&
					'type' in promotion &&
					'title' in promotion
			)
		: [];

const getSavingErrorDetailMessage = ( value: unknown, key: string ) => {
	const error = asSettingsRecord( value );
	const data = asSettingsRecord( error.data );
	const details = asSettingsRecord( data.details );
	const detail = asSettingsRecord( details[ key ] );

	return asString( detail.message );
};

const isValidEmailAddress = ( value: string ) =>
	value === '' ||
	( value.length <= 254 && EMAIL_ADDRESS_PATTERN.test( value ) );

/**
 * Shared state and handler for dismissing duplicate payment method notices.
 *
 * Collapses the identical wiring (duplicated method ids, the dismissed-notices
 * state, and the dismiss handler that persists the option) that the payment
 * methods, BNPL, and express checkout sections all need.
 */
const useDuplicatePaymentMethodNotices = () => {
	const duplicatedPaymentMethodIds = asDuplicatePaymentMethodNotices(
		useGetDuplicatedPaymentMethodIds()
	);
	const [
		dismissedDuplicatePaymentMethodNotices,
		setDismissedDuplicatePaymentMethodNotices,
	] = useDismissedDuplicatePaymentMethodNotices() as [
		DuplicatePaymentMethodNotices,
		typeof updateDismissedDuplicatePaymentMethodNotices,
	];

	const onDismissDuplicateNotice = (
		notices: Record< string, string[] >
	) => {
		setDismissedDuplicatePaymentMethodNotices( notices );
		void saveOption(
			'wcpay_duplicate_payment_method_notices_dismissed',
			notices
		);
	};

	return {
		duplicatedPaymentMethodIds,
		dismissedDuplicatePaymentMethodNotices,
		onDismissDuplicateNotice,
	};
};

export type {
	ValidationSetter,
	BooleanSetting,
	StringSetting,
	StringArraySetting,
	PaymentMethodStatus,
};

export {
	BNPL_METHOD_IDS,
	asAccountFees,
	asPmPromotions,
	getSavingErrorDetailMessage,
	isValidEmailAddress,
	useDuplicatePaymentMethodNotices,
};
