/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

export const fetchSummary = async < T extends object >(
	path: string
): Promise< Partial< T > > => {
	const summary = await apiFetch< T | null | undefined >( {
		path,
		method: 'GET',
	} );

	return summary ?? {};
};
