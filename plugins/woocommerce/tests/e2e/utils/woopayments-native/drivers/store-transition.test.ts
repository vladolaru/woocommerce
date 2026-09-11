import { expect, test } from '@playwright/test';
import { mkdirSync, mkdtempSync, realpathSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import {
	validateCutoverActionURL,
	validateCutoverProfile,
	validateCutoverStatus,
	validateOldPluginCutoverAdvance,
	publicCutoverStatus,
	readCutoverStatus,
} from './store-transition';
import type { TransitionAllocation } from '../transition-allocation';
import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';
import { runWpCliProbe } from '../../../tests/woopayments-native/compat/wp-cli';

test( 'reads raw transition state through the allocated wp-env config without an extension profile', async () => {
	const state = { state: 'pending', generation: 1, revision: 2 };
	const result = await runWpCliProbe(
		"return get_option( 'woocommerce_woopayments_cutover_state', null );",
		{
			wpEnvConfig: '/allocated/run/store/.wp-env.json',
			wpEnvHome: '/allocated/run/wp-env-home',
		},
		async ( command, args, options ) => {
			expect( command ).toBe( 'pnpm' );
			expect( args.slice( 0, 6 ) ).toEqual( [
				'exec',
				'wp-env',
				'--config',
				'/allocated/run/store/.wp-env.json',
				'run',
				'cli',
			] );
			expect( options.cwd ).toMatch( /plugins\/woocommerce$/ );
			expect( options.env?.WP_ENV_HOME ).toBe(
				'/allocated/run/wp-env-home'
			);
			const encoded = args
				.at( -1 )!
				.match( /base64_decode\( '([^']+)'/ )![ 1 ];
			const source = Buffer.from( encoded, 'base64' ).toString();
			expect( source ).toContain(
				'woocommerce_woopayments_cutover_state'
			);
			expect( source ).not.toContain(
				'e2e_woopayments_extension_compat_profile'
			);
			return `cli startup\n__WOOPAYMENTS_EXTENSION_COMPAT_RESULT__${ JSON.stringify(
				state
			) }\n`;
		}
	);
	expect( result ).toEqual( state );
} );

const baseURL = 'http://transition.test:9123/shop/';
const actionURL = `${ baseURL }wp-admin/admin.php?wc_woopayments_cutover_action=disable_woopayments&_wc_woopayments_cutover_nonce=123456789a`;

test( 'accepts the exact nonce-protected action on the allocated subdirectory store', () => {
	expect( validateCutoverActionURL( actionURL, baseURL ).href ).toBe(
		actionURL
	);
} );

for ( const [ name, href ] of [
	[ 'foreign origin', actionURL.replace( 'transition.test', 'other.test' ) ],
	[ 'different port', actionURL.replace( '9123', '9124' ) ],
	[ 'different installation', actionURL.replace( '/shop/', '/another/' ) ],
	[
		'different action',
		actionURL.replace( 'disable_woopayments', 'delete' ),
	],
	[ 'missing nonce', actionURL.split( '&' )[ 0 ] ],
	[ 'empty nonce', actionURL.replace( '123456789a', '' ) ],
	[ 'short nonce', actionURL.replace( '123456789a', '123456789' ) ],
	[ 'long nonce', actionURL.replace( '123456789a', '123456789ab' ) ],
	[ 'punctuated nonce', actionURL.replace( '123456789a', '123456789-' ) ],
	[ 'padded nonce', actionURL.replace( '123456789a', '%20123456789a' ) ],
	[ 'newline nonce', `${ actionURL }%0A` ],
	[
		'duplicate nonce',
		`${ actionURL }&_wc_woopayments_cutover_nonce=another`,
	],
	[
		'duplicate action',
		`${ actionURL }&wc_woopayments_cutover_action=delete`,
	],
	[ 'unexpected query', `${ actionURL }&redirect_to=https://other.test` ],
	[ 'credentials', actionURL.replace( 'http://', 'http://user:pass@' ) ],
	[ 'fragment', `${ actionURL }#other` ],
] ) {
	test( `refuses ${ name } before any cutover mutation`, () => {
		expect( () => validateCutoverActionURL( href, baseURL ) ).toThrow(
			'nonce-protected controller entry point'
		);
	} );
}

const allocation = {
	base_url: baseURL,
	store_id: 'transition-unit',
	run_id: 'unit-run',
	workspace: '/allocated/unit-run',
	plugin_version: '10.5.0',
	seed_hash: 'a'.repeat( 64 ),
	wpcom_blog_id: 17,
	account_id: 'acct_test',
} as TransitionAllocation;

const resourceState = {
	...allocation,
	pending_migrator_hook: 'wcpay_migrate_subscription_retry',
	pending_migrator_action_id: 42,
};

test( 'binds the requested profile and exact seeded orphan to the allocated store', () => {
	expect(
		validateCutoverProfile( allocation, resourceState, '10.5.0', '1' )
	).toEqual( {
		version: '10.5.0',
		migratorActionId: 42,
	} );
} );

test( 'accepts only the exact older immutable 10.4.0 profile', () => {
	expect(
		validateCutoverProfile(
			{ ...allocation, plugin_version: '10.4.0' },
			{ ...allocation, plugin_version: '10.4.0' },
			'10.4.0',
			'0'
		)
	).toEqual( { version: '10.4.0' } );
} );

for ( const field of [
	'base_url',
	'store_id',
	'run_id',
	'workspace',
	'plugin_version',
	'seed_hash',
	'wpcom_blog_id',
	'account_id',
] ) {
	test( `refuses a resource receipt with a different ${ field }`, () => {
		expect( () =>
			validateCutoverProfile(
				allocation,
				{ ...resourceState, [ field ]: 'wrong' },
				'10.5.0',
				'1'
			)
		).toThrow( 'identity' );
	} );
}

for ( const [ name, state, version, migrator ] of [
	[ 'unknown profile', resourceState, '10.4.1', '1' ],
	[ 'profile mismatch', resourceState, '10.4.0', '1' ],
	[
		'missing action',
		{ ...resourceState, pending_migrator_action_id: undefined },
		'10.5.0',
		'1',
	],
	[
		'wrong hook',
		{ ...resourceState, pending_migrator_hook: 'another_hook' },
		'10.5.0',
		'1',
	],
	[
		'invalid action',
		{ ...resourceState, pending_migrator_action_id: -1 },
		'10.5.0',
		'1',
	],
	[ 'unexpected seeded action', resourceState, '10.5.0', '0' ],
	[ 'ambiguous seed switch', resourceState, '10.5.0', 'true' ],
] as const ) {
	test( `refuses ${ name } before opening the admin action`, () => {
		expect( () =>
			validateCutoverProfile( allocation, state, version, migrator )
		).toThrow( /profile|migrator/ );
	} );
}

const status = {
	runtime_owner: 'plugin',
	cutover: {
		record: {
			state: 'pending',
			generation: 1,
			revision: 2,
			current_step: 'queued',
			step_log: [ { step: 'queued', at: 123 } ],
			deferred_codes: [],
			informational_outcomes: [],
		},
		plugin_active: true,
		network_active: false,
		plugin_version: '10.5.0',
		preflight_failures: [ 'operational_queue_pending' ],
		migrator_action: {
			id: 42,
			hook: 'wcpay_migrate_subscription_retry',
			group: '',
			status: 'pending',
			args: [],
		},
	},
};

const oldPluginStarted = {
	...status,
	cutover: {
		...status.cutover,
		record: {
			...status.cutover.record,
			state: 'pending',
			generation: 3,
			revision: 2,
			current_step: 'queued',
			deferred_codes: [],
		},
		plugin_version: '10.4.0',
		preflight_failures: [
			'unsupported_payment_methods_enabled',
			'woopayments_plugin_version_unsupported',
		],
		migrator_action: null,
	},
};

const oldPluginDeferred = {
	...oldPluginStarted,
	cutover: {
		...oldPluginStarted.cutover,
		record: {
			...oldPluginStarted.cutover.record,
			state: 'deferred',
			revision: 5,
			current_step: 'deferred',
			deferred_codes: [ 'woopayments_plugin_version_unsupported' ],
		},
		preflight_failures: [ 'woopayments_plugin_version_unsupported' ],
	},
};

const oldPluginBefore = {
	...oldPluginStarted,
	cutover: {
		...oldPluginStarted.cutover,
		record: null,
	},
};

test( 'accepts durable old-plugin advancement when the core updater defers explicitly', () => {
	expect(
		validateOldPluginCutoverAdvance(
			oldPluginBefore,
			oldPluginStarted,
			oldPluginDeferred
		)
	).toEqual( oldPluginDeferred );
} );

test( 'accepts an old-plugin disposition already present in the first post-click read', () => {
	expect(
		validateOldPluginCutoverAdvance(
			oldPluginBefore,
			oldPluginDeferred,
			oldPluginDeferred
		)
	).toEqual( oldPluginDeferred );
} );

test( 'accepts durable old-plugin advancement when the core updater reaches supported verification', () => {
	const updated = {
		...oldPluginDeferred,
		runtime_owner: 'native',
		cutover: {
			...oldPluginDeferred.cutover,
			record: {
				...oldPluginDeferred.cutover.record,
				state: 'pending',
				revision: 8,
				current_step: 'verify_native_ownership',
				deferred_codes: [],
			},
			plugin_active: false,
			plugin_version: '11.1.0',
			preflight_failures: [],
		},
	};
	expect(
		validateOldPluginCutoverAdvance(
			oldPluginBefore,
			oldPluginStarted,
			updated
		)
	).toEqual( updated );
	expect(
		validateOldPluginCutoverAdvance( oldPluginBefore, updated, updated )
	).toEqual( updated );
} );

for ( const [ name, advanced ] of [
	[ 'an unchanged queued record', oldPluginStarted ],
	[
		'a different generation',
		{
			...oldPluginDeferred,
			cutover: {
				...oldPluginDeferred.cutover,
				record: {
					...oldPluginDeferred.cutover.record,
					generation: 4,
				},
			},
		},
	],
	[
		'a deferred record without the version blocker',
		{
			...oldPluginDeferred,
			cutover: {
				...oldPluginDeferred.cutover,
				record: {
					...oldPluginDeferred.cutover.record,
					deferred_codes: [ 'native_transport_unavailable' ],
				},
				preflight_failures: [ 'native_transport_unavailable' ],
			},
		},
	],
	[
		'a deferred record after plugin ownership is lost',
		{ ...oldPluginDeferred, runtime_owner: 'native' },
	],
	[
		'an unsupported plugin pretending to enter ownership verification',
		{
			...oldPluginDeferred,
			runtime_owner: 'native',
			cutover: {
				...oldPluginDeferred.cutover,
				record: {
					...oldPluginDeferred.cutover.record,
					state: 'pending',
					current_step: 'verify_native_ownership',
					deferred_codes: [],
				},
				plugin_active: false,
			},
		},
	],
	[
		'a supported plugin with an unrelated remaining failure',
		{
			...oldPluginDeferred,
			runtime_owner: 'native',
			cutover: {
				...oldPluginDeferred.cutover,
				record: {
					...oldPluginDeferred.cutover.record,
					state: 'pending',
					current_step: 'verify_native_ownership',
					deferred_codes: [],
				},
				plugin_active: false,
				plugin_version: '11.1.0',
				preflight_failures: [ 'native_transport_unavailable' ],
			},
		},
	],
] as const ) {
	test( `rejects ${ name } as old-plugin disposition evidence`, () => {
		expect( () =>
			validateOldPluginCutoverAdvance(
				oldPluginBefore,
				oldPluginStarted,
				advanced
			)
		).toThrow( 'automatic-update disposition' );
	} );
}

test( 'polls normal HTTP traffic and reads state from the exact run-owned CLI allocation', async () => {
	const workspace = mkdtempSync( join( tmpdir(), 'cutover-probe-' ) );
	mkdirSync( join( workspace, 'store' ) );
	const previous = process.env.E2E_TRANSITION_ALLOCATION;
	process.env.E2E_TRANSITION_ALLOCATION = JSON.stringify( {
		...allocation,
		workspace,
	} );
	let httpReads = 0;
	const session = {
		requireEphemeralTransitionAllocation: () => {},
		adminApi: {
			get: async ( path: string ) => {
				expect( path ).toBe(
					'/wp-json/wc-native-payments-e2e/v1/status'
				);
				httpReads++;
				return {
					ok: () => true,
					json: async () => ( { runtime_owner: 'plugin' } ),
				};
			},
		},
	} as unknown as ProviderWriteSession;
	try {
		const result = await readCutoverStatus(
			session,
			{ version: '10.5.0', migratorActionId: 42 },
			async ( php, options ) => {
				expect( httpReads ).toBe( 1 );
				expect( options ).toEqual( {
					wpEnvConfig: join(
						realpathSync( join( workspace, 'store' ) ),
						'.wp-env.json'
					),
					wpEnvHome: join( workspace, 'wp-env-home' ),
				} );
				expect( php ).toContain(
					"get_option( 'woocommerce_woopayments_cutover_state', null )"
				);
				expect( php ).toContain( 'fetch_action( 42 )' );
				expect( php ).not.toMatch(
					/update_option|as_schedule|->execute|->run\(/
				);
				return status.cutover;
			}
		);
		expect( result ).toEqual( status );
	} finally {
		rmSync( workspace, { recursive: true, force: true } );
		if ( previous === undefined )
			delete process.env.E2E_TRANSITION_ALLOCATION;
		else process.env.E2E_TRANSITION_ALLOCATION = previous;
	}
} );

test( 'accepts observable progress with the exact migrator identity', () => {
	expect( validateCutoverStatus( status, 42 ) ).toEqual( status );
} );

test( 'projects cutover attachments through an explicit public-safe allowlist', () => {
	const sensitiveStatus = {
		...status,
		cutover: {
			...status.cutover,
			record: {
				...status.cutover.record,
				request_origin_token: 'origin-secret',
				lease_token: 'lease-secret',
				step_log: [
					{
						step: 'queued',
						at: 123,
						context: { token: 'context-secret' },
					},
				],
				informational_outcomes: [
					{ code: 'safe_outcome', value: 'outcome-secret' },
				],
			},
			migrator_action: {
				...status.cutover.migrator_action!,
				args: [ 'action-secret' ],
			},
		},
	};
	const projection = publicCutoverStatus( sensitiveStatus );
	expect( projection ).toEqual( {
		runtime_owner: 'plugin',
		cutover: {
			record: {
				state: 'pending',
				generation: 1,
				revision: 2,
				current_step: 'queued',
				deferred_codes: [],
				informational_outcome_count: 1,
			},
			plugin_active: true,
			network_active: false,
			plugin_version: '10.5.0',
			preflight_failures: [ 'operational_queue_pending' ],
			migrator_action: { present: true, status: 'pending' },
		},
	} );
	const serialized = JSON.stringify( projection );
	for ( const hidden of [
		'origin-secret',
		'lease-secret',
		'context-secret',
		'outcome-secret',
		'action-secret',
		'request_origin_token',
		'lease_token',
	] ) {
		expect( serialized ).not.toContain( hidden );
	}
} );

test( 'accepts an explicitly absent durable record before the merchant starts', () => {
	const beforeStart = {
		...status,
		cutover: { ...status.cutover, record: null },
	};
	expect( validateCutoverStatus( beforeStart, 42 ) ).toEqual( beforeStart );
} );

for ( const [ name, change ] of [
	[
		'different action ID',
		{ migrator_action: { ...status.cutover.migrator_action, id: 43 } },
	],
	[
		'different hook',
		{
			migrator_action: {
				...status.cutover.migrator_action,
				hook: 'unrelated',
			},
		},
	],
	[
		'different group',
		{
			migrator_action: {
				...status.cutover.migrator_action,
				group: 'unrelated',
			},
		},
	],
	[
		'different args',
		{ migrator_action: { ...status.cutover.migrator_action, args: [ 9 ] } },
	],
	[ 'missing action', { migrator_action: null } ],
	[
		'redacted foreign args',
		{
			migrator_action: {
				...status.cutover.migrator_action,
				args: null,
			},
		},
	],
	[
		'invalid revision',
		{ record: { ...status.cutover.record, revision: 0 } },
	],
	[
		'unknown state',
		{ record: { ...status.cutover.record, state: 'success' } },
	],
	[
		'missing deferred codes',
		{ record: { ...status.cutover.record, deferred_codes: undefined } },
	],
	[
		'a non-string deferred code',
		{ record: { ...status.cutover.record, deferred_codes: [ 17 ] } },
	],
	[
		'missing informational outcomes',
		{
			record: {
				...status.cutover.record,
				informational_outcomes: undefined,
			},
		},
	],
	[ 'missing plugin state', { plugin_active: undefined } ],
	[ 'missing record field', { record: undefined } ],
] as const ) {
	test( `rejects ${ name } in cutover observations`, () => {
		expect( () =>
			validateCutoverStatus(
				{ ...status, cutover: { ...status.cutover, ...change } },
				42
			)
		).toThrow( /cutover|migrator/ );
	} );
}
