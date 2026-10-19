import { stripVTControlCharacters } from 'node:util';

import { expect, test, type Page } from '@playwright/test';

import { wpCLI } from '../../../utils/cli';
import { admin } from '../../../test-data/data';
import { logIn } from '../../../utils/login';

/**
 * The per-site activation family on the multisite e2e store (`:8601`): four
 * sites with the WooPayments extension active for the whole network. No site
 * can switch to WooPayments in WooCommerce while the extension is active for
 * every site; a super admin turns the network activation into one activation
 * per site from the network admin, and each site then switches on its own.
 * Provider-free: the store runs the secretless CI provider fixture and no
 * platform connection, so no switch reaches its ownership check here (the
 * single-site transition project carries the click-to-done proof).
 *
 * The family runs in order. It restores the options it sets, the plugin
 * lists, the site statuses and the cutover actions, plus `recently_activated`
 * and the Storefront widgets the first admin visit clears; WordPress's own
 * cron entries and Jetpack's plugin bookkeeping from the activations stay.
 */

const STORE = ( process.env.BASE_URL ?? 'http://localhost:8601' ).replace(
	/\/+$/,
	''
);
const SITES = [ '/', '/shop1/', '/shop2/', '/shop3/' ] as const;
type SitePath = ( typeof SITES )[ number ];
const RESULT_MARKER = '__E2E_PER_SITE_RESULT__';
const RESTORED_OPTIONS = [
	'active_plugins',
	'woocommerce_feature_woopayments_enabled',
	'woocommerce_woopayments_cutover_state',
	'woocommerce_woopayments_cutover_admin_classification',
	'woocommerce_woopayments_setup_tier',
	'recently_activated',
	'sidebars_widgets',
	'storefront_cleared_widgets',
];

interface SiteState {
	blog_id: number;
	archived: string;
	site_active: boolean;
	sitewide_active: boolean;
	owner: string;
	awaiting_switch: boolean;
	record: {
		state: string;
		generation: number;
		current_step: string;
		step_log: Array< { context?: { source?: string } } >;
	} | null;
}

interface Snapshot {
	sitewide: unknown;
	sites: Record<
		string,
		{
			blog_id: number;
			archived: string;
			options: Record< string, { absent: true } | { value: unknown } >;
		}
	>;
}

/**
 * Evaluate PHP on one site of the network and return its JSON result.
 */
async function siteEval< Result >(
	site: SitePath,
	phpBody: string
): Promise< Result > {
	const source = `return ( static function () {\n${ phpBody }\n} )();`;
	const encoded = Buffer.from( source, 'utf8' ).toString( 'base64' );
	const php = `$e2e_source = base64_decode( '${ encoded }', true ); echo "\\n${ RESULT_MARKER }" . wp_json_encode( eval( $e2e_source ) ) . "\\n";`;
	const { stdout } = await wpCLI( [
		'wp',
		`--url=${ STORE }${ site }`,
		'--user=1',
		'eval',
		php,
	] );
	const line = stdout
		.split( /\r?\n/ )
		.map( ( entry ) => stripVTControlCharacters( entry ).trim() )
		.toReversed()
		.find( ( entry ) => entry.startsWith( RESULT_MARKER ) );
	if ( ! line ) {
		throw new Error(
			`No result from ${ site }: ${ stdout.slice( -400 ) }`
		);
	}
	return JSON.parse( line.slice( RESULT_MARKER.length ) ) as Result;
}

const SITE_STATE_PHP = String.raw`
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin  = 'woocommerce-payments/woocommerce-payments.php';
$arbiter = wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter::class );
$record  = get_option( 'woocommerce_woopayments_cutover_state', null );
return array(
	'blog_id'         => get_current_blog_id(),
	'archived'        => (string) get_site( get_current_blog_id() )->archived,
	'site_active'     => in_array( $plugin, (array) get_option( 'active_plugins', array() ), true ),
	'sitewide_active' => is_plugin_active_for_network( $plugin ),
	'owner'           => $arbiter->get_runtime_owner(),
	'awaiting_switch' => $arbiter->is_awaiting_switch(),
	'record'          => is_array( $record ) ? $record : null,
);
`;

function readSite( site: SitePath ): Promise< SiteState > {
	return siteEval< SiteState >( site, SITE_STATE_PHP );
}

function setFeature( site: SitePath, value: 'yes' | 'no' ): Promise< unknown > {
	return siteEval(
		site,
		`update_option( 'woocommerce_feature_woopayments_enabled', '${ value }' ); return true;`
	);
}

async function setArchived(
	site: SitePath,
	archived: '0' | '1'
): Promise< void > {
	const { blog_id: blogId } = await readSite( site );
	await siteEval(
		'/',
		`update_blog_status( ${ blogId }, 'archived', '${ archived }' ); return true;`
	);
}

function runPlugin(
	site: SitePath,
	action: 'activate' | 'deactivate'
): Promise< unknown > {
	return wpCLI( [
		'wp',
		`--url=${ STORE }${ site }`,
		'plugin',
		action,
		'woocommerce-payments',
	] );
}

async function goToSiteAdmin( page: Page, site: SitePath ): Promise< void > {
	await page.goto( `${ STORE }${ site }wp-admin/` );
}

test.skip(
	process.env.E2E_WOOPAYMENTS_MULTISITE !== 'true',
	'The multisite store profile (:8601) is opt-in.'
);

test.describe.configure( { mode: 'serial' } );

test.describe( 'per-site activation of the WooPayments extension', () => {
	let snapshot: Snapshot | null = null;

	test.beforeAll( async () => {
		const sites: Snapshot[ 'sites' ] = {};
		for ( const site of SITES ) {
			sites[ site ] = await siteEval(
				site,
				String.raw`
$options = array();
foreach ( ${ JSON.stringify( RESTORED_OPTIONS )
					.replace( /^\[/, 'array(' )
					.replace( /\]$/, ')' ) } as $name ) {
	$value            = get_option( $name, '__e2e_absent__' );
	$options[ $name ] = '__e2e_absent__' === $value ? array( 'absent' => true ) : array( 'value' => $value );
}
return array( 'blog_id' => get_current_blog_id(), 'archived' => (string) get_site( get_current_blog_id() )->archived, 'options' => $options );
`
			);
		}
		snapshot = {
			sitewide: await siteEval(
				'/',
				`return get_site_option( 'active_sitewide_plugins', array() );`
			),
			sites,
		};
		for ( const site of SITES ) {
			const state = await readSite( site );
			expect(
				state.sitewide_active,
				`${ site } starts with the extension active for every site`
			).toBe( true );
			expect(
				state.record,
				`${ site } starts with no cutover record`
			).toBeNull();
		}
	} );

	test.afterAll( async () => {
		if ( ! snapshot ) {
			return;
		}
		const saved = snapshot;
		for ( const site of SITES ) {
			const entry = saved.sites[ site ];
			const encoded = Buffer.from(
				JSON.stringify( entry.options ),
				'utf8'
			).toString( 'base64' );
			await siteEval(
				site,
				String.raw`
$options = json_decode( base64_decode( '${ encoded }' ), true );
foreach ( $options as $name => $saved ) {
	if ( ! empty( $saved['absent'] ) ) {
		delete_option( $name );
	} else {
		update_option( $name, $saved['value'] );
	}
}
as_unschedule_all_actions( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverActionScheduler::ACTION_HOOK );
global $wpdb;
$wpdb->delete( $wpdb->prefix . 'actionscheduler_actions', array( 'hook' => Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverActionScheduler::ACTION_HOOK ) );
update_blog_status( get_current_blog_id(), 'archived', '${ entry.archived }' );
return true;
`
			);
		}
		const sitewide = Buffer.from(
			JSON.stringify( saved.sitewide ),
			'utf8'
		).toString( 'base64' );
		await siteEval(
			'/',
			`update_site_option( 'active_sitewide_plugins', json_decode( base64_decode( '${ sitewide }' ), true ) ); return true;`
		);
	} );

	test( '@woopayments-multisite a super admin turns the network activation into per-site activations, an archived site included', async ( {
		page,
	}, testInfo ) => {
		for ( const site of [ '/shop1/', '/shop2/', '/shop3/' ] as const ) {
			await setFeature( site, 'yes' );
		}
		await setArchived( '/shop3/', '1' );

		// The conversion activates each site before it removes the network
		// activation; WordPress activates a network-active plugin on one site
		// from that site's own list. Checked here on the store, with the core
		// function the conversion calls: shop1 then has both activations, and
		// the conversion skips it as already active.
		const both = await siteEval< {
			error: string | null;
			site_active: boolean;
			sitewide_active: boolean;
		} >(
			'/shop1/',
			String.raw`
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin = 'woocommerce-payments/woocommerce-payments.php';
$result = activate_plugin( $plugin );
return array(
	'error'           => is_wp_error( $result ) ? $result->get_error_code() : null,
	'site_active'     => in_array( $plugin, (array) get_option( 'active_plugins', array() ), true ),
	'sitewide_active' => is_plugin_active_for_network( $plugin ),
);
`
		);
		expect( both ).toEqual( {
			error: null,
			site_active: true,
			sitewide_active: true,
		} );

		await page.goto( `${ STORE }/wp-login.php` );
		await logIn( page, admin.username, admin.password, false );

		await goToSiteAdmin( page, '/shop1/' );
		const refusal = page.locator( '.notice' ).filter( {
			hasText:
				'switch yet because the WooPayments extension is active for every site of the network.',
		} );
		await expect( refusal ).toContainText(
			'A super admin first needs to activate WooPayments on each site instead.'
		);
		await expect(
			page.getByRole( 'link', { name: 'Start the switch', exact: true } )
		).toHaveCount( 0 );
		await refusal.screenshot( {
			path: testInfo.outputPath( 'site-refusal-notice.png' ),
		} );

		await page.goto( `${ STORE }/wp-admin/network/plugins.php` );
		const offer = page.locator( '.notice' ).filter( {
			hasText:
				'No site can switch while the WooPayments extension is active for the whole network.',
		} );
		await expect( offer ).toBeVisible();
		await offer.screenshot( {
			path: testInfo.outputPath( 'network-admin-offer-notice.png' ),
		} );
		await offer
			.getByRole( 'link', {
				name: 'Activate WooPayments per site',
				exact: true,
			} )
			.click();

		await expect( page ).toHaveURL( /wc_woopayments_per_site_done=1/ );
		const result = page.locator( '.notice' ).filter( {
			hasText: 'WooPayments is now active on each site.',
		} );
		await expect( result ).toBeVisible();
		await expect(
			page
				.locator( '.notice' )
				.filter( { hasText: 'could not be activated' } )
		).toHaveCount( 0 );
		await result.screenshot( {
			path: testInfo.outputPath( 'network-admin-result-notice.png' ),
		} );

		for ( const site of SITES ) {
			const state = await readSite( site );
			expect(
				state.sitewide_active,
				`${ site } no longer has the extension for every site`
			).toBe( false );
			expect(
				state.site_active,
				`${ site } has its own activation`
			).toBe( true );
			expect(
				state.owner,
				`${ site } keeps the extension as its owner`
			).toBe( 'extension' );
			expect(
				state.record,
				`${ site } has no cutover record`
			).toBeNull();
		}

		await setArchived( '/shop3/', '0' );
		const restored = await readSite( '/shop3/' );
		expect( restored.archived ).toBe( '0' );
		expect(
			restored.site_active,
			'the restored site keeps its extension'
		).toBe( true );
	} );

	test( '@woopayments-multisite after the per-site activation a site offers its own switch', async ( {
		page,
	} ) => {
		await page.goto( `${ STORE }/wp-login.php` );
		await logIn( page, admin.username, admin.password, false );
		await goToSiteAdmin( page, '/shop1/' );

		await expect(
			page.getByRole( 'link', { name: 'Start the switch', exact: true } )
		).toBeVisible();
		await expect(
			page.locator( '.notice' ).filter( { hasText: 'switch yet' } )
		).toHaveCount( 0 );
	} );

	test( '@woopayments-multisite a site whose WooPayments extension is deactivated by hand waits for its switch', async ( {
		page,
	} ) => {
		await runPlugin( '/shop2/', 'deactivate' );

		const state = await readSite( '/shop2/' );
		expect( state.site_active ).toBe( false );
		expect(
			state.owner,
			'nothing owns payments while the site waits'
		).toBe( 'none' );
		expect( state.awaiting_switch ).toBe( true );
		expect(
			state.record,
			'a deactivation by hand records nothing'
		).toBeNull();

		await page.goto( `${ STORE }/wp-login.php` );
		await logIn( page, admin.username, admin.password, false );
		await goToSiteAdmin( page, '/shop2/' );
		await expect(
			page.getByRole( 'link', { name: 'Start the switch', exact: true } )
		).toBeVisible();
	} );

	test( '@woopayments-multisite a site that reactivates its WooPayments extension after starting its switch rolls the switch back', async ( {
		page,
	} ) => {
		await page.goto( `${ STORE }/wp-login.php` );
		await logIn( page, admin.username, admin.password, false );
		await goToSiteAdmin( page, '/shop3/' );
		await page
			.getByRole( 'link', { name: 'Start the switch', exact: true } )
			.click( { timeout: 30_000 } );
		await expect( page ).toHaveURL( /\/shop3\/wp-admin\/plugins\.php/ );

		const started = await readSite( '/shop3/' );
		expect(
			started.record,
			'the start opened a generation'
		).not.toBeNull();
		expect( started.record?.current_step ).not.toBe(
			'awaiting_merchant_start'
		);
		expect(
			started.site_active,
			'the switch cannot finish without a platform connection'
		).toBe( true );

		await runPlugin( '/shop3/', 'deactivate' );
		await runPlugin( '/shop3/', 'activate' );

		const rolledBack = await readSite( '/shop3/' );
		expect( rolledBack.owner ).toBe( 'extension' );
		expect( rolledBack.record?.generation ).toBe(
			( started.record?.generation ?? 0 ) + 1
		);
		expect( rolledBack.record?.current_step ).toBe(
			'awaiting_merchant_start'
		);
		expect( rolledBack.record?.step_log[ 0 ]?.context?.source ).toBe(
			'rollback'
		);

		await goToSiteAdmin( page, '/shop3/' );
		await expect(
			page.getByRole( 'link', { name: 'Start the switch', exact: true } )
		).toBeVisible();
	} );
} );
