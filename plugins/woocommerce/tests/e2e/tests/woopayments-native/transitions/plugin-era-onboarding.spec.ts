import { expect, tags, test } from '../../../fixtures/fixtures';
import { wpCLI, wpEvalJson } from '../../../utils/cli';
import { admin } from '../../../test-data/data';
import { logIn } from '../../../utils/login';

/**
 * A plugin-era store that has not switched to native keeps trunk's onboarding
 * path: core's NOX onboarding reads the KYC fields through the plugin's own
 * `/wc/v3/payments/onboarding/fields` route, and the fork's native API client
 * stays dormant. The seeded donor connection makes the native client
 * available, so a regression that calls it shows up in the recorder below.
 */

const RECORDER_FILE = 'e2e-outbound-recorder.php';

/**
 * A mu-plugin this case writes and removes. At the first `pre_http_request`
 * priority it records every request, redirect hops included, with the query
 * string (Jetpack signatures) left out and a caller tag read from the class
 * names on the stack. The native client sends the plugin's user agent, so
 * only the stack tells the two apart. At the last priority it answers every
 * request nobody else answered: a small fields_data body for the onboarding
 * fields, a WP_Error for the rest, so nothing reaches the network.
 */
const RECORDER_PHP = String.raw`<?php
/**
 * Plugin Name: E2E outbound HTTP recorder
 */

defined( 'ABSPATH' ) || exit;

function e2e_outbound_recorder_caller(): array {
	$classes = array();
	$depth   = -1;
	foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
		if ( ! isset( $frame['class'] ) ) {
			continue;
		}
		if ( 'WP_Http' === $frame['class'] && 'request' === $frame['function'] ) {
			++$depth;
		}
		$classes[ $frame['class'] ] = true;
	}
	$classes = array_keys( $classes );
	$native  = array_values( array_filter( $classes, static fn( $name ) => 0 === strpos( $name, 'Automattic\\WooCommerce\\Internal\\Payments\\' ) ) );
	$plugin  = array_values( array_intersect( $classes, array( 'WC_Payments_API_Client', 'WC_Payments_Http' ) ) );
	return array(
		'caller'  => $native && $plugin ? 'both' : ( $native ? 'native' : ( $plugin ? 'plugin' : 'other' ) ),
		'depth'   => max( 0, $depth ),
		'classes' => array_merge( $native, $plugin ),
	);
}

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( wp_installing() ) {
			return $preempt;
		}
		$headers = is_array( $args['headers'] ?? null ) ? array_change_key_case( $args['headers'] ) : array();
		add_option(
			'_e2e_outbound_' . str_replace( '.', '', uniqid( '', true ) ),
			wp_json_encode(
				array_merge(
					e2e_outbound_recorder_caller(),
					array(
						'url'        => strtok( (string) $url, '?' ),
						'user_agent' => (string) ( $headers['user-agent'] ?? ( $args['user-agent'] ?? '' ) ),
					)
				)
			),
			'',
			false
		);
		return $preempt;
	},
	PHP_INT_MIN,
	3
);

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		if ( false === strpos( (string) $url, '/wcpay/onboarding/fields_data' ) ) {
			return new WP_Error( 'e2e_outbound_blocked', 'Outbound HTTP is blocked by the E2E recorder.' );
		}
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'business_types' => array(
						array(
							'key'   => 'US',
							'name'  => 'United States (US)',
							'types' => array(
								array(
									'key'         => 'individual',
									'name'        => 'Individual',
									'description' => '',
									'structures'  => array(),
								),
							),
						),
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	PHP_INT_MAX,
	3
);
`;

interface OutboundRequest {
	caller: 'plugin' | 'native' | 'both' | 'other';
	depth: number;
	classes: string[];
	url: string;
	user_agent: string;
}

async function installRecorder(): Promise< void > {
	await wpEvalJson< true >(
		`file_put_contents( WPMU_PLUGIN_DIR . '/${ RECORDER_FILE }', base64_decode( getenv( 'E2E_RECORDER_PHP' ), true ) ) || throw new RuntimeException( 'Could not write the recorder.' ); return true;`,
		[
			`E2E_RECORDER_PHP=${ Buffer.from( RECORDER_PHP ).toString(
				'base64'
			) }`,
		]
	);
}

async function removeRecorder(): Promise< void > {
	await wpCLI( [ 'rm', '-f', `wp-content/mu-plugins/${ RECORDER_FILE }` ] );
}

/**
 * Starts a clean log and drops the plugin's cached fields, so the browser
 * step below makes the fields request again.
 */
async function resetRecorderLog(): Promise< void > {
	await wpEvalJson< true >( `
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\\\_e2e\\\\_outbound\\\\_%'" );
		delete_option( 'wcpay_onboarding_fields_data' );
		wp_cache_flush();
		return true;
	` );
}

async function readRecorderLog(): Promise< OutboundRequest[] > {
	return (
		await wpEvalJson< string[] >( `
			global $wpdb;
			return $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE '\\\\_e2e\\\\_outbound\\\\_%' ORDER BY option_id" );
		` )
	).map( ( row ) => JSON.parse( row ) as OutboundRequest );
}

test.describe( 'WooPayments transition: plugin-era onboarding', () => {
	test.describe.configure( { timeout: 5 * 60_000 } );

	test.beforeAll( async () => {
		process.env.E2E_WP_ENV_CONFIG ??=
			'tests/e2e/test-plugins/woopayments-transition-seed/wp-env.json';
		// Installed before the seed, so the seed's own account refresh is
		// answered locally too.
		await installRecorder();
		await wpCLI( [ 'wp', 'woopayments-e2e-transition', 'reset' ] );
		const identity = JSON.parse(
			(
				await wpCLI( [
					'wp',
					'woopayments-e2e-transition',
					'seed',
					'historical-tokens',
					'--version=11.1.0',
				] )
			).stdout
				.trim()
				.split( '\n' )
				.pop()!
		);
		expect( identity.plugin_version ).toBe( '11.1.0' );
		expect( identity.plugin_active ).toBe( true );
		expect( identity.is_live ).toBe( false );
		expect( identity.native_state ).toBe( 'available' );
		expect( identity.wpcom_blog_id ).toBeGreaterThan( 0 );
		expect( identity.blog_token_present ).toBe( true );
		expect( identity.user_token_present ).toBe( true );
	} );

	test.afterAll( async () => {
		try {
			await wpCLI( [ 'wp', 'woopayments-e2e-transition', 'reset' ] );
		} finally {
			await removeRecorder();
		}
	} );

	test(
		'NOX onboarding on a plugin-active store reads the KYC fields through the plugin, never the native client',
		{
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_TRANSITION ],
		},
		async ( { page, restApi } ) => {
			expect(
				( await restApi.get( 'wc-native-payments-e2e/v1/status' ) ).data
					.runtime_owner,
				'the plugin must own runtime on a store that has not switched'
			).toBe( 'plugin' );
			expect(
				await wpEvalJson< boolean >(
					'return wc_get_container()->get( Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\Api\\WooPaymentsApiClient::class )->is_available();'
				),
				'the native client must be available, or this case could not catch a native call'
			).toBe( true );

			await page.goto( 'wp-login.php' );
			await logIn( page, admin.username, admin.password, false );
			await resetRecorderLog();

			// WooCommerce > Settings > Payments. In dev mode the row offers no
			// onboarding button, so the case follows the provider's own
			// `onboarding._links.onboard` link, the URL "Complete setup" uses.
			const providersResponse = page.waitForResponse(
				( response ) =>
					response
						.url()
						.includes( '/wc-admin/settings/payments/providers' ) &&
					response.request().method() === 'POST'
			);
			await page.goto(
				'wp-admin/admin.php?page=wc-settings&tab=checkout'
			);
			const providers = (
				( await ( await providersResponse ).json() ) as {
					providers: Array< {
						id: string;
						onboarding?: {
							_links?: { onboard?: { href?: string } };
						};
					} >;
				}
			 ).providers;
			const onboardHref =
				providers.find(
					( provider ) => provider.id === 'woocommerce_payments'
				)?.onboarding?._links?.onboard?.href ?? '';
			expect( onboardHref ).toContain( 'path=/woopayments/onboarding' );

			const onboardingResponse = page.waitForResponse(
				( response ) =>
					/\/wc-admin\/settings\/payments\/woopayments\/onboarding(\?.*)?$/.test(
						response.url()
					) && response.request().method() === 'POST'
			);
			await page.goto( onboardHref );
			const onboarding = ( await (
				await onboardingResponse
			).json() ) as {
				steps: Array< {
					id: string;
					context?: { fields?: { business_types?: unknown } };
					errors?: unknown[];
				} >;
			};
			await expect(
				page.getByRole( 'heading', {
					name: 'Start accepting real payments',
				} )
			).toBeVisible();

			const log = await readRecorderLog();
			await test.info().attach( 'outbound-requests', {
				body: JSON.stringify( log, null, 2 ),
				contentType: 'application/json',
			} );
			const fieldsRequests = log.filter( ( entry ) =>
				entry.url.includes( '/wcpay/onboarding/fields_data' )
			);
			expect(
				fieldsRequests.filter( ( entry ) => entry.caller !== 'plugin' ),
				'no fields_data request may come from the native client while the plugin is active'
			).toEqual( [] );
			const pluginRequests = fieldsRequests.filter(
				( entry ) => entry.depth === 0
			);
			expect(
				pluginRequests.length,
				'the onboarding load must request fields_data through the plugin'
			).toBeGreaterThan( 0 );
			for ( const entry of pluginRequests ) {
				expect( entry.url ).toBe(
					'https://public-api.wordpress.com/wpcom/v2/wcpay/onboarding/fields_data'
				);
				expect( entry.user_agent ).toMatch( /^WooCommerce Payments\// );
				expect( entry.classes ).toContain( 'WC_Payments_API_Client' );
			}

			const businessVerification = onboarding.steps.find(
				( step ) => step.id === 'business_verification'
			);
			expect( businessVerification?.errors ?? [] ).toEqual( [] );
			expect(
				businessVerification?.context?.fields?.business_types,
				'the step must carry the fields the plugin route returned'
			).toEqual( [
				{
					key: 'US',
					name: 'United States (US)',
					types: [
						{
							key: 'individual',
							name: 'Individual',
							description: '',
							structures: [],
						},
					],
				},
			] );
		}
	);
} );
