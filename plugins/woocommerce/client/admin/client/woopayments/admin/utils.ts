/**
 * External dependencies
 */
import { getHistory } from '@woocommerce/navigation';

export const getSettingsPaymentsProviderAdminPath = ( path: string ) => {
	const queryIndex = path.indexOf( '?' );
	const routePath = queryIndex === -1 ? path : path.slice( 0, queryIndex );
	const routeQuery = queryIndex === -1 ? '' : path.slice( queryIndex + 1 );
	const params = new URLSearchParams( {
		page: 'wc-settings',
		tab: 'checkout',
		path: routePath,
	} );

	new URLSearchParams( routeQuery ).forEach( ( value, key ) => {
		params.append( key === 'page' ? 'paged' : key, value );
	} );

	return `admin.php?${ params.toString() }`;
};

export const getSettingsPaymentsProviderRouteUrl = ( path: string ) => {
	const adminUrl = window.wcSettings?.adminUrl || '';
	const separator = adminUrl.endsWith( '/' ) || adminUrl === '' ? '' : '/';

	return `${ adminUrl }${ separator }${ getSettingsPaymentsProviderAdminPath(
		path
	) }`;
};

/**
 * Move to a settings-shell route with an admin.php URL relative to the current admin page, so the address bar
 * stays on admin.php, under a subdirectory install too. Client 11.1.0 does the same through `getHistory()` with
 * `getAdminUrl()` and `getNewPath()`.
 *
 * @param path            The route path, with its query.
 * @param options         Options.
 * @param options.replace Whether to replace the current history entry.
 */
export const navigateToSettingsPaymentsProviderRoute = (
	path: string,
	{ replace = false }: { replace?: boolean } = {}
) => {
	const adminPath = getSettingsPaymentsProviderAdminPath( path );

	if ( replace ) {
		getHistory().replace( adminPath );
	} else {
		getHistory().push( adminPath );
	}
};
