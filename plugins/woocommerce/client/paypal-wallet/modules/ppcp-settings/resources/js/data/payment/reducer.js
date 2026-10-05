/**
 * Reducer: Defines store structure and state updates for this module.
 *
 * Manages both transient (temporary) and persistent (saved) state.
 * The initial state must define all properties, as dynamic additions are not supported.
 *
 * @file
 */

import { createReducer, createReducerSetters } from '@ppcp-settings/data/utils';
import ACTION_TYPES from './action-types';

// Store structure.

// Transient: Values that are _not_ saved to the DB (like app lifecycle-flags).
const defaultTransient = Object.freeze( {
	isReady: false,
} );

// Persistent: Values that are loaded from the DB.
const defaultPersistent = Object.freeze( {
	// Payment methods.
	'ppcp-gateway': {},
	venmo: {},
	'pay-later': {},

	// Custom payment method properties.
	paypalShowLogo: false,
	__meta: false,
} );

// Keys the dependency logic adds to a payment method's data. They are saved with the method, so the names stay as they are.
const DISABLED_BY_DEPENDENCY = '_disabledByDependency';
const ORIGINAL_STATE = '_originalState';

// Reducer logic.

const [ changeTransient, changePersistent ] = createReducerSetters(
	defaultTransient,
	defaultPersistent
);

const reducer = createReducer( defaultTransient, defaultPersistent, {
	[ ACTION_TYPES.SET_TRANSIENT ]: ( state, payload ) =>
		changeTransient( state, payload ),

	[ ACTION_TYPES.SET_PERSISTENT ]: ( state, payload ) =>
		changePersistent( state, payload ),

	[ ACTION_TYPES.CHANGE_PAYMENT_SETTING ]: ( state, payload ) => {
		const methodId = payload.id;
		const oldProps = state.data[ methodId ];

		if ( ! oldProps || oldProps.id !== methodId ) {
			return state;
		}

		return changePersistent( state, {
			[ methodId ]: { ...oldProps, ...payload.props },
		} );
	},

	[ ACTION_TYPES.RESET ]: ( state ) => {
		const cleanState = changeTransient(
			changePersistent( state, defaultPersistent ),
			defaultTransient
		);

		// Keep "read-only" details and initialization flags.
		cleanState.isReady = true;

		return cleanState;
	},

	[ ACTION_TYPES.HYDRATE ]: ( state, payload ) =>
		changePersistent( state, payload.data ),

	[ ACTION_TYPES.SET_DISABLED_BY_DEPENDENCY ]: ( state, payload ) => {
		const { methodId } = payload;
		const method = state.data[ methodId ];

		if ( ! method ) {
			return state;
		}

		// Create a new state with the method disabled due to dependency
		const updatedData = {
			...state.data,
			[ methodId ]: {
				...method,
				enabled: false,
				[ DISABLED_BY_DEPENDENCY ]: true,
				[ ORIGINAL_STATE ]: method.enabled,
			},
		};

		return {
			...state,
			data: updatedData,
		};
	},

	[ ACTION_TYPES.RESTORE_DEPENDENCY_STATE ]: ( state, payload ) => {
		const { methodId } = payload;
		const method = state.data[ methodId ];

		if ( ! method || ! method[ DISABLED_BY_DEPENDENCY ] ) {
			return state;
		}

		// Restore the method to its original state
		const updatedData = {
			...state.data,
			[ methodId ]: {
				...method,
				enabled: method[ ORIGINAL_STATE ] === true,
				[ DISABLED_BY_DEPENDENCY ]: false,
				[ ORIGINAL_STATE ]: undefined,
			},
		};

		return {
			...state,
			data: updatedData,
		};
	},
} );

export default reducer;
