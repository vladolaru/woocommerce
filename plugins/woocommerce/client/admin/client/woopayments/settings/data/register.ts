/**
 * External dependencies
 */
import { register, select } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { store, STORE_NAME } from './store';

let attempted = false;

export function registerWooPaymentsSettingsStore(): void {
	if ( attempted ) {
		return;
	}

	attempted = true;

	if ( select( STORE_NAME ) !== undefined ) {
		return;
	}

	register( store );
}

export { STORE_NAME };
