import { expect, type Page } from '@playwright/test';
import { readFileSync, realpathSync } from 'node:fs';
import { join } from 'node:path';

import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';
import type { TransitionAllocation } from '../transition-allocation';
import { runWpCliProbe } from '../../../tests/woopayments-native/compat/wp-cli';

export interface CutoverProfile {
	version: '10.5.0' | '10.4.0';
	migratorActionId?: number;
}

export interface CutoverStatus {
	runtime_owner: string;
	cutover: {
		record: null | {
			state: string;
			generation: number;
			revision: number;
			current_step: string;
			step_log: { step: string; at: number }[];
			deferred_codes: string[];
			informational_outcomes: unknown[];
		};
		plugin_active: boolean;
		network_active: boolean;
		plugin_version: string;
		preflight_failures: string[];
		migrator_action: null | {
			id: number;
			hook: string;
			group: string;
			status: string;
			args: unknown[];
		};
	};
}

export function validateCutoverProfile(
	allocation: TransitionAllocation,
	resourceState: unknown,
	version: string,
	pendingMigrator: string
): CutoverProfile {
	if (
		! [ '10.5.0', '10.4.0' ].includes( version ) ||
		allocation.plugin_version !== version
	) {
		throw new Error(
			'Cutover profile must match the exact immutable allocation version.'
		);
	}
	const state = resourceState as Record< string, unknown >;
	for ( const key of [
		'base_url',
		'store_id',
		'run_id',
		'workspace',
		'plugin_version',
		'seed_hash',
		'wpcom_blog_id',
		'account_id',
	] as const ) {
		if ( ! state || state[ key ] !== allocation[ key ] ) {
			throw new Error( `Cutover resource identity mismatch: ${ key }.` );
		}
	}
	if ( ! [ '0', '1' ].includes( pendingMigrator ) ) {
		throw new Error( 'Cutover migrator seed must be exactly 0 or 1.' );
	}
	if ( pendingMigrator === '1' ) {
		if (
			state.pending_migrator_hook !==
				'wcpay_migrate_subscription_retry' ||
			! Number.isSafeInteger( state.pending_migrator_action_id ) ||
			Number( state.pending_migrator_action_id ) <= 0
		) {
			throw new Error(
				'Cutover migrator receipt must name the exact seeded hook and positive action ID.'
			);
		}
		return {
			version: version as CutoverProfile[ 'version' ],
			migratorActionId: state.pending_migrator_action_id as number,
		};
	}
	if (
		state.pending_migrator_hook !== undefined ||
		state.pending_migrator_action_id !== undefined
	) {
		throw new Error(
			'Cutover profile contains an unexpected migrator seed.'
		);
	}
	return { version: version as CutoverProfile[ 'version' ] };
}

export function readCutoverProfile(
	session: ProviderWriteSession
): CutoverProfile {
	session.requireEphemeralTransitionAllocation();
	session.requireApprovedProviderFixture( 'cutover-reconciliation' );
	const allocation: TransitionAllocation = JSON.parse(
		process.env.E2E_TRANSITION_ALLOCATION!
	);
	return validateCutoverProfile(
		allocation,
		JSON.parse(
			readFileSync(
				join( allocation.workspace, 'resource-state.json' ),
				'utf8'
			)
		),
		process.env.E2E_TRANSITION_SEED_PROFILE ?? '',
		process.env.E2E_TRANSITION_PENDING_MIGRATOR_HOOK ?? ''
	);
}

export function validateCutoverStatus(
	value: unknown,
	migratorActionId?: number
): CutoverStatus {
	const status = value as CutoverStatus;
	const cutover = status?.cutover;
	const record = cutover?.record;
	if (
		! cutover ||
		typeof status.runtime_owner !== 'string' ||
		typeof cutover.plugin_active !== 'boolean' ||
		typeof cutover.network_active !== 'boolean' ||
		typeof cutover.plugin_version !== 'string' ||
		! /^\d+\.\d+\.\d+/.test( cutover.plugin_version ) ||
		! Array.isArray( cutover.preflight_failures ) ||
		! cutover.preflight_failures.every(
			( code ) => typeof code === 'string'
		) ||
		record === undefined
	) {
		throw new Error( 'Invalid cutover status payload.' );
	}
	if (
		record !== null &&
		( ! [ 'pending', 'running', 'deferred', 'done', 'excluded' ].includes(
			record.state
		) ||
			! Number.isSafeInteger( record.generation ) ||
			record.generation <= 0 ||
			! Number.isSafeInteger( record.revision ) ||
			record.revision <= 0 ||
			typeof record.current_step !== 'string' ||
			! record.current_step ||
			! Array.isArray( record.step_log ) ||
			! Array.isArray( record.deferred_codes ) ||
			! record.deferred_codes.every(
				( code ) => typeof code === 'string'
			) ||
			! Array.isArray( record.informational_outcomes ) )
	) {
		throw new Error( 'Invalid persisted cutover progress.' );
	}
	if ( migratorActionId !== undefined ) {
		const action = cutover.migrator_action;
		if (
			! Number.isSafeInteger( migratorActionId ) ||
			migratorActionId <= 0 ||
			! action ||
			action.id !== migratorActionId ||
			action.hook !== 'wcpay_migrate_subscription_retry' ||
			action.group !== '' ||
			! Array.isArray( action.args ) ||
			action.args.length !== 0 ||
			! [
				'pending',
				'in-progress',
				'complete',
				'failed',
				'canceled',
			].includes( action.status )
		) {
			throw new Error(
				'Cutover migrator observation does not match the exact seeded action.'
			);
		}
	}
	return status;
}

export function publicCutoverStatus( status: CutoverStatus ) {
	const record = status.cutover.record;
	const action = status.cutover.migrator_action;
	const validActionStatuses = [
		'pending',
		'in-progress',
		'complete',
		'failed',
		'canceled',
	];
	return {
		runtime_owner: status.runtime_owner,
		cutover: {
			record: record
				? {
						state: record.state,
						generation: record.generation,
						revision: record.revision,
						current_step: record.current_step,
						deferred_codes: record.deferred_codes,
						informational_outcome_count:
							record.informational_outcomes.length,
				  }
				: null,
			plugin_active: status.cutover.plugin_active,
			network_active: status.cutover.network_active,
			plugin_version: status.cutover.plugin_version,
			preflight_failures: status.cutover.preflight_failures,
			migrator_action: action
				? {
						present: true,
						status: validActionStatuses.includes( action.status )
							? action.status
							: 'invalid',
				  }
				: { present: false },
		},
	};
}

export function validateOldPluginCutoverAdvance(
	before: CutoverStatus,
	started: CutoverStatus,
	advanced: CutoverStatus
): CutoverStatus {
	const beforeRecord = before.cutover.record;
	const startedRecord = started.cutover.record;
	const advancedRecord = advanced.cutover.record;
	const versionBlocker = 'woopayments_plugin_version_unsupported';
	const provesDisposition = ( status: CutoverStatus ) => {
		const record = status.cutover.record;
		const deferred =
			status.runtime_owner === 'plugin' &&
			status.cutover.plugin_active &&
			status.cutover.plugin_version === '10.4.0' &&
			status.cutover.preflight_failures.length === 1 &&
			status.cutover.preflight_failures[ 0 ] === versionBlocker &&
			record?.state === 'deferred' &&
			record.current_step === 'deferred' &&
			record.deferred_codes.length === 1 &&
			record.deferred_codes[ 0 ] === versionBlocker;
		const supported =
			status.runtime_owner === 'native' &&
			! status.cutover.plugin_active &&
			! status.cutover.network_active &&
			status.cutover.plugin_version.localeCompare( '10.5.0', 'en', {
				numeric: true,
			} ) >= 0 &&
			status.cutover.preflight_failures.length === 0 &&
			( ( record?.state === 'pending' &&
				record.current_step === 'verify_native_ownership' ) ||
				( record?.state === 'done' &&
					record.current_step === 'done' ) );
		return deferred || supported;
	};
	const progressedFromBefore =
		! beforeRecord ||
		( startedRecord &&
			( startedRecord.generation > beforeRecord.generation ||
				( startedRecord.generation === beforeRecord.generation &&
					startedRecord.revision > beforeRecord.revision ) ) );
	const dispositionWasFirstRead =
		advanced === started && provesDisposition( started );
	if (
		before.runtime_owner !== 'plugin' ||
		! before.cutover.plugin_active ||
		before.cutover.plugin_version !== '10.4.0' ||
		! before.cutover.preflight_failures.includes( versionBlocker ) ||
		! startedRecord ||
		! progressedFromBefore ||
		! advancedRecord ||
		advancedRecord.generation !== startedRecord.generation ||
		( ! dispositionWasFirstRead &&
			advancedRecord.revision <= startedRecord.revision ) ||
		! provesDisposition( advanced )
	) {
		throw new Error(
			'Old WooPayments cutover did not prove the automatic-update disposition.'
		);
	}
	return advanced;
}

export async function readCutoverStatus(
	session: ProviderWriteSession,
	profile: CutoverProfile,
	readProbe: (
		php: string,
		options: { wpEnvConfig: string; wpEnvHome: string }
	) => Promise< CutoverStatus[ 'cutover' ] > = runWpCliProbe
): Promise< CutoverStatus > {
	session.requireEphemeralTransitionAllocation();
	const allocation: TransitionAllocation = JSON.parse(
		process.env.E2E_TRANSITION_ALLOCATION!
	);
	const response = await session.adminApi.get(
		'/wp-json/wc-native-payments-e2e/v1/status'
	);
	if ( ! response.ok() ) {
		throw new Error(
			`Unable to read cutover progress: HTTP ${ response.status() }.`
		);
	}
	const runtime = await response.json();
	const actionId = profile.migratorActionId ?? 0;
	if ( ! Number.isSafeInteger( actionId ) || actionId < 0 ) {
		throw new Error(
			'Cutover migrator action ID must be a positive integer.'
		);
	}
	const cutover = await readProbe(
		String.raw`
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin = 'woocommerce-payments/woocommerce-payments.php';
$headers = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
$controller = wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController::class );
$action = ${ actionId } > 0 ? ActionScheduler::store()->fetch_action( ${ actionId } ) : null;
return array(
	'record' => get_option( 'woocommerce_woopayments_cutover_state', null ),
	'plugin_active' => is_plugin_active( $plugin ),
	'network_active' => is_plugin_active_for_network( $plugin ),
	'plugin_version' => $headers['Version'],
	'preflight_failures' => $controller->get_preflight_failures(),
	'migrator_action' => $action ? array(
		'id' => ${ actionId },
		'hook' => $action->get_hook(),
		'group' => $action->get_group(),
		'args' => $action->get_args(),
		'status' => ActionScheduler::store()->get_status( ${ actionId } ),
	) : null,
);
`,
		{
			wpEnvConfig: join(
				realpathSync( join( allocation.workspace, 'store' ) ),
				'.wp-env.json'
			),
			wpEnvHome: join( allocation.workspace, 'wp-env-home' ),
		}
	);
	return validateCutoverStatus(
		{ runtime_owner: runtime.runtime_owner, cutover },
		profile.migratorActionId
	);
}

export function validateCutoverActionURL( href: string, baseURL: string ): URL {
	const expected = new URL( 'wp-admin/admin.php', baseURL );
	const url = new URL( href, baseURL );
	if (
		url.origin !== expected.origin ||
		url.pathname !== expected.pathname ||
		url.username ||
		url.password ||
		url.hash ||
		[ ...url.searchParams.keys() ].toSorted().join( ',' ) !==
			'_wc_woopayments_cutover_nonce,wc_woopayments_cutover_action' ||
		url.searchParams.get( 'wc_woopayments_cutover_action' ) !==
			'disable_woopayments' ||
		! /^[a-zA-Z0-9]{10}$/.test(
			url.searchParams.get( '_wc_woopayments_cutover_nonce' ) ?? ''
		)
	) {
		throw new Error(
			'The product WooPayments cutover action is not bound to the nonce-protected controller entry point.'
		);
	}
	return url;
}

export async function softCutOverEphemeralStore(
	session: ProviderWriteSession,
	page: Page
): Promise< void > {
	const link = await prepareCutoverAction( session, page, false );
	await session.performWrite( () => link.click() );
	await session.assertCurrentRuntimeReady( 'native' );
	await session.logInAsCustomer( page );
}

async function prepareCutoverAction(
	session: ProviderWriteSession,
	page: Page,
	profiled: boolean
) {
	await session.assertCanWrite();
	session.requireEphemeralTransitionAllocation();
	for ( const capability of profiled
		? [ 'cutover-ui', 'cutover-job', 'native-owner' ]
		: [ 'soft-cutover' ] ) {
		session.requireApprovedProviderFixture( capability );
	}
	await session.logInAsAdmin( page );
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
	validateCutoverActionURL( href, session.baseURL );
	await session.assertCanWrite();
	return link;
}

async function startEphemeralCutover(
	session: ProviderWriteSession,
	page: Page,
	profile: CutoverProfile
): Promise< { before: CutoverStatus; started: CutoverStatus } > {
	const link = await prepareCutoverAction( session, page, true );
	const before = await readCutoverStatus( session, profile );
	expect( before.cutover.plugin_version ).toBe( profile.version );
	expect( before.cutover.plugin_active ).toBe( true );
	expect( before.runtime_owner ).toBe( 'plugin' );
	await session.performWrite( () => link.click() );
	await expect(
		page.getByText( 'Switch in progress', { exact: true } )
	).toBeVisible();
	const started = await readCutoverStatus( session, profile );
	expect( started.cutover.record ).not.toBeNull();
	expect( started.cutover.record!.current_step ).not.toBe(
		'awaiting_merchant_start'
	);
	expect( started.cutover.record!.generation ).toBeGreaterThanOrEqual(
		before.cutover.record?.generation ?? 0
	);
	return { before, started };
}

export async function advanceOldPluginCutover(
	session: ProviderWriteSession,
	page: Page,
	profile: CutoverProfile
): Promise< {
	before: CutoverStatus;
	started: CutoverStatus;
	advanced: CutoverStatus;
} > {
	if ( profile.version !== '10.4.0' ) {
		throw new Error(
			'Old WooPayments cutover advancement requires the exact 10.4.0 profile.'
		);
	}
	const { before, started } = await startEphemeralCutover(
		session,
		page,
		profile
	);
	let advanced = started;
	try {
		validateOldPluginCutoverAdvance( before, started, advanced );
	} catch {
		await expect
			.poll(
				async () => {
					advanced = await readCutoverStatus( session, profile );
					try {
						validateOldPluginCutoverAdvance(
							before,
							started,
							advanced
						);
						return true;
					} catch {
						return false;
					}
				},
				{
					timeout: 2 * 60_000,
					intervals: [ 1000, 5000, 15000 ],
				}
			)
			.toBe( true );
	}
	validateOldPluginCutoverAdvance( before, started, advanced );
	await expect(
		page.getByText( 'Switch in progress', { exact: true } )
	).toBeVisible();
	return { before, started, advanced };
}

export async function reconcileEphemeralStore(
	session: ProviderWriteSession,
	page: Page,
	profile: CutoverProfile
): Promise< {
	before: CutoverStatus;
	started: CutoverStatus;
	completed: CutoverStatus;
} > {
	const { before, started } = await startEphemeralCutover(
		session,
		page,
		profile
	);
	let completed = started;
	await expect
		.poll(
			async () => {
				completed = await readCutoverStatus( session, profile );
				return completed.cutover.record?.state === 'done';
			},
			{
				timeout: 20 * 60_000,
				intervals: [ 1000, 5000, 15000 ],
			}
		)
		.toBe( true );
	expect( completed.cutover.record!.generation ).toBe(
		started.cutover.record!.generation
	);
	expect( completed.cutover.record!.revision ).toBeGreaterThan(
		started.cutover.record!.revision
	);
	expect( completed.cutover.plugin_active ).toBe( false );
	expect( completed.cutover.network_active ).toBe( false );
	expect( completed.runtime_owner ).toBe( 'native' );
	await session.assertCurrentRuntimeReady( 'native' );
	await page.goto( 'wp-admin/plugins.php' );
	await expect(
		page.getByText( 'Switch in progress', { exact: true } )
	).toHaveCount( 0 );
	return { before, started, completed };
}
