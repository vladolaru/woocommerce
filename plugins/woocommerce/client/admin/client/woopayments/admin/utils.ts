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
