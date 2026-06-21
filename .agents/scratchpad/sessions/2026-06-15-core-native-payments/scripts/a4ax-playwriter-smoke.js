const fs = require( 'fs' );
const path = require( 'path' );

const evidenceDir = path.resolve(
	'.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4ax-express-settings-parity'
);
fs.mkdirSync( evidenceDir, { recursive: true } );

const baseUrl = 'http://store8889.localhost:8889';
const routes = [
	{
		name: 'settings-root-post-review',
		url: `${ baseUrl }/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings`,
		expect: async () => {
			await page
				.getByRole( 'heading', { name: 'Express checkouts' } )
				.waitFor( { timeout: 20000 } );
			await page
				.getByRole( 'checkbox', { name: 'WooPay', exact: true } )
				.waitFor();
			await page
				.getByRole( 'checkbox', {
					name: 'Apple Pay / Google Pay',
					exact: true,
				} )
				.waitFor();
			await page
				.getByRole( 'checkbox', { name: 'Link by Stripe', exact: true } )
				.waitFor();
			await page
				.getByRole( 'checkbox', { name: 'Amazon Pay', exact: true } )
				.waitFor();
		},
	},
	{
		name: 'payment-request-detail-post-review',
		url: `${ baseUrl }/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings%2Fexpress-checkout%2Fpayment_request`,
		expect: async () => {
			await page
				.getByRole( 'heading', {
					level: 1,
					name: 'Apple Pay / Google Pay',
				} )
				.waitFor( { timeout: 20000 } );
			await page
				.getByText(
					'Apple Pay, Google Pay, and Amazon Pay will appear as options in the payment methods list instead of as separate express checkout buttons.'
				)
				.waitFor();
		},
	},
	{
		name: 'woopay-detail-post-review',
		url: `${ baseUrl }/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings%2Fexpress-checkout%2Fwoopay`,
		expect: async () => {
			await page
				.getByRole( 'heading', { level: 1, name: 'WooPay' } )
				.waitFor( { timeout: 20000 } );
			await page.getByText( 'Checkout appearance' ).waitFor();
			await page.getByText( 'Checkout policies' ).waitFor();
		},
	},
	{
		name: 'amazon-pay-detail-post-review',
		url: `${ baseUrl }/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings%2Fexpress-checkout%2Famazon_pay`,
		expect: async () => {
			await page
				.getByRole( 'heading', { level: 1, name: 'Amazon Pay' } )
				.waitFor( { timeout: 20000 } );
			await page
				.getByRole( 'checkbox', {
					name: 'Enable Amazon Pay as an express payment button',
				} )
				.waitFor();
		},
	},
];

const allowedLogPatterns = [
	/JQMIGRATE/i,
	/Permissions policy violation: unload is not allowed/i,
];

const events = [];
page.on( 'console', ( message ) => {
	events.push( {
		type: message.type(),
		text: message.text(),
	} );
} );
page.on( 'pageerror', ( error ) => {
	events.push( {
		type: 'pageerror',
		text: error.message,
	} );
} );

const results = [];

for ( const route of routes ) {
	events.length = 0;
	await page.goto( route.url, { waitUntil: 'domcontentloaded' } );
	await page.waitForLoadState( 'networkidle', { timeout: 15000 } ).catch( () => {} );
	await route.expect();
	await page.screenshot( {
		path: path.join( evidenceDir, `${ route.name }.png` ),
		fullPage: true,
	} );

	const unexpectedLogs = events.filter(
		( event ) =>
			! allowedLogPatterns.some( ( pattern ) =>
				pattern.test( event.text )
			)
	);

	results.push( {
		name: route.name,
		url: page.url(),
		logs: events,
		unexpectedLogs,
	} );
}

const report = {
	status: results.every( ( result ) => result.unexpectedLogs.length === 0 )
		? 'pass'
		: 'fail',
	generatedAt: new Date().toISOString(),
	results,
};

fs.writeFileSync(
	path.join( evidenceDir, 'target-express-settings-smoke-post-review.json' ),
	JSON.stringify( report, null, 2 )
);

console.log( JSON.stringify( report, null, 2 ) );
