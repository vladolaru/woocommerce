/**
 * Hooks: Provide the main API for components to interact with the store.
 *
 * These encapsulate store interactions, offering a consistent interface.
 * Hooks simplify data access and manipulation for components.
 *
 * @file
 */

import { useDispatch, useSelect } from '@wordpress/data';

import { STORE_NAME } from './constants';
import { createHooksForStore } from '@ppcp-settings/data/utils';
import { useMemo } from '@wordpress/element';

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

export const useStore = () => {
	const { select, useTransient, dispatch } = useStoreData();
	const { persist, refresh, setPersistent, changePaymentSettings } = dispatch;
	const [ isReady ] = useTransient( 'isReady' );

	// Load persistent data from REST if not done yet.
	if ( ! isReady ) {
		select.persistentData();
	}

	return {
		persist,
		refresh,
		setPersistent,
		changePaymentSettings,
		isReady,
	};
};

export const usePaymentMethods = () => {
	const { usePersistent } = useStoreData();

	// PayPal checkout.
	const [ paypal ] = usePersistent( 'ppcp-gateway' );
	const [ venmo ] = usePersistent( 'venmo' );
	const [ payLater ] = usePersistent( 'pay-later' );

	const removeEmpty = ( list ) =>
		list.filter( ( item ) => item && item.id?.length );

	const payPalCheckout = removeEmpty( [ paypal, venmo, payLater ] );

	// PayPal checkout is the only group, so `all` and `paypal` are the same list. Callers read `all` for lookups and
	// `paypal` for the checkout card.
	return {
		all: payPalCheckout,
		paypal: payPalCheckout,
	};
};

export const usePaymentMethodsModal = () => {
	const { usePersistent } = useStoreData();

	const [ paypalShowLogo ] = usePersistent( 'paypalShowLogo' );

	return {
		paypalShowLogo,
	};
};
