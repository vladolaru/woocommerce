/**
 * External dependencies
 */
import { isEqual } from 'lodash';

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
	// Counts the resets, so a request can tell that the settings visit it started in has closed.
	visit: number;
};

const defaultState: SettingsState = {
	isDirty: false,
	isSaving: false,
	savingError: null,
	data: {},
	savedData: {},
	visit: 0,
};

// Lists the server only reads as sets. Turning an item off and on again moves it to the end without changing anything.
// Every other list, such as the fraud protection rules, keeps its order significant.
const ORDER_FREE_LIST_SETTINGS = [
	'enabled_payment_method_ids',
	'express_checkout_product_methods',
	'express_checkout_cart_methods',
	'express_checkout_checkout_methods',
];

// The dirty check and the save both compare a setting with its saved value through this, so they cannot disagree.
export const isSameSetting = (
	key: string,
	value: unknown,
	savedValue: unknown
) =>
	ORDER_FREE_LIST_SETTINGS.includes( key ) &&
	Array.isArray( value ) &&
	Array.isArray( savedValue )
		? isEqual( [ ...value ].sort(), [ ...savedValue ].sort() )
		: isEqual( value, savedValue );

const isSameSettings = ( data: SettingsData, savedData: SettingsData ) =>
	Object.keys( { ...data, ...savedData } ).every( ( key ) =>
		isSameSetting( key, data[ key ], savedData[ key ] )
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

		case ACTION_TYPES.RESET_SETTINGS:
			return { ...defaultState, visit: state.visit + 1 };

		case ACTION_TYPES.SET_SETTINGS_VALUES:
			return withEditedData(
				{ ...state, savingError: null },
				{
					...state.data,
					...( action.payload ?? {} ),
				}
			);

		// Values saved on their own: they change the saved copy too, and leave any other unsaved edit as it is.
		case ACTION_TYPES.SET_SAVED_SETTINGS_VALUES:
			return withEditedData(
				{
					...state,
					savedData: {
						...state.savedData,
						...( action.payload ?? {} ),
					},
				},
				{ ...state.data, ...( action.payload ?? {} ) }
			);

		// A failed save's fresh read becomes the saved copy and the edits stay; without a read, nothing counts as saved.
		case ACTION_TYPES.SET_SAVED_SETTINGS:
			return withEditedData(
				{ ...state, savedData: action.data ?? {} },
				state.data
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
