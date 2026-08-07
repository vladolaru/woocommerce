import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { expect, test } from '@playwright/test';

import {
	assertRuntimeOwnership,
	assertRuntimeReady,
	readRuntimeStatusArtifact,
	type RuntimeStatus,
} from './runtime-readiness';

const expected = {
	siteUrl: 'http://store8889.localhost:8889',
	wpcomBlogId: 882001,
	accountId: 'acct_local_native',
};

function readyStatus(
	overrides: Partial< RuntimeStatus > = {}
): RuntimeStatus {
	return {
		site_url: expected.siteUrl,
		wpcom_blog_id: expected.wpcomBlogId,
		runtime_owner: 'native',
		native_enabled: true,
		account_id: expected.accountId,
		account_connected: true,
		gateway_enabled: true,
		test_mode: true,
		enabled_payment_methods: [ 'card' ],
		last_webhook_fetch: 1_800_000_000,
		callback_probe: {
			registered: true,
			reachable: true,
			wpcom_blog_id: expected.wpcomBlogId,
		},
		...overrides,
	};
}

test( 'accepts exact native runtime readiness', () => {
	expect( () =>
		assertRuntimeReady( 'native', readyStatus(), expected )
	).not.toThrow();
} );

test( 'requires plugin ownership for client and transition runtimes', () => {
	for ( const runtime of [ 'client', 'transition' ] as const ) {
		expect( () =>
			assertRuntimeReady(
				runtime,
				readyStatus( { runtime_owner: 'native' } ),
				expected
			)
		).toThrow( /expected runtime owner plugin/i );
	}
} );

test( 'rejects an exact store, blog, or account mismatch', () => {
	expect( () =>
		assertRuntimeReady(
			'native',
			readyStatus( { site_url: 'http://localhost:8082' } ),
			expected
		)
	).toThrow( /site URL/i );

	expect( () =>
		assertRuntimeReady(
			'native',
			readyStatus( { wpcom_blog_id: 882002 } ),
			expected
		)
	).toThrow( /WPCOM blog ID/i );

	expect( () =>
		assertRuntimeReady(
			'native',
			readyStatus( { account_id: 'acct_other' } ),
			expected
		)
	).toThrow( /account ID/i );
} );

test( 'rejects disconnected, disabled, live-mode, or card-incapable stores', () => {
	for ( const [ field, value ] of [
		[ 'account_connected', false ],
		[ 'gateway_enabled', false ],
		[ 'test_mode', false ],
		[ 'enabled_payment_methods', [ 'link' ] ],
	] as const ) {
		expect( () =>
			assertRuntimeReady(
				'native',
				readyStatus( { [ field ]: value } ),
				expected
			)
		).toThrow();
	}
} );

test( 'webhook freshness cannot substitute for registered callback reachability', () => {
	expect( () =>
		assertRuntimeReady(
			'native',
			readyStatus( {
				last_webhook_fetch: Date.now(),
				callback_probe: {
					registered: false,
					reachable: false,
					wpcom_blog_id: expected.wpcomBlogId,
				},
			} ),
			expected
		)
	).toThrow( /callback/i );
} );

test( 'requires callback proof for the same WPCOM blog', () => {
	expect( () =>
		assertRuntimeReady(
			'native',
			readyStatus( {
				callback_probe: {
					registered: true,
					reachable: true,
					wpcom_blog_id: 882999,
				},
			} ),
			expected
		)
	).toThrow( /callback.*WPCOM blog ID/i );
} );

test( 'allows a borrowed transition fixture to skip callback ownership proof', () => {
	expect( () =>
		assertRuntimeReady(
			'transition',
			readyStatus( {
				runtime_owner: 'plugin',
				native_enabled: false,
				callback_probe: {
					registered: false,
					reachable: false,
					wpcom_blog_id: 0,
				},
			} ),
			expected,
			{ requireCallback: false }
		)
	).not.toThrow();
} );

test( 'reads a validated runtime status artifact', async () => {
	const directory = await mkdtemp(
		join( tmpdir(), 'woopayments-runtime-status-' )
	);
	const artifact = join( directory, 'runtime-status.json' );

	try {
		await writeFile(
			artifact,
			JSON.stringify(
				readyStatus( {
					runtime_owner: 'plugin',
					native_enabled: false,
				} )
			)
		);

		await expect( readRuntimeStatusArtifact( artifact ) ).resolves.toEqual(
			readyStatus( {
				runtime_owner: 'plugin',
				native_enabled: false,
			} )
		);
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'rejects a malformed runtime status artifact', async () => {
	const directory = await mkdtemp(
		join( tmpdir(), 'woopayments-runtime-status-' )
	);
	const artifact = join( directory, 'runtime-status.json' );

	try {
		await writeFile( artifact, '{"runtime_owner":"plugin"}' );

		await expect( readRuntimeStatusArtifact( artifact ) ).rejects.toThrow(
			/invalid runtime status artifact/i
		);
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'ownership assertion accepts a native-owned store without provider expectations', () => {
	// Empty account, disconnected gateway, unregistered callback: none of it
	// is an ownership concern.
	expect( () =>
		assertRuntimeOwnership(
			'native',
			readyStatus( {
				account_id: '',
				account_connected: false,
				gateway_enabled: false,
				test_mode: false,
				enabled_payment_methods: [],
				callback_probe: {
					registered: false,
					reachable: false,
					wpcom_blog_id: 0,
				},
			} ),
			{ siteUrl: expected.siteUrl }
		)
	).not.toThrow();
} );

test( 'ownership assertion still rejects a foreign site, wrong owner, or disabled native runtime', () => {
	expect( () =>
		assertRuntimeOwnership(
			'native',
			readyStatus( { site_url: 'http://other.test' } ),
			{ siteUrl: expected.siteUrl }
		)
	).toThrow( /site URL/ );
	expect( () =>
		assertRuntimeOwnership(
			'native',
			readyStatus( { runtime_owner: 'plugin' } ),
			{ siteUrl: expected.siteUrl }
		)
	).toThrow( /runtime owner/ );
	expect( () =>
		assertRuntimeOwnership(
			'native',
			readyStatus( { native_enabled: false } ),
			{ siteUrl: expected.siteUrl }
		)
	).toThrow( /native runtime is not enabled/ );
	expect( () =>
		assertRuntimeOwnership(
			'client',
			readyStatus( { runtime_owner: 'native' } ),
			{ siteUrl: expected.siteUrl }
		)
	).toThrow( /expected runtime owner plugin/ );
} );
