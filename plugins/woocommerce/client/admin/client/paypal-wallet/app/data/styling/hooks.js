/**
 * Hooks: Provide the main API for components to interact with the store.
 *
 * These encapsulate store interactions, offering a consistent interface.
 * Hooks simplify data access and manipulation for components.
 *
 * @file
 */

import { useCallback, useMemo } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';

import { createHooksForStore } from '../utils';
import { STORE_NAME } from './constants';
import {
	STYLING_COLORS,
	STYLING_LABELS,
	STYLING_LAYOUTS,
	STYLING_LOCATIONS,
	STYLING_PAYMENT_METHODS,
	STYLING_SHAPES,
} from './configuration';

/**
 * Single source of truth for access Redux details.
 *
 * This hook returns a stable API to access actions, selectors and special hooks to generate
 * getter- and setters for transient or persistent properties.
 *
 * @return {{select, dispatch, useTransient, usePersistent}} Store data API.
 */
const useStoreData = () => {
	const select = useSelect( ( selectors ) => selectors( STORE_NAME ), [] );
	const dispatch = useDispatch( STORE_NAME );
	const { useTransient, usePersistent } = createHooksForStore( STORE_NAME );

	return useMemo(
		() => ( {
			select,
			dispatch,
			useTransient,
			usePersistent,
		} ),
		[ select, dispatch, useTransient, usePersistent ]
	);
};

const useHooks = () => {
	const { useTransient, dispatch } = useStoreData();
	const { setPersistent } = dispatch;

	// Transient accessors.
	const [ location, setLocation ] = useTransient( 'location' );

	// Persistent accessors.
	const persistentData = useSelect(
		( select ) => select( STORE_NAME ).persistentData(),
		[]
	);

	const getLocationProp = useCallback(
		( locationId, prop ) => {
			if ( undefined === persistentData[ locationId ]?.[ prop ] ) {
				return null;
			}
			return persistentData[ locationId ][ prop ];
		},
		[ persistentData ]
	);

	const setLocationProp = useCallback(
		( locationId, prop, value ) => {
			const updatedStyles = {
				...persistentData[ locationId ],
				[ prop ]: value,
			};

			setPersistent( locationId, updatedStyles );
		},
		[ persistentData, setPersistent ]
	);

	return {
		location,
		setLocation,
		getLocationProp,
		setLocationProp,
	};
};

export const useStore = () => {
	const { select, dispatch, useTransient } = useStoreData();
	const { persist, refresh } = dispatch;
	const [ isReady ] = useTransient( 'isReady' );

	// Load persistent data from REST if not done yet.
	if ( ! isReady ) {
		select.persistentData();
	}

	return { persist, refresh, isReady };
};

export const useStylingLocation = () => {
	const { location, setLocation } = useHooks();
	return { location, setLocation };
};

const sanitizeEnabled = ( value ) => ( undefined === value ? true : !! value );

export const useLocationProps = ( location ) => {
	const { getLocationProp, setLocationProp } = useHooks();
	const details = STYLING_LOCATIONS[ location ] ?? {};

	return {
		choices: Object.values( STYLING_LOCATIONS ),
		details,
		isActive: sanitizeEnabled( getLocationProp( location, 'enabled' ) ),
		setActive: ( state ) =>
			setLocationProp( location, 'enabled', sanitizeEnabled( state ) ),
	};
};

const sanitizeMethods = ( value ) => {
	if ( Array.isArray( value ) ) {
		return value;
	}
	return value ? [ value ] : [];
};

export const usePaymentMethodProps = ( location ) => {
	const { getLocationProp, setLocationProp } = useHooks();

	return {
		choices: Object.values( STYLING_PAYMENT_METHODS ),
		paymentMethods: sanitizeMethods(
			getLocationProp( location, 'methods' )
		),
		setPaymentMethods: ( methods ) =>
			setLocationProp( location, 'methods', sanitizeMethods( methods ) ),
	};
};

const sanitizeColor = ( value ) => {
	const isValidColor = Object.values( STYLING_COLORS ).some(
		( color ) => color.value === value
	);
	return isValidColor ? value : STYLING_COLORS.gold.value;
};

export const useColorProps = ( location ) => {
	const { getLocationProp, setLocationProp } = useHooks();

	return {
		choices: Object.values( STYLING_COLORS ),
		color: sanitizeColor( getLocationProp( location, 'color' ) ),
		setColor: ( color ) =>
			setLocationProp( location, 'color', sanitizeColor( color ) ),
	};
};

const sanitizeShape = ( value ) => {
	const isValidColor = Object.values( STYLING_SHAPES ).some(
		( color ) => color.value === value
	);
	return isValidColor ? value : STYLING_SHAPES.rect.value;
};

export const useShapeProps = ( location ) => {
	const { getLocationProp, setLocationProp } = useHooks();

	return {
		choices: Object.values( STYLING_SHAPES ),
		shape: sanitizeShape( getLocationProp( location, 'shape' ) ),
		setShape: ( shape ) =>
			setLocationProp( location, 'shape', sanitizeShape( shape ) ),
	};
};

const sanitizeLabel = ( value ) => {
	const isValidColor = Object.values( STYLING_LABELS ).some(
		( color ) => color.value === value
	);
	return isValidColor ? value : STYLING_LABELS.paypal.value;
};

export const useLabelProps = ( location ) => {
	const { getLocationProp, setLocationProp } = useHooks();

	return {
		choices: Object.values( STYLING_LABELS ),
		label: sanitizeLabel( getLocationProp( location, 'label' ) ),
		setLabel: ( label ) =>
			setLocationProp( location, 'label', sanitizeLabel( label ) ),
	};
};

const sanitizeLayout = ( value ) => {
	const isValidColor = Object.values( STYLING_LAYOUTS ).some(
		( color ) => color.value === value
	);
	return isValidColor ? value : STYLING_LAYOUTS.vertical.value;
};

export const useLayoutProps = ( location ) => {
	const { getLocationProp, setLocationProp } = useHooks();
	const { details } = useLocationProps( location );
	const isAvailable = details.props.layout !== false;

	return {
		choices: Object.values( STYLING_LAYOUTS ),
		isAvailable,
		layout: sanitizeLayout( getLocationProp( location, 'layout' ) ),
		setLayout: ( layout ) =>
			setLocationProp( location, 'layout', sanitizeLayout( layout ) ),
	};
};

const sanitizeTagline = ( value ) => !! value;

export const useTaglineProps = ( location ) => {
	const { getLocationProp, setLocationProp } = useHooks();
	const { details } = useLocationProps( location );

	// Tagline is only available for horizontal layouts.
	const isAvailable =
		details.props.tagline !== false &&
		STYLING_LAYOUTS.horizontal.value ===
			getLocationProp( location, 'layout' );

	return {
		isAvailable,
		tagline: isAvailable
			? sanitizeTagline( getLocationProp( location, 'tagline' ) )
			: false,
		setTagline: ( tagline ) =>
			setLocationProp( location, 'tagline', sanitizeTagline( tagline ) ),
	};
};
