import type { ApiClient } from '@woocommerce/e2e-utils-playwright';
import { fillBillingCheckoutBlocks } from '@woocommerce/e2e-utils-playwright';
import type { Page } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { wpCLI, wpEvalJson } from '../../../utils/woopayments-cli';
import { admin } from '../../../test-data/data';
import { logIn } from '../../../utils/login';
import { random } from '../../../utils/helpers';
import {
	EXTENSION_ORDER_CHECK_HOOK,
	expectSettledCardPayment,
	fillCardDetails,
	getPaymentIntent,
	requireTestModeAccount,
	TEST_CARDS,
} from '../../../utils/woopayments';

/**
 * The transition `cutover-reconciliation` row (T.4 Batch T rewrite, D6): a
 * one-click "Start the switch" on a real plugin-era store, run against a
 * seeded profile of the WooPayments transition-seed plugin instead of the
 * retired provisioner. The seed borrows this checkout's own connected
 * account (`:8889`), so the payment below settles against a real
 * provider account in test mode.
 */

const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const CURRENCY = 'USD';
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
const CARD = { brand: 'visa', last4: '4242' };
const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';

interface CutoverRecord {
	state: string;
	generation: number;
	revision: number;
	current_step: string;
	deferred_codes?: string[];
	step_log?: Array< {
		step: string;
		at: number;
		context?: { codes?: string[] };
	} >;
}

interface CutoverStatus {
	record: CutoverRecord | null;
	plugin_active: boolean;
	network_active: boolean;
	plugin_version: string;
	preflight_failures: string[];
	migrator_action: { status: string } | null;
	native_state: string | null;
}

const CUTOVER_STATUS_PHP = String.raw`
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin = 'woocommerce-payments/woocommerce-payments.php';
$headers = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
$preflight = wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\PreflightService::class );
$migrator_id = (int) getenv( 'E2E_MIGRATOR_ACTION_ID' );
$action = $migrator_id > 0 ? ActionScheduler::store()->fetch_action( $migrator_id ) : null;
return array(
	'record' => get_option( 'woocommerce_woopayments_cutover_state', null ),
	'plugin_active' => is_plugin_active( $plugin ),
	'network_active' => is_plugin_active_for_network( $plugin ),
	'plugin_version' => $headers['Version'],
	'preflight_failures' => $preflight->get_failures(),
	'migrator_action' => $action ? array( 'status' => ActionScheduler::store()->get_status( $migrator_id ) ) : null,
	'native_state' => wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier::class )->get_effective_tier(),
);
`;

async function readCutoverStatus(
	migratorActionId: number
): Promise< CutoverStatus > {
	return wpEvalJson< CutoverStatus >( CUTOVER_STATUS_PHP, [
		`E2E_MIGRATOR_ACTION_ID=${ migratorActionId }`,
	] );
}

async function readRuntimeOwner( restApi: ApiClient ): Promise< string > {
	return String(
		( await restApi.get( 'wc-native-payments-e2e/v1/status' ) ).data
			.runtime_owner
	);
}

/**
 * Validates that the cutover link is the product's nonce-bound controller
 * entry point, the property the retired harness's
 * `validateCutoverActionURL` protected.
 */
function validateCutoverActionURL( href: string, baseURL: string ): void {
	const expected = new URL( 'wp-admin/admin.php', baseURL );
	const url = new URL( href, baseURL );
	const valid =
		url.origin === expected.origin &&
		url.pathname === expected.pathname &&
		! url.username &&
		! url.password &&
		! url.hash &&
		[ ...url.searchParams.keys() ].toSorted().join( ',' ) ===
			'_wc_woopayments_cutover_nonce,wc_woopayments_cutover_action' &&
		url.searchParams.get( 'wc_woopayments_cutover_action' ) === 'start' &&
		/^[a-zA-Z0-9]{10}$/.test(
			url.searchParams.get( '_wc_woopayments_cutover_nonce' ) ?? ''
		);
	if ( ! valid ) {
		throw new Error(
			'The product WooPayments cutover action is not bound to the nonce-protected controller entry point.'
		);
	}
}

/**
 * Reads the registered gateway ids through a REST request, where native
 * registers its gateway once the store is `active` (WP-CLI never does).
 */
async function readGatewayIds( restApi: ApiClient ): Promise< string[] > {
	return (
		( await restApi.get( 'wc/v3/payment_gateways' ) ).data as Array< {
			id: string;
		} >
	 ).map( ( gateway ) => gateway.id );
}

/**
 * Asserts the cutover left native payments `active` with its gateway
 * registered, the state the cutover job writes once the plugin is off.
 */
async function expectNativeGatewayActive(
	restApi: ApiClient,
	status: CutoverStatus
): Promise< void > {
	expect(
		status.native_state,
		'the cutover must end with native payments active'
	).toBe( 'active' );
	expect(
		await readGatewayIds( restApi ),
		'the native gateway must be registered once the plugin is off'
	).toContain( 'woocommerce_payments' );
}

async function createRunProduct(
	restApi: ApiClient
): Promise< { id: number } > {
	return (
		await restApi.post( PRODUCTS_ROUTE, {
			name: `WooPayments cutover ${ random() }`,
			type: 'simple',
			virtual: true,
			regular_price: PRICE,
			status: 'publish',
		} )
	).data as { id: number };
}

async function readHighestOrderId( restApi: ApiClient ): Promise< number > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=1&status=any`
		)
	).data as Array< { id: number } >;
	return orders[ 0 ]?.id ?? 0;
}

async function readNewOrders(
	restApi: ApiClient,
	baselineOrderId: number
): Promise< Array< { id: number; status: string } > > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=20&status=any`
		)
	).data as Array< { id: number; status: string } >;
	return orders.filter( ( order ) => order.id > baselineOrderId );
}

function orderMeta( order: Record< string, unknown >, key: string ): string {
	const entries = Array.isArray( order.meta_data )
		? ( order.meta_data as Array< { key?: unknown; value?: unknown } > )
		: [];
	const value = entries.find( ( entry ) => entry.key === key )?.value;
	return typeof value === 'string' ? value : '';
}

let migratorActionId: number;

test.describe( 'WooPayments transition: cutover reconciliation', () => {
	test.describe.configure( { timeout: 25 * 60_000 } );

	test.beforeAll( async () => {
		// Falls back to this profile's own wp-env config only when the run
		// command did not already set one, so a differently-configured
		// checkout is never overridden.
		process.env.E2E_WP_ENV_CONFIG ??=
			'tests/e2e/test-plugins/woopayments-transition-seed/wp-env.json';
		await wpCLI( [ 'wp', 'woopayments-e2e-transition', 'reset' ] );
		const identity = JSON.parse(
			(
				await wpCLI( [
					'wp',
					'woopayments-e2e-transition',
					'seed',
					'cutover',
					'--pending-migrator',
				] )
			).stdout
				.trim()
				.split( '\n' )
				.pop()!
		);
		expect( identity.plugin_version ).toBe( '10.5.0' );
		expect( identity.plugin_active ).toBe( true );
		expect( identity.blog_token_present ).toBe( true );
		expect( identity.user_token_present ).toBe( true );
		expect( identity.is_live ).toBe( false );
		// The extension owns payments, so the effective tier is `available`.
		expect( identity.native_state ).toBe( 'available' );
		expect( identity.migrator_action_id ).toBeGreaterThan( 0 );
		migratorActionId = identity.migrator_action_id;
	} );

	test.afterAll( async () => {
		await wpCLI( [ 'wp', 'woopayments-e2e-transition', 'reset' ] );
	} );

	test(
		'one click proves the allocated cutover reconciliation profile contract',
		{
			tag: [
				tags.WOOPAYMENTS_NATIVE,
				tags.WOOPAYMENTS_PROVIDER,
				tags.WOOPAYMENTS_TRANSITION,
			],
		},
		async ( { page, restApi, baseURL } ) => {
			await requireTestModeAccount( restApi );
			expect(
				await readRuntimeOwner( restApi ),
				'the plugin must own runtime before its own cutover writes'
			).toBe( 'extension' );

			const before = await readCutoverStatus( migratorActionId );
			expect( before.plugin_version ).toBe( '10.5.0' );
			expect( before.plugin_active ).toBe( true );
			expect( before.migrator_action?.status ).toBe( 'pending' );
			expect(
				before.native_state,
				'the switch notice loads only from the available tier up'
			).toBe( 'available' );

			await page.goto( 'wp-login.php' );
			await logIn( page, admin.username, admin.password, false );
			await page.goto( 'wp-admin/' );
			const link = page.getByRole( 'link', {
				name: 'Start the switch',
				exact: true,
			} );
			await expect( link ).toBeVisible();
			const href = await link.getAttribute( 'href' );
			if ( ! href ) {
				throw new Error(
					'The product WooPayments cutover action has no exact URL.'
				);
			}
			validateCutoverActionURL( href, baseURL! );

			await link.click( { timeout: 30_000 } );
			// Below the 10.6.0 floor, the start updates the extension first, and
			// the site answers 503 while the update runs (WordPress maintenance
			// mode), so wait for the updated files before reading the notice.
			await expect
				.poll(
					async () => {
						const response = await page.request.get( 'wp-admin/' );
						if ( response.status() !== 200 ) {
							return false;
						}
						const { plugin_version: version } =
							await readCutoverStatus( migratorActionId );
						return (
							version.localeCompare( '10.6.0', undefined, {
								numeric: true,
							} ) >= 0
						);
					},
					{ timeout: 60_000, intervals: [ 1000, 2000, 5000 ] }
				)
				.toBe( true );
			await page.goto( 'wp-admin/' );
			await expect(
				page.getByText( 'Switch in progress', { exact: true } )
			).toBeVisible();

			const started = await readCutoverStatus( migratorActionId );
			expect( started.record ).not.toBeNull();
			expect( started.record!.current_step ).not.toBe(
				'awaiting_merchant_start'
			);

			let completed = started;
			await expect
				.poll(
					async () => {
						completed = await readCutoverStatus( migratorActionId );
						return completed.record?.state === 'done';
					},
					{ timeout: 20 * 60_000, intervals: [ 1000, 5000, 15000 ] }
				)
				.toBe( true );
			expect( completed.record!.generation ).toBe(
				started.record!.generation
			);
			expect( completed.record!.revision ).toBeGreaterThan(
				started.record!.revision
			);
			expect( completed.plugin_active ).toBe( false );
			expect( completed.network_active ).toBe( false );
			expect(
				completed.migrator_action?.status,
				'the switch adopts the migration off Stripe Billing, so its retry stays queued for the built-in migrator'
			).toBe( 'pending' );
			await expect
				.poll( () => readRuntimeOwner( restApi ), { timeout: 60_000 } )
				.toBe( 'builtin' );
			await expectNativeGatewayActive( restApi, completed );

			await page.goto( 'wp-admin/plugins.php' );
			await expect(
				page.getByText( 'Switch in progress', { exact: true } )
			).toHaveCount( 0 );

			// The payment below proves a fresh guest checkout on the exact
			// contract the harness cited: the client's basic-card guest
			// checkout, `shopper-checkout-purchase.spec.ts:53`.
			await page.context().clearCookies();
			const baselineOrderId = await readHighestOrderId( restApi );
			// No cleanup call here: `afterAll` already runs a full `db reset`,
			// which removes this product along with everything else, so a
			// REST delete here would only risk masking the case's own error.
			const product = await createRunProduct( restApi );
			{
				await page.goto( `?post_type=product&p=${ product.id }` );
				await page
					.getByRole( 'button', { name: 'Add to cart', exact: true } )
					.click();
				await page.goto( 'checkout/' );
				await page
					.getByRole( 'textbox', { name: 'Email address' } )
					.fill( `woopayments-${ random() }@example.com` );
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
				await fillCardDetails( page, TEST_CARDS.basic, 'blocks' );
				await page
					.getByRole( 'button', { name: /place order/i } )
					.click();
				await page.waitForURL( /\/order-received\/[1-9]\d*/, {
					timeout: 60_000,
				} );
				const orderId = Number(
					/order-received\/(\d+)/.exec( page.url() )?.[ 1 ]
				);

				const payment = await expectSettledCardPayment(
					restApi,
					orderId,
					{
						amountMinor: AMOUNT_MINOR,
						currency: CURRENCY,
						card: CARD,
					}
				);
				const intent = await getPaymentIntent(
					restApi,
					payment.intentId
				);
				const intentMetadata =
					( intent.metadata as { order_id?: unknown } | undefined ) ??
					{};
				expect(
					String( intentMetadata.order_id ?? '' ),
					"the provider's own intent metadata must record this exact order"
				).toBe( String( orderId ) );
				expect( PAID_ORDER_STATUSES ).toContain( payment.orderStatus );

				const newOrders = await readNewOrders(
					restApi,
					baselineOrderId
				);
				expect(
					newOrders.map( ( order ) => order.id ),
					'the cutover payment must create exactly one order'
				).toEqual( [ orderId ] );
				expect(
					newOrders.filter( ( order ) =>
						PAID_ORDER_STATUSES.includes( order.status )
					)
				).toHaveLength( 1 );
				const orderRecord = (
					await restApi.get( `${ ORDERS_ROUTE }/${ orderId }` )
				).data as Record< string, unknown >;
				expect(
					orderMeta( orderRecord, '_intent_id' ),
					'the paid order must carry the exact settled provider intent'
				).toBe( payment.intentId );
			}
		}
	);
} );

const VERSION_BLOCKER = 'woopayments_plugin_version_unsupported';

/**
 * An environment accommodation for wp-env's cron, not a product finding.
 * `action_scheduler_run_queue` is Action Scheduler's own WP-Cron hook, not
 * an AJAX action; hitting `admin-ajax.php` works because that request is
 * `is_admin()`, and `ActionScheduler_QueueRunner` dispatches its async
 * request runner (`ActionScheduler_AsyncRequest_QueueRunner`'s
 * `async_request_queue_runner` action) on `shutdown` of any such request
 * (`ActionScheduler_QueueRunner.php:110, :138-141`). Passive WP-Cron alone
 * left the reconciliation job's queued action unclaimed for over 20 real
 * minutes on this store; an `is_admin()` request's own shutdown dispatch
 * resolved it in seconds, so the poll below drives one on every tick
 * instead of waiting on background cron timing.
 */
async function triggerActionSchedulerQueue( baseURL: string ): Promise< void > {
	await fetch(
		new URL(
			'wp-admin/admin-ajax.php?action=action_scheduler_run_queue',
			baseURL
		)
	).catch( () => undefined );
}

/**
 * Test-time shortcut, not a product finding: moves the next attempt of a
 * switch deferred before its hand-over to now, a direct DB time-shortcut
 * (the same category as WooCommerce Subscriptions never scheduling past
 * dates) instead of idling the run through the product's own real ~15-minute
 * backoff between attempts. It moves only the one action the deferral
 * scheduled, by the ID the record holds, and only while that action is still
 * pending, so an attempt a runner schedules meanwhile keeps its own time: the
 * ownership check, due 5 s after the hand-over, runs as the product
 * schedules it.
 */
async function rescheduleDeferredAttemptToNow(): Promise< void > {
	await wpEvalJson< number >( `
		global $wpdb;
		$record = get_option( 'woocommerce_woopayments_cutover_state' );
		if ( ! is_array( $record ) || 'deferred' !== ( $record['state'] ?? '' ) || null !== ( $record['request_origin_token'] ?? null ) ) {
			return 0;
		}
		$action_id = (int) ( $record['action_id'] ?? 0 );
		if ( $action_id < 1 ) {
			return 0;
		}
		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}actionscheduler_actions SET scheduled_date_gmt = %s, scheduled_date_local = %s WHERE action_id = %d AND hook = %s AND status = 'pending'",
			current_time( 'mysql', true ),
			current_time( 'mysql' ),
			$action_id,
			'woocommerce_woopayments_cutover_reconcile'
		) );
	` );
}

/**
 * Moves every pending reconciliation action to now, only while the record
 * waits for the merchant's start, the state a rollback leaves: then no
 * pending action is an attempt of a running switch, and any that runs would
 * claim the rolled-back generation. Returns false, moving nothing, in any
 * other state.
 */
async function forceObsoleteReconcileActionsDue(): Promise< boolean > {
	return wpEvalJson< boolean >( `
		global $wpdb;
		$record = get_option( 'woocommerce_woopayments_cutover_state' );
		if ( ! is_array( $record ) || 'pending' !== ( $record['state'] ?? '' ) || 'awaiting_merchant_start' !== ( $record['current_step'] ?? '' ) ) {
			return false;
		}
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}actionscheduler_actions SET scheduled_date_gmt = %s, scheduled_date_local = %s WHERE hook = %s AND status = 'pending'",
			current_time( 'mysql', true ),
			current_time( 'mysql' ),
			'woocommerce_woopayments_cutover_reconcile'
		) );
		return true;
	` );
}

const QUEUE_RUNNER_HOLD_HOOK = 'woocommerce_e2e_queue_runner_hold';

/**
 * Holds the store's queue runners off from the spec's side, through Action
 * Scheduler's own concurrency limit: a claim on a placeholder action fills
 * its one concurrent batch, so no WP-Cron, async request or admin-dispatched
 * runner claims anything (`ActionScheduler_QueueRunner::run()`), and the
 * async request chain the click started stops at its next dispatch
 * (`ActionScheduler_AsyncRequest_QueueRunner::allow()`). The live rows' hold
 * (`DISABLE_WP_CRON` and the async runner's lock) does not stop that chain.
 * The claim carries a fresh last-attempt time, so
 * `ActionScheduler_QueueCleaner::reset_timeouts()` leaves it for five
 * minutes; `refreshQueueRunnerHold()` renews that time. If a release is ever
 * missed, Action Scheduler frees the leaked claim after those five minutes.
 * A runner admitted before the hold keeps looping; `waitForIdleQueueRunners()`
 * waits it out. Pair with `releaseQueueRunners()` in a `finally`.
 */
async function holdQueueRunners(): Promise< void > {
	const held = await wpEvalJson< boolean >( `
		global $wpdb;
		$action_id = as_schedule_single_action( time() + DAY_IN_SECONDS, '${ QUEUE_RUNNER_HOLD_HOOK }', array(), 'woocommerce-e2e' );
		$wpdb->insert( $wpdb->actionscheduler_claims, array( 'date_created_gmt' => current_time( 'mysql', true ) ) );
		$wpdb->update(
			$wpdb->actionscheduler_actions,
			array(
				'claim_id'           => (int) $wpdb->insert_id,
				'last_attempt_gmt'   => current_time( 'mysql', true ),
				'last_attempt_local' => current_time( 'mysql' ),
			),
			array( 'action_id' => $action_id )
		);
		return ActionScheduler::runner()->has_maximum_concurrent_batches();
	` );
	expect( held, 'no queue runner may claim an action' ).toBe( true );
}

/**
 * Renews the hold's last-attempt time, so `reset_timeouts()` leaves the claim
 * five more minutes, and asserts the hold is still in place.
 */
async function refreshQueueRunnerHold(): Promise< void > {
	const held = await wpEvalJson< boolean >( `
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->actionscheduler_actions} SET last_attempt_gmt = %s, last_attempt_local = %s WHERE hook = %s AND claim_id > 0",
			current_time( 'mysql', true ),
			current_time( 'mysql' ),
			'${ QUEUE_RUNNER_HOLD_HOOK }'
		) );
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->actionscheduler_actions} WHERE hook = %s AND claim_id > 0", '${ QUEUE_RUNNER_HOLD_HOOK }' ) )
			&& ActionScheduler::runner()->has_maximum_concurrent_batches();
	` );
	expect( held, 'the hold still has its claim' ).toBe( true );
}

/**
 * Counts the live queue work the hold does not cover: claims other than the
 * hold's, and actions running. A finished action keeps its claim ID for good;
 * the claim's own row goes when its batch ends
 * (`ActionScheduler_DBStore::release_claim()`), so only the claims table
 * tells a live claim.
 */
async function countOtherQueueWork(): Promise< number > {
	return wpEvalJson< number >( `
		global $wpdb;
		$claims = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->actionscheduler_claims} WHERE claim_id NOT IN ( SELECT claim_id FROM {$wpdb->actionscheduler_actions} WHERE hook = %s AND claim_id > 0 )",
			'${ QUEUE_RUNNER_HOLD_HOOK }'
		) );
		$running = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->actionscheduler_actions} WHERE status = %s",
			ActionScheduler_Store::STATUS_RUNNING
		) );
		return $claims + $running;
	` );
}

/**
 * The barrier behind `holdQueueRunners()`. The hold stops new runners, but a
 * runner admitted before it checks the concurrency limit only once and keeps
 * claiming batches while they hold due actions
 * (`ActionScheduler_QueueRunner::run()`). Between batches it holds a claim
 * only for an instant, and a batch that finds nothing due ends it. So two
 * reads a second apart with no other claim and no running action mean no such
 * runner is left to claim the ownership check the held section schedules.
 */
async function waitForIdleQueueRunners(): Promise< void > {
	let idleReads = 0;
	await expect
		.poll(
			async () => {
				idleReads =
					( await countOtherQueueWork() ) === 0 ? idleReads + 1 : 0;
				return idleReads;
			},
			{ timeout: 60_000, intervals: [ 1000 ] }
		)
		.toBeGreaterThanOrEqual( 2 );
}

/**
 * Releases `holdQueueRunners()`: the placeholder actions and their claims go.
 * Safe to run when nothing is held.
 */
async function releaseQueueRunners(): Promise< void > {
	await wpEvalJson< true >( `
		global $wpdb;
		$claims = $wpdb->get_col( $wpdb->prepare( "SELECT claim_id FROM {$wpdb->actionscheduler_actions} WHERE hook = %s AND claim_id > 0", '${ QUEUE_RUNNER_HOLD_HOOK }' ) );
		foreach ( as_get_scheduled_actions( array( 'hook' => '${ QUEUE_RUNNER_HOLD_HOOK }', 'status' => '', 'per_page' => -1 ), 'ids' ) as $action_id ) {
			ActionScheduler::store()->delete_action( $action_id );
		}
		foreach ( $claims as $claim_id ) {
			$wpdb->delete( $wpdb->actionscheduler_claims, array( 'claim_id' => (int) $claim_id ) );
		}
		return true;
	` );
}

/**
 * Runs the deferred switch's next attempt in one WP-CLI process, by the
 * action ID the record holds. Returns the action ID, or 0 when the record is
 * not deferred or the action is no longer pending.
 */
async function runDeferredAttemptNow(): Promise< number > {
	return wpEvalJson< number >( `
		$record = get_option( 'woocommerce_woopayments_cutover_state' );
		$action_id = is_array( $record ) && 'deferred' === ( $record['state'] ?? '' ) ? (int) ( $record['action_id'] ?? 0 ) : 0;
		if ( $action_id < 1 || ActionScheduler_Store::STATUS_PENDING !== ActionScheduler::store()->get_status( $action_id ) ) {
			return 0;
		}
		ActionScheduler::runner()->process_action( $action_id, 'WP CLI' );
		return $action_id;
	` );
}

/**
 * Waits for WordPress's own maintenance-mode page to clear by reloading,
 * instead of sleeping a fixed span. The real `Plugin_Upgrader::bulk_upgrade()`
 * attempt (F-UPGRADEDEACT, b6edbf50550) holds the site in that mode for the
 * moment its install takes, and the click's own admin request can already
 * trigger it.
 */
async function waitPastMaintenanceMode( page: Page ): Promise< void > {
	await expect
		.poll(
			async () => {
				const notice = page.getByText(
					'Briefly unavailable for scheduled maintenance',
					{ exact: false }
				);
				if ( ( await notice.count() ) === 0 ) {
					return true;
				}
				await page.reload().catch( () => undefined );
				return false;
			},
			{ timeout: 30_000, intervals: [ 500, 1000, 2000 ] }
		)
		.toBe( true );
}

test.describe( 'WooPayments transition: old plugin cutover advancement', () => {
	test.describe.configure( { timeout: 5 * 60_000 } );

	test.beforeAll( async () => {
		process.env.E2E_WP_ENV_CONFIG ??=
			'tests/e2e/test-plugins/woopayments-transition-seed/wp-env.json';
		await wpCLI( [ 'wp', 'woopayments-e2e-transition', 'reset' ] );
		const identity = JSON.parse(
			(
				await wpCLI( [
					'wp',
					'woopayments-e2e-transition',
					'seed',
					'cutover',
					'--version=10.4.0',
				] )
			).stdout
				.trim()
				.split( '\n' )
				.pop()!
		);
		expect( identity.plugin_version ).toBe( '10.4.0' );
		expect( identity.plugin_active ).toBe( true );
		expect( identity.native_state ).toBe( 'available' );
	} );

	test.afterAll( async () => {
		await wpCLI( [ 'wp', 'woopayments-e2e-transition', 'reset' ] );
	} );

	/**
	 * The expected sequence for an old, unsupported plugin version (10.4.0)
	 * is the program's own design (plan.md Task 3.1) and the
	 * zero-merchant-action rule: after the one click, the plugin stays
	 * active and owning while its own real
	 * `Plugin_Upgrader::bulk_upgrade()` attempt upgrades it in place
	 * (F-UPGRADEDEACT, b6edbf50550), and everything after completes
	 * automatically through the product's own retry cycle with no further
	 * merchant action, ending with native owning the store and the plugin
	 * deactivated only at that all-clear step. This replaces the pre-merge
	 * driver's expectation that this same version would fail its upgrade
	 * and stay deferred with the plugin as owner (owner FYI).
	 */
	test(
		'an old, unsupported plugin version proves the real Plugin_Upgrader disposition',
		{
			tag: [
				tags.WOOPAYMENTS_NATIVE,
				tags.WOOPAYMENTS_PROVIDER,
				tags.WOOPAYMENTS_TRANSITION,
			],
		},
		async ( { page, restApi, baseURL } ) => {
			expect(
				await readRuntimeOwner( restApi ),
				'the plugin must own runtime before its own cutover writes'
			).toBe( 'extension' );

			const before = await readCutoverStatus( 0 );
			expect( before.plugin_version ).toBe( '10.4.0' );
			expect( before.plugin_active ).toBe( true );
			expect( before.preflight_failures ).toContain( VERSION_BLOCKER );
			expect( before.native_state ).toBe( 'available' );

			await page.goto( 'wp-login.php' );
			await logIn( page, admin.username, admin.password, false );
			await page.goto( 'wp-admin/' );
			const link = page.getByRole( 'link', {
				name: 'Start the switch',
				exact: true,
			} );
			await expect( link ).toBeVisible();
			const href = await link.getAttribute( 'href' );
			if ( ! href ) {
				throw new Error(
					'The product WooPayments cutover action has no exact URL.'
				);
			}
			validateCutoverActionURL( href, baseURL! );

			await link.click( { timeout: 30_000 } );
			await waitPastMaintenanceMode( page );

			// Step 1: the first attempt. Not asserted: record.deferred_codes,
			// a stale mid-attempt snapshot taken before this same attempt's
			// own upgrade result lands (F-UPGRADELOG, T.7). The status is read
			// only inside the poll's try: a WP-CLI process that loads the
			// extension while the attempt swaps its files fails and is retried.
			let owner = '';
			let status!: CutoverStatus;
			await expect
				.poll(
					async () => {
						await triggerActionSchedulerQueue( baseURL! );
						try {
							owner = await readRuntimeOwner( restApi );
							status = await readCutoverStatus( 0 );
						} catch {
							return null;
						}
						return status.record?.state ?? null;
					},
					{ timeout: 60_000, intervals: [ 1000, 3000, 5000 ] }
				)
				.toBe( 'deferred' );
			expect(
				owner,
				'the plugin must still own runtime after the first attempt'
			).toBe( 'extension' );
			expect(
				status.plugin_active,
				'the plugin must stay active through the first attempt'
			).toBe( true );
			expect( status.network_active ).toBe( false );
			expect(
				status.plugin_version.localeCompare( '10.5.0', 'en', {
					numeric: true,
				} ),
				"the plugin's own header version must already be at or above the supported floor"
			).toBeGreaterThanOrEqual( 0 );

			// Step 2: zero merchant action. The merchant's one click already
			// happened above; every later attempt is the product's own retry
			// cycle, driven here without waiting on its real ~15-minute
			// cadence (rescheduleDeferredAttemptToNow, a test-time shortcut,
			// not a product finding).
			await expect
				.poll(
					async () => {
						await rescheduleDeferredAttemptToNow();
						await triggerActionSchedulerQueue( baseURL! );
						try {
							owner = await readRuntimeOwner( restApi );
							status = await readCutoverStatus( 0 );
						} catch {
							return null;
						}
						return status.record?.state ?? null;
					},
					{ timeout: 3 * 60_000, intervals: [ 1000, 3000, 5000 ] }
				)
				.toBe( 'done' );

			// Step 3: the all-clear. The plugin is deactivated only here,
			// not during the deferred attempt above (asserted active there).
			expect( owner ).toBe( 'builtin' );
			expect(
				status.plugin_active,
				'the plugin must be deactivated only once the record is done'
			).toBe( false );
			expect( status.network_active ).toBe( false );
			expect( status.preflight_failures ).toEqual( [] );
			await expectNativeGatewayActive( restApi, status );
		}
	);
} );

/**
 * Plugin-era state the rollback case compares. Settings and split
 * per-method options are flattened into one path per leaf (lists stay whole),
 * so a key added inside an option reads as an added path and never hides a
 * changed sibling. Multi-currency caches (`*cached*`) are left out: both
 * runtimes refresh them from the platform, and they hold no merchant choice.
 */
const ROLLBACK_SNAPSHOT_PHP = String.raw`
global $wpdb;
$snapshot = array();
$flatten = static function ( string $path, $value ) use ( &$flatten, &$snapshot ): void {
	if ( is_array( $value ) && array() !== $value && array_values( $value ) !== $value ) {
		foreach ( $value as $key => $child ) {
			$flatten( $path . '.' . $key, $child );
		}
		return;
	}
	$snapshot[ $path ] = wp_json_encode( $value );
};
$option_names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name = 'woocommerce_woocommerce_payments_settings' OR option_name LIKE 'woocommerce\\_woocommerce\\_payments\\_%\\_settings' OR ( option_name LIKE 'wcpay\\_multi\\_currency\\_%' AND option_name NOT LIKE '%cached%' ) ORDER BY option_name" );
foreach ( $option_names as $option_name ) {
	$flatten( 'option:' . $option_name, get_option( $option_name ) );
}
$tokens = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE gateway_id LIKE 'woocommerce\\_payments%' ORDER BY token_id", ARRAY_A );
foreach ( $tokens as $row ) {
	foreach ( $row as $column => $value ) {
		$snapshot[ 'token:' . $row['token_id'] . '.' . $column ] = wp_json_encode( $value );
	}
	$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->prefix}woocommerce_payment_tokenmeta WHERE payment_token_id = %d", $row['token_id'] ), ARRAY_A );
	foreach ( $meta as $entry ) {
		$snapshot[ 'token:' . $row['token_id'] . '.meta.' . $entry['meta_key'] ] = wp_json_encode( $entry['meta_value'] );
	}
}
$order = wc_get_order( (int) getenv( 'E2E_ROLLBACK_ORDER_ID' ) );
if ( ! $order ) {
	throw new RuntimeException( 'The rollback fixture order is missing.' );
}
$snapshot['order:payment_method'] = wp_json_encode( $order->get_payment_method() );
$snapshot['order:status'] = wp_json_encode( $order->get_status() );
foreach ( array( '_wcpay_mode', '_wcpay_intent_currency', '_intent_id', '_charge_id', '_payment_method_id' ) as $meta_key ) {
	$snapshot[ 'order:' . $meta_key ] = wp_json_encode( $order->get_meta( $meta_key ) );
}
return $snapshot;
`;

/**
 * Seeds, with the 11.2.0 plugin active, the plugin-era records a rollback
 * must find again: two saved tokens, multi-currency settings and one
 * live-mode order. All are local rows written in the plugin's own shapes;
 * nothing reaches the provider.
 */
const ROLLBACK_SEED_PHP = String.raw`
$customer = get_user_by( 'login', 'customer' );
if ( ! $customer || ! class_exists( 'WC_Payment_Token_WCPay_SEPA' ) ) {
	throw new RuntimeException( 'The 11.2.0 profile customer or the plugin token classes are missing.' );
}
$card = new WC_Payment_Token_CC();
$card->set_token( 'pm_rollback_fixture_card' );
$card->set_gateway_id( 'woocommerce_payments' );
$card->set_card_type( 'visa' );
$card->set_last4( '4242' );
$card->set_expiry_month( '12' );
$card->set_expiry_year( '2034' );
$card->set_user_id( $customer->ID );
$card->set_default( true );
$card->save();
// Client 11.1.0 class-wc-payment-token-wcpay-sepa.php:26 type 'wcpay_sepa'.
$sepa = new WC_Payment_Token_WCPay_SEPA();
$sepa->set_token( 'pm_rollback_fixture_sepa' );
$sepa->set_gateway_id( 'woocommerce_payments_sepa_debit' );
$sepa->set_last4( '3000' );
$sepa->set_user_id( $customer->ID );
$sepa->save();
// Shapes from client 11.1.0 MultiCurrency.php:669-691 and :767-783.
update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR' ) );
update_option( 'wcpay_multi_currency_price_rounding_eur', 1.0 );
update_option( 'wcpay_multi_currency_price_charm_eur', -0.01 );
update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
update_option( 'wcpay_multi_currency_manual_rate_eur', 0.9 );
update_option( 'wcpay_multi_currency_stored_customer_currencies', array( 'EUR' ) );
// A live-mode order as client 11.1.0 stores it: Order_Mode::PRODUCTION
// ('prod', includes/constants/class-order-mode.php:21) and the uppercased
// intention currency (class-wc-payments-api-payment-intention.php:93).
$order = wc_create_order( array( 'customer_id' => $customer->ID ) );
$order->set_payment_method( 'woocommerce_payments' );
$order->set_total( '10.99' );
$order->update_meta_data( '_wcpay_mode', 'prod' );
$order->update_meta_data( '_wcpay_intent_currency', 'USD' );
$order->update_meta_data( '_intent_id', 'pi_rollback_fixture' );
$order->update_meta_data( '_charge_id', 'ch_rollback_fixture' );
$order->update_meta_data( '_payment_method_id', 'pm_rollback_fixture_card' );
$order->set_status( 'processing' );
$order->save();
return $order->get_id();
`;

type RollbackSnapshot = Record< string, string >;

async function readRollbackSnapshot(
	orderId: number
): Promise< RollbackSnapshot > {
	return wpEvalJson< RollbackSnapshot >( ROLLBACK_SNAPSHOT_PHP, [
		`E2E_ROLLBACK_ORDER_ID=${ orderId }`,
	] );
}

interface SnapshotDiff {
	removed: string[];
	changed: Array< { path: string; before: string; after: string } >;
	added: string[];
}

function diffSnapshots(
	before: RollbackSnapshot,
	after: RollbackSnapshot
): SnapshotDiff {
	return {
		removed: Object.keys( before ).filter(
			( path ) => ! ( path in after )
		),
		changed: Object.keys( before )
			.filter(
				( path ) => path in after && after[ path ] !== before[ path ]
			)
			.map( ( path ) => ( {
				path,
				before: before[ path ],
				after: after[ path ],
			} ) ),
		added: Object.keys( after ).filter( ( path ) => ! ( path in before ) ),
	};
}

/**
 * Asserts the rollback rule: every pre-cutover path is still there with the
 * same value, so the only allowed difference is an added path.
 */
async function expectOnlyAddedPaths(
	before: RollbackSnapshot,
	after: RollbackSnapshot,
	stage: string
): Promise< void > {
	const diff = diffSnapshots( before, after );
	await test.info().attach( `rollback-snapshot-diff-${ stage }`, {
		body: JSON.stringify( diff, null, 2 ),
		contentType: 'application/json',
	} );
	expect(
		diff.removed,
		`${ stage }: plugin-era paths must survive the round trip`
	).toEqual( [] );
	expect(
		diff.changed,
		`${ stage }: plugin-era values must be identical after the round trip`
	).toEqual( [] );
}

async function readWooPaymentsGatewayClass(): Promise< string > {
	return wpEvalJson< string >( `
		$gateway = WC()->payment_gateways()->payment_gateways()['woocommerce_payments'] ?? null;
		return $gateway ? get_class( $gateway ) : '';
	` );
}

test.describe( 'WooPayments transition: rollback round trip', () => {
	test.describe.configure( { timeout: 10 * 60_000 } );

	let orderId: number;

	test.beforeAll( async () => {
		process.env.E2E_WP_ENV_CONFIG ??=
			'tests/e2e/test-plugins/woopayments-transition-seed/wp-env.json';
		await wpCLI( [ 'wp', 'woopayments-e2e-transition', 'reset' ] );
		// The 11.2.0 card-only profile.
		const identity = JSON.parse(
			(
				await wpCLI( [
					'wp',
					'woopayments-e2e-transition',
					'seed',
					'historical-tokens',
					'--version=11.2.0',
				] )
			).stdout
				.trim()
				.split( '\n' )
				.pop()!
		);
		expect( identity.plugin_version ).toBe( '11.2.0' );
		expect( identity.plugin_active ).toBe( true );
		expect( identity.is_live ).toBe( false );
		expect( identity.native_state ).toBe( 'available' );
		orderId = await wpEvalJson< number >( ROLLBACK_SEED_PHP );
	} );

	test.afterAll( async () => {
		await wpCLI( [ 'wp', 'woopayments-e2e-transition', 'reset' ] );
	} );

	/**
	 * Plugin re-activation is the rollback lever,
	 * so an unchanged cutover followed by re-activation, and then by a second
	 * deactivation, must leave every plugin-era setting, token and order
	 * record as the plugin wrote it. Provider-free: no payment, capture or
	 * refund runs.
	 */
	test(
		'plugin re-activation after an unchanged cutover keeps every plugin-era setting, token and order record',
		{
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_TRANSITION ],
		},
		async ( { page, restApi, baseURL } ) => {
			expect( await readRuntimeOwner( restApi ) ).toBe( 'extension' );
			const before = await readRollbackSnapshot( orderId );
			expect(
				Object.keys( before ).filter( ( path ) =>
					/^token:\d+\.token$/.test( path )
				),
				'the fixture must hold both plugin-era tokens'
			).toHaveLength( 2 );
			expect( before[ 'order:_wcpay_mode' ] ).toBe( '"prod"' );
			expect( before[ 'order:_wcpay_intent_currency' ] ).toBe( '"USD"' );
			expect(
				before[ 'option:wcpay_multi_currency_enabled_currencies' ]
			).toBe( '["USD","EUR"]' );
			expect(
				before[
					'option:woocommerce_woocommerce_payments_apple_pay_settings.enabled'
				],
				'the fixture must hold split per-method options'
			).toBeDefined();

			// Cutover: the merchant's one click, then the product's own
			// retry cycle driven without its real cadence (the same test-time
			// shortcut as the 10.4.0 case above).
			await page.goto( 'wp-login.php' );
			await logIn( page, admin.username, admin.password, false );
			await page.goto( 'wp-admin/' );
			const link = page.getByRole( 'link', {
				name: 'Start the switch',
				exact: true,
			} );
			const href = await link.getAttribute( 'href', { timeout: 30_000 } );
			validateCutoverActionURL( href ?? '', baseURL! );
			await link.click( { timeout: 30_000 } );
			let status = await readCutoverStatus( 0 );
			await expect
				.poll(
					async () => {
						await rescheduleDeferredAttemptToNow();
						await triggerActionSchedulerQueue( baseURL! );
						status = await readCutoverStatus( 0 );
						return status.record?.state ?? null;
					},
					{ timeout: 3 * 60_000, intervals: [ 1000, 3000, 5000 ] }
				)
				.toBe( 'done' );
			expect( status.plugin_active ).toBe( false );
			const doneGeneration = status.record?.generation ?? 0;
			await expect
				.poll( () => readRuntimeOwner( restApi ), { timeout: 60_000 } )
				.toBe( 'builtin' );
			await expectNativeGatewayActive( restApi, status );
			const native = await readRollbackSnapshot( orderId );
			await test.info().attach( 'rollback-snapshot-diff-native', {
				body: JSON.stringify(
					diffSnapshots( before, native ),
					null,
					2
				),
				contentType: 'application/json',
			} );

			// Rollback: re-activate the plugin, changing nothing else.
			await wpCLI( [
				'wp',
				'plugin',
				'activate',
				'woocommerce-payments',
			] );
			await expect
				.poll( () => readRuntimeOwner( restApi ), { timeout: 60_000 } )
				.toBe( 'extension' );
			const gateway = (
				await restApi.get(
					'wc/v3/payment_gateways/woocommerce_payments'
				)
			).data as { enabled: boolean };
			expect(
				gateway.enabled,
				"the plugin's gateway must be enabled after re-activation"
			).toBe( true );
			expect( await readWooPaymentsGatewayClass() ).toBe(
				'WC_Payment_Gateway_WCPay'
			);
			await expectOnlyAddedPaths(
				before,
				await readRollbackSnapshot( orderId ),
				'plugin-reactivated'
			);
			// WP-CLI activates the plugin outside wp-admin; the cutover still
			// records the rollback and offers the switch again.
			const reopened = ( await readCutoverStatus( 0 ) ).record;
			expect( reopened?.state ).toBe( 'pending' );
			expect( reopened?.current_step ).toBe( 'awaiting_merchant_start' );
			expect( reopened?.generation ?? 0 ).toBeGreaterThan(
				doneGeneration
			);

			// And forward again: deactivating the extension hands payments to nobody.
			// The store waits for its switch, and the merchant's click on the
			// reopened generation finishes it.
			await wpCLI( [
				'wp',
				'plugin',
				'deactivate',
				'woocommerce-payments',
			] );
			await expect
				.poll( () => readRuntimeOwner( restApi ), { timeout: 60_000 } )
				.toBe( 'none' );
			await expectOnlyAddedPaths(
				before,
				await readRollbackSnapshot( orderId ),
				'plugin-deactivated'
			);
			expect(
				( await readCutoverStatus( 0 ) ).record?.current_step,
				'deactivating the extension queues nothing'
			).toBe( 'awaiting_merchant_start' );
			expect(
				await readGatewayIds( restApi ),
				'no WooPayments gateway while the store waits for its switch'
			).not.toContain( 'woocommerce_payments' );
			await page.goto( 'wp-admin/' );
			const again = page.getByRole( 'link', {
				name: 'Start the switch',
				exact: true,
			} );
			validateCutoverActionURL(
				( await again.getAttribute( 'href', { timeout: 30_000 } ) ) ??
					'',
				baseURL!
			);
			await again.click( { timeout: 30_000 } );
			await expect
				.poll(
					async () => {
						await rescheduleDeferredAttemptToNow();
						await triggerActionSchedulerQueue( baseURL! );
						const record = ( await readCutoverStatus( 0 ) ).record;
						return record?.generation === reopened?.generation
							? ( record?.state ?? null )
							: null;
					},
					{ timeout: 3 * 60_000, intervals: [ 1000, 3000, 5000 ] }
				)
				.toBe( 'done' );
			await expect
				.poll( () => readRuntimeOwner( restApi ), { timeout: 60_000 } )
				.toBe( 'builtin' );
			await expectNativeGatewayActive(
				restApi,
				await readCutoverStatus( 0 )
			);
			await expectOnlyAddedPaths(
				before,
				await readRollbackSnapshot( orderId ),
				'switched-again'
			);
		}
	);
} );

const PENDING_RECONCILE_ACTIONS_PHP = String.raw`
return (int) count( as_get_scheduled_actions( array( 'hook' => 'woocommerce_woopayments_cutover_reconcile', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' ) );
`;

const PENDING_RECONCILE_ACTION_IDS_PHP = String.raw`
return array_map( 'intval', array_values( as_get_scheduled_actions( array( 'hook' => 'woocommerce_woopayments_cutover_reconcile', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' ) ) );
`;

/**
 * Reads one Action Scheduler action's status.
 */
async function readActionStatus( actionId: number ): Promise< string > {
	return wpEvalJson< string >(
		`return (string) ActionScheduler::store()->get_status( ${ Number(
			actionId
		) } );`
	);
}

/**
 * Reads the extension's first queued order check: its status, and when the queue started it.
 */
async function readExtensionOrderCheck(): Promise< {
	status: string;
	started_at: number;
} | null > {
	return wpEvalJson( `
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, last_attempt_gmt FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s ORDER BY action_id ASC LIMIT 1", '${ EXTENSION_ORDER_CHECK_HOOK }' ), ARRAY_A );
		return $row ? array( 'status' => $row['status'], 'started_at' => (int) strtotime( $row['last_attempt_gmt'] . ' UTC' ) ) : null;
	` );
}

/**
 * Drives the queue a few times and asserts the rolled-back generation stays as
 * it is: no attempt claims it, nothing deactivates the extension again and the
 * data migration does not run (any attempt would bump the record's revision).
 */
async function expectRollbackStaysPut(
	restApi: ApiClient,
	baseURL: string,
	rollback: CutoverRecord
): Promise< void > {
	for ( let tick = 0; tick < 3; tick++ ) {
		expect(
			await forceObsoleteReconcileActionsDue(),
			"the record still waits for the merchant's start"
		).toBe( true );
		await triggerActionSchedulerQueue( baseURL );
	}
	const settled = await readCutoverStatus( 0 );
	expect(
		settled.plugin_active,
		'nothing deactivates the extension again'
	).toBe( true );
	expect( settled.record?.generation ).toBe( rollback.generation );
	expect(
		settled.record?.revision,
		'no attempt claims the rolled-back generation'
	).toBe( rollback.revision );
	expect( settled.record?.current_step ).toBe( 'awaiting_merchant_start' );
	expect( await readRuntimeOwner( restApi ) ).toBe( 'extension' );
}

/**
 * Asserts an extension activation closed the open generation as the
 * merchant's rollback: a new generation waits for the merchant's start, the
 * open one's next attempt is gone, the extension owns payments again and the
 * setup tier follows it.
 */
async function expectRolledBack(
	restApi: ApiClient,
	open: CutoverRecord
): Promise< CutoverRecord > {
	await expect
		.poll( () => readRuntimeOwner( restApi ), { timeout: 60_000 } )
		.toBe( 'extension' );
	const status = await readCutoverStatus( 0 );
	expect( status.plugin_active ).toBe( true );
	expect( status.record?.generation ).toBe( open.generation + 1 );
	expect( status.record?.state ).toBe( 'pending' );
	expect( status.record?.current_step ).toBe( 'awaiting_merchant_start' );
	expect( status.native_state, 'the tier follows the extension' ).toBe(
		'available'
	);
	expect(
		await wpEvalJson< number >( PENDING_RECONCILE_ACTIONS_PHP ),
		'the open generation keeps no scheduled attempt'
	).toBe( 0 );

	return status.record!;
}

test.describe( 'WooPayments transition: extension activation during an open cutover', () => {
	test.describe.configure( { timeout: 10 * 60_000 } );

	test.beforeEach( async () => {
		process.env.E2E_WP_ENV_CONFIG ??=
			'tests/e2e/test-plugins/woopayments-transition-seed/wp-env.json';
		await wpCLI( [ 'wp', 'woopayments-e2e-transition', 'reset' ] );
		// The 11.2.0 card-only profile, as the rollback round trip uses.
		const identity = JSON.parse(
			(
				await wpCLI( [
					'wp',
					'woopayments-e2e-transition',
					'seed',
					'historical-tokens',
					'--version=11.2.0',
				] )
			).stdout
				.trim()
				.split( '\n' )
				.pop()!
		);
		expect( identity.plugin_version ).toBe( '11.2.0' );
		expect( identity.plugin_active ).toBe( true );
		expect( identity.native_state ).toBe( 'available' );
	} );

	test.afterEach( async () => {
		// A test that timed out never reached its own release.
		await releaseQueueRunners();
	} );

	test.afterAll( async () => {
		await wpCLI( [ 'wp', 'woopayments-e2e-transition', 'reset' ] );
	} );

	/**
	 * The merchant reactivating the extension after the job
	 * deactivated it, before the switch completed, is the rollback. Before
	 * the fix the open generation's next attempt deactivated it again.
	 */
	test(
		'reactivating the extension after the cutover job deactivated it rolls the switch back',
		{
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_TRANSITION ],
		},
		async ( { page, restApi, baseURL } ) => {
			expect( await readRuntimeOwner( restApi ) ).toBe( 'extension' );
			await page.goto( 'wp-login.php' );
			await logIn( page, admin.username, admin.password, false );
			await page.goto( 'wp-admin/' );
			const link = page.getByRole( 'link', {
				name: 'Start the switch',
				exact: true,
			} );
			const href = await link.getAttribute( 'href', { timeout: 30_000 } );
			validateCutoverActionURL( href ?? '', baseURL! );
			await link.click( { timeout: 30_000 } );

			// This case keeps the fresh store on purpose: the admin page above
			// queued the extension's order check 10 s ahead and the click came
			// first, so the switch defers for that check. Drive the queue until
			// the check has run, moving no attempt.
			await expect
				.poll(
					async () => {
						const record = ( await readCutoverStatus( 0 ) ).record;
						const polled = await readExtensionOrderCheck();
						if (
							record?.state === 'deferred' &&
							polled?.status === 'complete'
						) {
							return true;
						}
						await triggerActionSchedulerQueue( baseURL! );
						return false;
					},
					{ timeout: 3 * 60_000, intervals: [ 1000, 3000, 5000 ] }
				)
				.toBe( true );
			const check = await readExtensionOrderCheck();
			expect(
				check?.status,
				"the extension's order check must have run through the queue"
			).toBe( 'complete' );

			// The ownership check is due 5 s after the hand-over, so hold the
			// queue's runners before the hand-over, and wait out any runner
			// admitted before the hold: the extension then comes back while
			// the switch is still open, every time.
			let rollback: CutoverRecord;
			await holdQueueRunners();
			try {
				await waitForIdleQueueRunners();
				await refreshQueueRunnerHold();
				expect(
					await runDeferredAttemptNow(),
					'the deferred attempt runs here'
				).toBeGreaterThan( 0 );
				const open = ( await readCutoverStatus( 0 ) ).record!;
				expect(
					open.state,
					'the switch is still open when the extension comes back'
				).toBe( 'pending' );
				expect( open.current_step ).toBe( 'verify_builtin_ownership' );
				expect( ( await readCutoverStatus( 0 ) ).plugin_active ).toBe(
					false
				);
				const heldChecks = await wpEvalJson< number[] >(
					PENDING_RECONCILE_ACTION_IDS_PHP
				);
				expect(
					heldChecks,
					'the ownership check waits, held'
				).toHaveLength( 1 );

				const steps = open.step_log ?? [];
				const deferral = steps.find(
					( entry ) =>
						entry.step === 'deferred' &&
						( entry.context?.codes ?? [] ).includes(
							'operational_queue_hooks_unhandled'
						)
				);
				expect(
					deferral?.at,
					'the switch must defer while the check is pending'
				).toBeLessThanOrEqual( check!.started_at );
				const handOver = steps.find(
					( entry ) => entry.step === 'verify_builtin_ownership'
				);
				expect(
					handOver?.at,
					'the hand-over must follow once the check has run'
				).toBeGreaterThanOrEqual( check!.started_at );

				await refreshQueueRunnerHold();
				await wpCLI( [
					'wp',
					'plugin',
					'activate',
					'woocommerce-payments',
				] );

				// The open generation's rollback cancels the held ownership
				// check; a check that ran would read complete instead.
				rollback = await expectRolledBack( restApi, open );
				expect(
					await readActionStatus( heldChecks[ 0 ] ),
					'the open generation was rolled back, not a completed switch'
				).toBe( 'canceled' );
				await refreshQueueRunnerHold();
			} finally {
				await releaseQueueRunners();
			}
			await expectRollbackStaysPut( restApi, baseURL!, rollback );
		}
	);

	/**
	 * The switch reads only what the extension stored, so a store
	 * whose merchant deactivated and deleted the extension still switches. The
	 * 11.2.0 extension registers no uninstall hook, so its options stay.
	 */
	test(
		'the switch completes after the extension was deactivated and deleted from disk',
		{
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_TRANSITION ],
		},
		async ( { page, restApi, baseURL } ) => {
			expect( await readRuntimeOwner( restApi ) ).toBe( 'extension' );
			await wpCLI( [
				'wp',
				'plugin',
				'deactivate',
				'woocommerce-payments',
			] );
			// A merchant deletes from Plugins after the deactivation's redirect,
			// so a request runs in between. With the 11.2.0 extension, deleting
			// in the very next request fatals on shutdown, on trunk too: the
			// Jetpack autoloader still lists the deactivated plugin for one
			// request and autoloads its Connection class after the delete.
			expect( await readRuntimeOwner( restApi ) ).toBe( 'none' );
			await wpCLI( [ 'wp', 'plugin', 'delete', 'woocommerce-payments' ] );
			await expect
				.poll( () => readRuntimeOwner( restApi ), { timeout: 60_000 } )
				.toBe( 'none' );
			expect(
				await readGatewayIds( restApi ),
				'no WooPayments gateway while the store waits for its switch'
			).not.toContain( 'woocommerce_payments' );

			await page.goto( 'wp-login.php' );
			await logIn( page, admin.username, admin.password, false );
			await page.goto( 'wp-admin/' );
			const link = page.getByRole( 'link', {
				name: 'Start the switch',
				exact: true,
			} );
			const href = await link.getAttribute( 'href', { timeout: 30_000 } );
			validateCutoverActionURL( href ?? '', baseURL! );
			await link.click( { timeout: 30_000 } );
			let status = await readCutoverStatus( 0 );
			await expect
				.poll(
					async () => {
						await rescheduleDeferredAttemptToNow();
						await triggerActionSchedulerQueue( baseURL! );
						status = await readCutoverStatus( 0 );
						return status.record?.state ?? null;
					},
					{ timeout: 3 * 60_000, intervals: [ 1000, 3000, 5000 ] }
				)
				.toBe( 'done' );
			expect( status.plugin_active ).toBe( false );
			await expect
				.poll( () => readRuntimeOwner( restApi ), { timeout: 60_000 } )
				.toBe( 'builtin' );
			await expectNativeGatewayActive( restApi, status );
		}
	);
} );
