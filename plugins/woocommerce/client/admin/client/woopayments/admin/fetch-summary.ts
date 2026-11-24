/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/** A successful summary GET may have no body, including an HTTP 204. */
export const fetchSummary = async < T extends object >(
	path: string,
	signal?: AbortSignal
): Promise< T | Record< string, never > > => {
	const summary = await apiFetch< T | null | undefined >( {
		path,
		method: 'GET',
		signal,
	} );

	return summary ?? {};
};
