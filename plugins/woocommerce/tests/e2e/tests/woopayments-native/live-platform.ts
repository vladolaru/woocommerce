/**
 * Live platform helper for the platform-events and in-person families.
 *
 * These cases run the platform's own code on the local WPCOM (`wpcom-local wp -- eval`, services from the platform's DI
 * container), act on the store's connected account through the Stripe CLI logged in to the local platform's test
 * account, and read the store through `restApi` and `wpEvalJson`. Every delivery they wait for is a real one: nothing
 * here posts to the store's webhook route. They skip unless the local platform is available and delivering.
 */

/**
 * External dependencies
 */
import { execFile, spawnSync } from 'node:child_process';
import { homedir } from 'node:os';
import { join, resolve } from 'node:path';
import { promisify, stripVTControlCharacters } from 'node:util';
import type { Page } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';
import { fillBillingCheckoutBlocks } from '@woocommerce/e2e-utils-playwright';

/**
 * Internal dependencies
 */
import { expect } from '../../fixtures/fixtures';
import { wpEvalJson } from '../../utils/cli';
import { random } from '../../utils/helpers';
import { hasStripeTestKey } from '../../utils/stripe-test-clock';
import {
	expectSettledCardPayment,
	fillCardDetails,
	requireTestModeAccount,
	type ProviderTestCard,
	type SettledCardPayment,
} from '../../utils/woopayments';

const execFileAsync = promisify( execFile );

const ACCOUNTS_ROUTE = 'wc/v3/payments/accounts';
const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';
const PLATFORM_RESULT_MARKER = '__E2E_PLATFORM_RESULT__';
const POLL_INTERVAL_MS = 3_000;
const CHECKOUT_BUDGET_MS = 90_000;
/** The local platform's WooPay blog; no case may act on it. */
const WOOPAY_BLOG_ID = 100000000;

export type Json = Record< string, unknown >;

/** Why a case skips where the live platform is not available. */
export const LIVE_PLATFORM_SKIP_REASON =
	'Needs the local WPCOM in its default state home with transact listen delivering, its Stripe test key, and the Stripe CLI logged in to its test account; local only.';

/** Whether the default wpcom-local state home is the one in use; a test home is refused. */
function isDefaultStateHome(): boolean {
	const home = process.env.WPCOM_LOCAL_HOME;
	return (
		! home ||
		resolve( home ) === resolve( join( homedir(), '.wpcom-local' ) )
	);
}

function runJson( command: string, args: string[] ): Json | null {
	const result = spawnSync( command, args, {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'ignore' ],
	} );
	if ( result.status !== 0 ) {
		return null;
	}
	try {
		return JSON.parse( result.stdout ) as Json;
	} catch {
		return null;
	}
}

/**
 * Whether the live platform is available for `test.skip()`: the tools are on this machine, the local platform's
 * Stripe test key is present (the Stripe Billing cases' gate), the state home is the default one, and transact listen
 * is delivering with the async jobs running.
 */
export function hasLivePlatformTools(): boolean {
	if (
		! isDefaultStateHome() ||
		! hasStripeTestKey() ||
		[ 'wpcom-local', 'stripe' ].some(
			( tool ) =>
				spawnSync( tool, [ 'version' ], { stdio: 'ignore' } ).status !==
				0
		)
	) {
		return false;
	}
	const context = ( runJson( 'wpcom-local', [
		'--json',
		'transact',
		'status',
	] )?.context ?? {} ) as Json;

	return (
		context.listener_process_state === 'alive' &&
		String( context.async_jobs_ready ) === 'true'
	);
}

let storeBlogId: number | undefined;

/**
 * The store's own blog on the local platform, from `wpcom-local wcpay status` for the store URL under test. The WooPay
 * blog is refused.
 */
function readStoreBlogId(): number {
	if ( storeBlogId !== undefined ) {
		return storeBlogId;
	}
	const storeUrl =
		process.env.E2E_WOOPAYMENTS_NATIVE_STORE_URL ?? process.env.BASE_URL;
	if ( ! storeUrl ) {
		throw new Error(
			'No store URL (E2E_WOOPAYMENTS_NATIVE_STORE_URL or BASE_URL); refusing to drive the platform.'
		);
	}
	const blogId = Number(
		(
			runJson( 'wpcom-local', [
				'--json',
				'wcpay',
				'status',
				'--store-url',
				storeUrl,
			] )?.context as Json | undefined
		 )?.connected_blog_id
	);
	if ( ! Number.isInteger( blogId ) || blogId <= 0 ) {
		throw new Error(
			`wpcom-local wcpay status reports no connected blog for ${ storeUrl }; refusing to drive the platform.`
		);
	}
	if ( blogId === WOOPAY_BLOG_ID ) {
		throw new Error(
			'The store resolves to the local WooPay blog; refusing to drive the platform.'
		);
	}
	storeBlogId = blogId;

	return blogId;
}

/** The store's connected account, after the store's test-mode guard passes. */
export async function connectedAccountId(
	restApi: ApiClient
): Promise< string > {
	await requireTestModeAccount( restApi );
	const accountId = ( ( await restApi.get( ACCOUNTS_ROUTE ) ).data as Json )
		.account_id;
	if ( typeof accountId !== 'string' || ! accountId.startsWith( 'acct_' ) ) {
		throw new Error(
			'The store reports no connected account id; refusing to drive the platform.'
		);
	}

	return accountId;
}

export async function delay( milliseconds: number ): Promise< void > {
	await new Promise( ( done ) => setTimeout( done, milliseconds ) );
}

/**
 * Polls `read` until it returns a value, or fails with `message` at the deadline. A read that throws is retried
 * until the deadline too.
 */
export async function pollFor< T >(
	read: () => Promise< T | null | undefined >,
	budgetMs: number,
	message: string
): Promise< T > {
	const deadline = Date.now() + budgetMs;
	let lastError: unknown;
	for (;;) {
		try {
			const value = await read();
			if ( value !== null && value !== undefined ) {
				return value;
			}
		} catch ( error ) {
			lastError = error;
		}
		if ( Date.now() >= deadline ) {
			throw new Error(
				`${ message }${
					lastError instanceof Error
						? ` Last error: ${ lastError.message }`
						: ''
				}`
			);
		}
		await delay( POLL_INTERVAL_MS );
	}
}

/**
 * Runs every cleanup step even when an earlier one fails, then fails once with every step that did.
 */
export async function cleanUp(
	steps: Array< [ string, () => Promise< unknown > ] >
): Promise< void > {
	const failures: string[] = [];
	for ( const [ what, step ] of steps ) {
		try {
			await step();
		} catch ( error ) {
			failures.push(
				`${ what }: ${ error instanceof Error ? error.message : error }`
			);
		}
	}
	if ( failures.length > 0 ) {
		throw new Error( `Cleanup failed: ${ failures.join( '; ' ) }` );
	}
}

/**
 * One Stripe CLI call on the connected account (or on the platform account with `onPlatform`, for calls about the
 * connected account itself), parsed. Test mode only; `STRIPE_API_KEY` is removed so the CLI uses its logged-in
 * profile.
 */
export async function stripeTest(
	accountId: string,
	args: string[],
	{ onPlatform = false }: { onPlatform?: boolean } = {}
): Promise< Json > {
	if (
		args.some(
			( arg ) => arg === '--live' || arg.startsWith( '--api-key' )
		)
	) {
		throw new Error( 'Refusing a Stripe CLI call outside test mode.' );
	}
	const env = { ...process.env };
	delete env.STRIPE_API_KEY;
	const { stdout } = await execFileAsync(
		'stripe',
		onPlatform ? args : [ ...args, '--stripe-account', accountId ],
		{ env, maxBuffer: 20 * 1024 * 1024 }
	);
	const data = JSON.parse( stdout ) as Json & {
		error?: { message?: string };
	};
	if ( data.error ) {
		throw new Error(
			`Stripe ${ args.slice( 0, 3 ).join( ' ' ) } failed: ${
				data.error.message ?? 'no message'
			}`
		);
	}

	return data;
}

/** The Stripe events of `type` on the connected account created at or after `since` (Unix seconds). */
export async function listStripeEvents(
	accountId: string,
	type: string,
	since: number
): Promise< Array< { id: string; object: Json } > > {
	const list = await stripeTest( accountId, [
		'events',
		'list',
		'--type',
		type,
		'--limit',
		'20',
		'-d',
		`created[gte]=${ since }`,
	] );
	return ( ( list.data as Json[] ) ?? [] ).map( ( entry ) => ( {
		id: String( entry.id ),
		object: ( ( entry.data as Json )?.object as Json ) ?? {},
	} ) );
}

/** Waits for the Stripe event of `type` about the object `matches` picks. */
export async function waitForStripeEvent(
	accountId: string,
	type: string,
	since: number,
	matches: ( object: Json ) => boolean,
	budgetMs: number
): Promise< { id: string; object: Json } > {
	return pollFor(
		async () =>
			( await listStripeEvents( accountId, type, since ) ).find(
				( event ) => matches( event.object )
			),
		budgetMs,
		`No ${ type } event reached the connected account within ${ budgetMs } ms.`
	);
}

/**
 * Waits until the store finished processing a delivered event: the ingestor writes its processed-event marker
 * (`wcpay_processed_event_` + md5 of the id, one hour) only after a handled delivery. Nothing is delivered here.
 */
export async function waitForProcessedDelivery(
	eventId: string,
	budgetMs: number
): Promise< void > {
	await pollFor(
		async () =>
			( await wpEvalJson< boolean >(
				`return false !== get_transient( 'wcpay_processed_event_' . md5( ${ JSON.stringify(
					eventId
				) } ) );`
			) ) || null,
		budgetMs,
		`The store has not processed event ${ eventId } within ${ budgetMs } ms: no real delivery of it arrived (is wpcom-local transact listen running with --ensure-jobs?), or the store refused it (read the store's WooPayments log for "Failed processing event ${ eventId }"). Nothing is replayed.`
	);
}

const PLATFORM_PRELUDE = `
if ( ! class_exists( '\\\\WCPay\\\\DI_Config' ) ) {
	require_once WP_CONTENT_DIR . '/rest-api-plugins/endpoints/wcpay/lib.php';
	require_once WP_CONTENT_DIR . '/lib/transact/lib.php';
}
\\WCPay\\DI_Config::setup_wcpay_api();
$e2e_service = static function ( string $class_name ) {
	return \\Transact\\Lib\\Shared\\Utils\\DI::get( $class_name );
};
$e2e_account = $e2e_service( \\WCPay\\Service\\Account_Service::class )->get_account_record_by_stripe_id( (string) $e2e_args['account'] );
if ( null === $e2e_account || ! $e2e_account->is_test ) {
	throw new RuntimeException( 'The store account is not a local platform test account; refusing to drive a platform sender.' );
}
if ( ${ WOOPAY_BLOG_ID } === (int) $e2e_args['blog'] || (int) $e2e_account->wpcom_blog_id !== (int) $e2e_args['blog'] ) {
	throw new RuntimeException( 'The account does not belong to the store blog under test; refusing to drive a platform sender.' );
}
`;

/**
 * Runs `phpBody` inside the local WPCOM, through platform code only, and returns its JSON-safe return value. The
 * body sees `$e2e_service( Class::class )` (the platform's DI container), `$e2e_account` (the store's platform
 * account record, which must belong to the store's own blog) and `$e2e_args` (`args` plus `account` and `blog`).
 * Transported base64-encoded, as `wpEvalJson` does; nothing is written into any container.
 */
export async function runOnPlatform< Result >(
	accountId: string,
	phpBody: string,
	args: Json = {}
): Promise< Result > {
	if ( ! isDefaultStateHome() ) {
		throw new Error(
			'WPCOM_LOCAL_HOME points at a non-default state home; refusing to drive the platform.'
		);
	}
	const encodedArgs = Buffer.from(
		JSON.stringify( {
			...args,
			account: accountId,
			blog: readStoreBlogId(),
		} ),
		'utf8'
	).toString( 'base64' );
	const source = `$e2e_args = json_decode( base64_decode( '${ encodedArgs }', true ), true );\n${ PLATFORM_PRELUDE }\n$e2e_result = ( static function () use ( $e2e_service, $e2e_account, $e2e_args ) {\n${ phpBody }\n} )();\necho "\\n${ PLATFORM_RESULT_MARKER }" . wp_json_encode( $e2e_result ) . "\\n";`;
	const encodedSource = Buffer.from( source, 'utf8' ).toString( 'base64' );
	const { stdout } = await execFileAsync(
		'wpcom-local',
		[
			'wp',
			'--',
			'eval',
			`eval( base64_decode( '${ encodedSource }', true ) );`,
		],
		{ maxBuffer: 20 * 1024 * 1024 }
	);
	const resultLine = stdout
		.split( /\r?\n/ )
		.map( ( line ) => stripVTControlCharacters( line ).trim() )
		.toReversed()
		.find( ( line ) => line.startsWith( PLATFORM_RESULT_MARKER ) );
	if ( ! resultLine ) {
		throw new Error( 'The platform call printed no result marker.' );
	}

	return JSON.parse(
		resultLine.slice( PLATFORM_RESULT_MARKER.length )
	) as Result;
}

/**
 * The store-side effects of an account event delivery that a case can watch (client 11.1.0 refreshes the account and
 * clears every cached saved-method list): a run-owned scratch user with a `_wcpay_payment_methods` cache row and a
 * run-owned `wcpay_pm_<probe>` option stand for the cached lists.
 */
export interface AccountRefreshProbe {
	userId: number;
	option: string;
	fetchedBefore: number;
}

export interface AccountRefreshState {
	fetched: number;
	meta: boolean;
	option: boolean;
}

export async function seedAccountRefreshProbe(): Promise< AccountRefreshProbe > {
	const option = `wcpay_pm_e2e_${ random() }`.replace( /[^a-z0-9_]/gi, '_' );
	return wpEvalJson< AccountRefreshProbe >( `
		$login = ${ JSON.stringify( option ) };
		$user_id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.com', 'role' => 'customer' ) );
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( $user_id->get_error_message() );
		}
		try {
			if ( ! update_user_meta( $user_id, '_wcpay_payment_methods', array( 'cus_e2e_probe' => array() ) ) || ! update_option( $login, array( 'data' => array() ), false ) ) {
				throw new RuntimeException( 'Could not seed the saved-method cache probe.' );
			}
		} catch ( Throwable $error ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
			delete_option( $login );
			throw $error;
		}
		$account = get_option( 'wcpay_account_data', array() );
		return array( 'userId' => $user_id, 'option' => $login, 'fetchedBefore' => (int) ( is_array( $account ) ? ( $account['fetched'] ?? 0 ) : 0 ) );
	` );
}

/** One read of the probe: the account cache's fetch time and whether each probe cache is still there. */
export async function readAccountRefreshState(
	probe: AccountRefreshProbe
): Promise< AccountRefreshState > {
	return wpEvalJson< AccountRefreshState >( `
		$account = get_option( 'wcpay_account_data', array() );
		return array(
			'fetched' => (int) ( is_array( $account ) ? ( $account['fetched'] ?? 0 ) : 0 ),
			'meta' => '' !== get_user_meta( ${
				probe.userId
			}, '_wcpay_payment_methods', true ),
			'option' => false !== get_option( ${ JSON.stringify( probe.option ) }, false ),
		);
	` );
}

/** Whether a probe read shows the account refreshed after the probe and both probe caches cleared. */
export function isRefreshed(
	probe: AccountRefreshProbe,
	state: AccountRefreshState
): boolean {
	return (
		state.fetched > probe.fetchedBefore && ! state.meta && ! state.option
	);
}

/** Waits until the account cache was fetched again after the probe was seeded and both probe caches are gone. */
export async function waitForAccountRefresh(
	probe: AccountRefreshProbe,
	budgetMs: number,
	what: string
): Promise< AccountRefreshState > {
	return pollFor(
		async () => {
			const state = await readAccountRefreshState( probe );
			return isRefreshed( probe, state ) ? state : null;
		},
		budgetMs,
		`${ what } did not refresh the store's account cache and clear its cached saved methods within ${ budgetMs } ms (no real delivery arrived, or the store refused it; read the store's WooPayments log).`
	);
}

export async function removeAccountRefreshProbe(
	probe: AccountRefreshProbe | undefined
): Promise< void > {
	if ( ! probe ) {
		return;
	}
	await wpEvalJson< unknown >( `
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( ${ probe.userId } );
		delete_option( ${ JSON.stringify( probe.option ) } );
		return true;
	` );
}

/** A guest Blocks checkout of one run-owned USD product, settled at the provider; `onOrderReceived` runs as soon as the order exists. */
export async function payWithBlocksCheckout(
	page: Page,
	restApi: ApiClient,
	card: ProviderTestCard,
	price: string,
	created: ( productId: number ) => void,
	onOrderReceived?: ( orderId: number ) => Promise< void >
): Promise< SettledCardPayment > {
	const productId = (
		(
			await restApi.post( PRODUCTS_ROUTE, {
				name: `WooPayments platform events ${ random() }`,
				type: 'simple',
				virtual: true,
				regular_price: price,
				status: 'publish',
			} )
		).data as { id: number }
	 ).id;
	created( productId );

	await page.goto( `?post_type=product&p=${ productId }` );
	await page
		.getByRole( 'button', { name: 'Add to cart', exact: true } )
		.click();
	await page.goto( 'checkout/' );
	await page
		.getByRole( 'textbox', { name: 'Email address' } )
		.fill( `woopayments-${ random() }-platform@example.com` );
	await fillBillingCheckoutBlocks( page, {
		country: 'US',
		firstName: 'E2E',
		lastName: 'WooPayments',
		address: '123 Test Street',
		city: 'San Francisco',
		state: 'CA',
		zip: '94107',
		phone: '5555550100',
	} );
	await page
		.getByRole( 'group', { name: 'Payment options' } )
		.getByRole( 'radio', { name: /Card/i } )
		.check();
	await fillCardDetails( page, card, 'blocks' );
	await page.getByRole( 'button', { name: /place order/i } ).press( 'Enter' );
	await page.waitForURL( /\/order-received\/[1-9]\d*/, {
		timeout: CHECKOUT_BUDGET_MS,
	} );
	const orderId = Number( /order-received\/(\d+)/.exec( page.url() )?.[ 1 ] );
	expect( orderId, 'the checkout must land on an order' ).toBeGreaterThan(
		0
	);
	// Before the settled-graph wait, so a caller can read the order before the provider's deliveries land.
	await onOrderReceived?.( orderId );

	return expectSettledCardPayment( restApi, orderId, {
		amountMinor: Math.round( Number( price ) * 100 ),
		currency: 'USD',
	} );
}

export async function deleteOrder(
	restApi: ApiClient,
	orderId: number | undefined
): Promise< void > {
	if ( orderId ) {
		await restApi.delete( `${ ORDERS_ROUTE }/${ orderId }`, {
			force: true,
		} );
	}
}

export async function deleteProduct(
	restApi: ApiClient,
	productId: number | undefined
): Promise< void > {
	if ( productId ) {
		await restApi.delete( `${ PRODUCTS_ROUTE }/${ productId }`, {
			force: true,
		} );
	}
}

/** One admin note by name, as the store stored it, or null. */
export async function readAdminNote( name: string ): Promise< Json | null > {
	return wpEvalJson< Json | null >( `
		$note = \\Automattic\\WooCommerce\\Admin\\Notes\\Notes::get_note_by_name( ${ JSON.stringify(
			name
		) } );
		if ( ! $note ) {
			return null;
		}
		return array(
			'title' => $note->get_title(),
			'content' => $note->get_content(),
			'source' => $note->get_source(),
			'actions' => array_map( static fn( $action ) => array( 'label' => $action->label, 'url' => $action->query, 'status' => $action->status, 'primary' => (bool) $action->primary ), $note->get_actions() ),
		);
	` );
}

/** Deletes every admin note with this name, so a run starts and ends without it. */
export async function deleteAdminNotes( name: string ): Promise< void > {
	await wpEvalJson< unknown >( `
		$data_store = \\Automattic\\WooCommerce\\Admin\\Notes\\Notes::load_data_store();
		foreach ( $data_store->get_notes_with_name( ${ JSON.stringify(
			name
		) } ) as $note_id ) {
			$note = \\Automattic\\WooCommerce\\Admin\\Notes\\Notes::get_note( $note_id );
			if ( $note ) {
				$data_store->delete( $note );
			}
		}
		return true;
	` );
}
