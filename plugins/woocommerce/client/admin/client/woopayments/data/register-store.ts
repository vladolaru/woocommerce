/**
 * External dependencies
 */
import { register, select } from '@wordpress/data';

const attemptedStoreNames = new Set< string >();

/**
 * Register a WooPayments data store on first use, once. The WooPayments extension registers stores under the same
 * names, so a store already registered under the name is left in place.
 *
 * @param store The store descriptor.
 */
export function registerStoreUnlessTaken(
	store: Parameters< typeof register >[ 0 ]
): void {
	if ( attemptedStoreNames.has( store.name ) ) {
		return;
	}

	attemptedStoreNames.add( store.name );

	if ( select( store.name ) !== undefined ) {
		return;
	}

	register( store );
}
