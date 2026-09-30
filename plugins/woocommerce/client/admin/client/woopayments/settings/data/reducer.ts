/**
 * External dependencies
 */
import { isEqual, isEqualWith } from 'lodash';

/**
 * Internal dependencies
 */
import ACTION_TYPES from './action-types';

type SettingsData = Record< string, unknown > & {
	enabled_payment_method_ids?: string[];
};

type SettingsState = {
	isDirty: boolean;
	isSaving: boolean;
	savingError: unknown;
	data: SettingsData;
	savedData: SettingsData;
};

const defaultState: SettingsState = {
	isDirty: false,
	isSaving: false,
	savingError: null,
	data: {},
	savedData: {},
};

// Lists the server only reads as sets. Turning an item off and on again moves it to the end without changing anything.
// Every other list, such as the fraud protection rules, keeps its order significant.
const ORDER_FREE_LIST_SETTINGS = [
	'enabled_payment_method_ids',
	'express_checkout_product_methods',
	'express_checkout_cart_methods',
	'express_checkout_checkout_methods',
];

const isSameSettings = ( data: SettingsData, savedData: SettingsData ) =>
	isEqualWith( data, savedData, ( value, savedValue, key ) =>
		ORDER_FREE_LIST_SETTINGS.includes( String( key ) ) &&
		Array.isArray( value ) &&
		Array.isArray( savedValue )
			? isEqual( [ ...value ].sort(), [ ...savedValue ].sort() )
			: undefined
	);

// An edit that returns every setting to its saved value leaves nothing to save.
const withEditedData = (
	state: SettingsState,
	data: SettingsData
): SettingsState => ( {
	...state,
	data,
	isDirty: ! isSameSettings( data, state.savedData ),
} );

export const receiveSettings = (
	state = defaultState,
	action: {
		type?: string;
		data?: SettingsData;
		payload?: SettingsData;
		isSaving?: boolean;
		error?: unknown;
		id?: string;
	}
): SettingsState => {
	switch ( action.type ) {
		case ACTION_TYPES.SET_SETTINGS:
			return {
				...state,
				data: action.data ?? {},
				savedData: action.data ?? {},
				isDirty: false,
			};

		case ACTION_TYPES.SET_SETTINGS_VALUES:
			return withEditedData(
				{ ...state, savingError: null },
				{
					...state.data,
					...( action.payload ?? {} ),
				}
			);

		case ACTION_TYPES.SET_IS_SAVING_SETTINGS:
			return {
				...state,
				isDirty:
					action.isSaving || action.error ? state.isDirty : false,
				isSaving: Boolean( action.isSaving ),
				savingError: action.error ?? null,
			};

		case ACTION_TYPES.SET_SELECTED_PAYMENT_METHOD:
			return withEditedData( state, {
				...state.data,
				enabled_payment_method_ids: [
					...( state.data.enabled_payment_method_ids ?? [] ),
					action.id,
				].filter( Boolean ) as string[],
			} );

		case ACTION_TYPES.SET_UNSELECTED_PAYMENT_METHOD:
			return withEditedData( state, {
				...state.data,
				enabled_payment_method_ids: (
					state.data.enabled_payment_method_ids ?? []
				).filter( ( id ) => id !== action.id ),
			} );
	}

	return state;
};

export default receiveSettings;
