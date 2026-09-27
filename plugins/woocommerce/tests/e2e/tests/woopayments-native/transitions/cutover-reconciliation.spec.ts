import type { ApiClient } from '@woocommerce/e2e-utils-playwright';
import { fillBillingCheckoutBlocks } from '@woocommerce/e2e-utils-playwright';
import type { Page } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { wpCLI, wpEvalJson } from '../../../utils/cli';
import { admin } from '../../../test-data/data';
import { logIn } from '../../../utils/login';
import { random } from '../../../utils/helpers';
import {
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
 * account (`:8889`, N-123), so the payment below settles against a real
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
$controller = wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController::class );
$migrator_id = (int) getenv( 'E2E_MIGRATOR_ACTION_ID' );
$action = $migrator_id > 0 ? ActionScheduler::store()->fetch_action( $migrator_id ) : null;
return array(
	'record' => get_option( 'woocommerce_woopayments_cutover_state', null ),
	'plugin_active' => is_plugin_active( $plugin ),
	'network_active' => is_plugin_active_for_network( $plugin ),
	'plugin_version' => $headers['Version'],
	'preflight_failures' => $controller->get_preflight_failures(),
	'migrator_action' => $action ? array( 'status' => ActionScheduler::store()->get_status( $migrator_id ) ) : null,
	'native_state' => get_option( 'woocommerce_native_payments_state', null ),
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
		url.searchParams.get( 'wc_woopayments_cutover_action' ) ===
			'disable_woopayments' &&
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
		// The seed runs the 11.2.0-5 repair update the way an upgraded
		// plugin-era store does, which writes `available`.
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
			).toBe( 'plugin' );

			const before = await readCutoverStatus( migratorActionId );
			expect( before.plugin_version ).toBe( '10.5.0' );
			expect( before.plugin_active ).toBe( true );
			expect( before.preflight_failures ).toContain(
				'unsupported_payment_methods_enabled'
			);
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
			expect( completed.migrator_action?.status ).toBe( 'canceled' );
			await expect
				.poll( () => readRuntimeOwner( restApi ), { timeout: 60_000 } )
				.toBe( 'native' );
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
 * Test-time shortcut, not a product finding: reschedules any pending
 * cutover reconciliation Action Scheduler action to run immediately, a
 * direct DB time-shortcut (the same category as WooCommerce Subscriptions
 * never scheduling past dates) instead of idling the run through the
 * product's own real ~15-minute backoff between attempts.
 */
async function rescheduleReconciliationActionsToNow(): Promise< void > {
	await wpEvalJson< true >( `
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}actionscheduler_actions SET scheduled_date_gmt = %s, scheduled_date_local = %s WHERE hook = %s AND status = 'pending'",
			current_time( 'mysql', true ),
			current_time( 'mysql' ),
			'woocommerce_woopayments_cutover_reconcile'
		) );
		return true;
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
			).toBe( 'plugin' );

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
			// own upgrade result lands (F-UPGRADELOG, T.7).
			let owner = '';
			let status = await readCutoverStatus( 0 );
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
			).toBe( 'plugin' );
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
			// cadence (rescheduleReconciliationActionsToNow, a test-time
			// shortcut, not a product finding).
			await expect
				.poll(
					async () => {
						await rescheduleReconciliationActionsToNow();
						await triggerActionSchedulerQueue( baseURL! );
						try {
							owner = await readRuntimeOwner( restApi );
							status = await readCutoverStatus( 0 );
						} catch {
							return null;
						}
						return status.record?.state ?? null;
					},
					{ timeout: 2 * 60_000, intervals: [ 1000, 3000, 5000 ] }
				)
				.toBe( 'done' );

			// Step 3: the all-clear. The plugin is deactivated only here,
			// not during the deferred attempt above (asserted active there).
			expect( owner ).toBe( 'native' );
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
