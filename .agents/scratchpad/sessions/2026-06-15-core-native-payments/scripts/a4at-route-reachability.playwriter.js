const fs = require( 'node:fs' );

const target = 'http://store8889.localhost:8889';
const outputPath =
	'.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4at-route-reachability-browser.json';

const routes = [
	{
		name: 'connect',
		legacyPath: '/payments/connect',
		expectedPath: '/woopayments/overview',
	},
	{
		name: 'onboarding',
		legacyPath: '/payments/onboarding',
		expectedPath: '/woopayments/overview',
	},
	{
		name: 'onboarding-kyc',
		legacyPath: '/payments/onboarding/kyc',
		expectedPath: '/woopayments/overview',
	},
	{
		name: 'fraud-protection',
		legacyPath: '/payments/fraud-protection',
		expectedPath: '/woopayments/settings/fraud-protection',
	},
	{
		name: 'multi-currency-setup',
		legacyPath: '/payments/multi-currency-setup',
		expectedPath: '/woopayments/settings',
		expectedFragment: 'advanced',
		sectionText: 'Enable multi-currency',
	},
	{
		name: 'additional-payment-methods',
		legacyPath: '/payments/additional-payment-methods',
		expectedPath: '/woopayments/settings',
		expectedFragment: 'payment-methods',
		sectionText: 'Payments accepted on checkout',
	},
];

const allowedLogPatterns = [
	/JQMIGRATE/i,
	/Permissions policy violation: unload is not allowed in this document/i,
];

const normalizeLog = ( log ) => {
	if ( 'string' === typeof log ) {
		return log;
	}

	if ( log && 'object' === typeof log ) {
		return log.text || log.message || JSON.stringify( log );
	}

	return String( log );
};

const isAllowedLog = ( log ) =>
	allowedLogPatterns.some( ( pattern ) => pattern.test( normalizeLog( log ) ) );

state.a4atPage = state.a4atPage || ( await context.newPage() );
const page = state.a4atPage;
page.setDefaultTimeout( 45000 );

const results = [];

for ( const route of routes ) {
	const requestedUrl = `${ target }/wp-admin/admin.php?page=wc-admin&path=${ encodeURIComponent(
		route.legacyPath
	) }`;

	await page.goto( requestedUrl, {
		waitUntil: 'domcontentloaded',
		timeout: 45000,
	} );
	await page.waitForLoadState( 'domcontentloaded' );
	await page.waitForURL( ( url ) => url.searchParams.get( 'page' ) === 'wc-settings', {
		timeout: 45000,
	} );

	const finalUrl = page.url();
	const parsed = new URL( finalUrl );
	const actualPage = parsed.searchParams.get( 'page' );
	const actualTab = parsed.searchParams.get( 'tab' );
	const actualPath = parsed.searchParams.get( 'path' );
	const actualSection = parsed.searchParams.get( 'section' );
	const actualFragment = parsed.hash.replace( /^#/, '' ) || null;
	const sectionChecks = {};

	if ( route.expectedFragment ) {
		const section = page.locator( `#${ route.expectedFragment }` );
		await section.waitFor( { state: 'attached', timeout: 20000 } );
		sectionChecks.sectionAttached = true;

		if ( route.sectionText ) {
			await section.getByText( route.sectionText, { exact: false } ).waitFor( {
				state: 'visible',
				timeout: 20000,
			} );
			sectionChecks.sectionTextVisible = true;
		}
	}

	const logs = ( await getLatestLogs( { page, sinceLastCall: true } ) ).map(
		normalizeLog
	);
	const unexpectedLogs = logs.filter( ( log ) => ! isAllowedLog( log ) );
	const pageSnapshot = await snapshot( { page } );
	const pass =
		actualPage === 'wc-settings' &&
		actualTab === 'checkout' &&
		actualPath === route.expectedPath &&
		null === actualSection &&
		( route.expectedFragment || null ) === actualFragment &&
		0 === unexpectedLogs.length &&
		( ! route.expectedFragment ||
			( true === sectionChecks.sectionAttached &&
				true === sectionChecks.sectionTextVisible ) );

	results.push( {
		name: route.name,
		requestedUrl,
		finalUrl,
		expectedPath: route.expectedPath,
		actualPage,
		actualTab,
		actualPath,
		expectedFragment: route.expectedFragment || null,
		actualFragment,
		actualSection: actualSection || null,
		sectionChecks,
		logs,
		unexpectedLogs,
		snapshotExcerpt: pageSnapshot.slice( 0, 3000 ),
		pass,
	} );
}

page.removeAllListeners();

const evidence = {
	status: results.every( ( result ) => result.pass ) ? 'pass' : 'fail',
	generatedAt: new Date().toISOString(),
	target,
	results,
};

fs.writeFileSync( outputPath, `${ JSON.stringify( evidence, null, 2 ) }\n` );
console.log( JSON.stringify( evidence, null, 2 ) );

if ( 'pass' !== evidence.status ) {
	throw new Error( 'A4at route reachability browser matrix failed' );
}
