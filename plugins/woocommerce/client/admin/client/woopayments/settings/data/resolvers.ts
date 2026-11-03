/**
 * External dependencies
 */
import { dispatch, select } from '@wordpress/data';
import { apiFetch } from '@wordpress/data-controls';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ACTION_TYPES from './action-types';
import { NAMESPACE } from './constants';
import { updateSettings } from './actions';
import { STORE_NAME } from './store-name';

// A read from a closed settings visit never answers, as a discarded page's request does not: landing, it would write
// its settings into the next visit or mark that visit's own read as finished.
function* stopIfVisitClosed( visit: number ): Generator< unknown, void > {
	if ( select( STORE_NAME ).getSettingsVisit() !== visit ) {
		yield { type: ACTION_TYPES.NEVER_ANSWER };
	}
}

export function* getSettings(): Generator<
	unknown,
	void,
	Record< string, unknown >
> {
	const path = `${ NAMESPACE }/settings`;
	const visit = select( STORE_NAME ).getSettingsVisit();

	try {
		const result = yield apiFetch( { path } );

		yield* stopIfVisitClosed( visit );
		yield updateSettings( result );
	} catch ( e ) {
		yield* stopIfVisitClosed( visit );
		yield dispatch( 'core/notices' ).createErrorNotice(
			__( 'Error retrieving settings.', 'woocommerce' )
		);
	}
}
