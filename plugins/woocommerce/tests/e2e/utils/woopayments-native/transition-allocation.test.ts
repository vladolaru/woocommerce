import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { expect, test } from '@playwright/test';

import {
	assertTransitionAllocation,
	type TransitionAllocation,
} from './transition-allocation';

const RUN_ID = 'transition-test';
const STORE_ID = 'transition-store';

function allocation(
	tempRoot: string,
	overrides: Partial< TransitionAllocation > = {}
): TransitionAllocation {
	const workspace = join(
		tempRoot,
		`woopayments-native-transition-${ RUN_ID }`
	);
	return {
		base_url: 'http://transition.localhost:8891',
		store_id: STORE_ID,
		seed_hash: 'a'.repeat( 64 ),
		plugin_version: '10.5.0',
		teardown_token: 'b'.repeat( 64 ),
		run_id: RUN_ID,
		workspace,
		allocation_path: join( workspace, 'allocation.json' ),
		...overrides,
	};
}

async function writeSavedAllocation(
	value: TransitionAllocation
): Promise< void > {
	await mkdir( value.workspace, { recursive: true } );
	await writeFile( value.allocation_path, JSON.stringify( value ) );
}

test( 'accepts an exact emitted and saved transition identity', async () => {
	const tempRoot = await mkdtemp(
		join( tmpdir(), 'woopayments-allocation-' )
	);
	const emitted = allocation( tempRoot );

	try {
		await writeSavedAllocation( emitted );
		expect(
			assertTransitionAllocation( JSON.stringify( emitted ), {
				baseUrl: emitted.base_url,
				storeId: STORE_ID,
				tempRoot,
				runId: RUN_ID,
			} )
		).toEqual( emitted );
	} finally {
		await rm( tempRoot, { recursive: true, force: true } );
	}
} );

test( 'rejects a forged identity that differs from the saved allocation', async () => {
	const tempRoot = await mkdtemp(
		join( tmpdir(), 'woopayments-allocation-forged-' )
	);
	const saved = allocation( tempRoot );

	try {
		await writeSavedAllocation( saved );
		expect( () =>
			assertTransitionAllocation(
				JSON.stringify( {
					...saved,
					teardown_token: 'c'.repeat( 64 ),
				} ),
				{
					baseUrl: saved.base_url,
					storeId: STORE_ID,
					tempRoot,
					runId: RUN_ID,
				}
			)
		).toThrow( /teardown_token.*saved identity/i );
	} finally {
		await rm( tempRoot, { recursive: true, force: true } );
	}
} );

test( 'rejects a transition allocation for a different base URL', async () => {
	const tempRoot = await mkdtemp(
		join( tmpdir(), 'woopayments-allocation-mismatch-' )
	);
	const emitted = allocation( tempRoot );

	try {
		await writeSavedAllocation( emitted );
		expect( () =>
			assertTransitionAllocation( JSON.stringify( emitted ), {
				baseUrl: 'http://different.localhost:8892',
				storeId: STORE_ID,
				tempRoot,
				runId: RUN_ID,
			} )
		).toThrow( /exact Playwright base URL/i );
	} finally {
		await rm( tempRoot, { recursive: true, force: true } );
	}
} );

test( 'rejects an allocation from a prior transition attempt', async () => {
	const tempRoot = await mkdtemp(
		join( tmpdir(), 'woopayments-allocation-prior-run-' )
	);
	const emitted = allocation( tempRoot );

	try {
		await writeSavedAllocation( emitted );
		expect( () =>
			assertTransitionAllocation( JSON.stringify( emitted ), {
				baseUrl: emitted.base_url,
				storeId: STORE_ID,
				tempRoot,
				runId: 'next-transition-attempt',
			} )
		).toThrow( /invalid run ID/i );
	} finally {
		await rm( tempRoot, { recursive: true, force: true } );
	}
} );

test( 'normalizes a trailing-slash TMPDIR allocation path', async () => {
	const tempRoot = await mkdtemp(
		join( tmpdir(), 'woopayments-allocation-trailing-slash-' )
	);
	const rawTempRoot = `${ tempRoot }/`;
	const workspace = `${ rawTempRoot }/woopayments-native-transition-${ RUN_ID }`;
	const emitted = allocation( tempRoot, {
		workspace,
		allocation_path: `${ workspace }/allocation.json`,
	} );

	try {
		await writeSavedAllocation( emitted );
		expect(
			assertTransitionAllocation( JSON.stringify( emitted ), {
				baseUrl: emitted.base_url,
				storeId: STORE_ID,
				tempRoot: rawTempRoot,
				runId: RUN_ID,
			} )
		).toEqual( emitted );
	} finally {
		await rm( tempRoot, { recursive: true, force: true } );
	}
} );

for ( const port of [ '8082', '8889' ] ) {
	test( `rejects standing developer store port ${ port }`, async () => {
		const tempRoot = await mkdtemp(
			join( tmpdir(), `woopayments-allocation-port-${ port }-` )
		);
		const emitted = allocation( tempRoot, {
			base_url: `http://localhost:${ port }`,
		} );

		try {
			await writeSavedAllocation( emitted );
			expect( () =>
				assertTransitionAllocation( JSON.stringify( emitted ), {
					baseUrl: emitted.base_url,
					storeId: STORE_ID,
					tempRoot,
					runId: RUN_ID,
				} )
			).toThrow( /standing developer store/i );
		} finally {
			await rm( tempRoot, { recursive: true, force: true } );
		}
	} );
}
