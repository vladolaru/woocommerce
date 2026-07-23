import { spawn, type ChildProcessWithoutNullStreams } from 'node:child_process';
import { once } from 'node:events';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve as resolvePath } from 'node:path';
import { createInterface } from 'node:readline';
import { pathToFileURL } from 'node:url';

import { expect, test } from '@playwright/test';

import {
	assertAccountSeparation,
	ResourceLockManager,
	type ResourceLock,
	type ResourceLockRequest,
} from './resource-locks';

const accountRequest: ResourceLockRequest = {
	providerAccountId: 'acct_local',
	storeId: 'native-store',
	kind: 'account',
	resource: 'provider-writes',
	diagnosticPath: 'test-results/run-lock',
};

async function lockDirectory(): Promise< string > {
	return mkdtemp( join( tmpdir(), 'woopayments-native-lock-test-' ) );
}

async function yieldToPeer(): Promise< void > {
	return new Promise( ( resolve ) => setImmediate( resolve ) );
}

function spawnLockWorker(
	lockDir: string,
	runId: string,
	leaseMs: number,
	autoRenew: boolean,
	exitAfterAcquire: boolean
): ChildProcessWithoutNullStreams {
	const moduleUrl = pathToFileURL(
		resolvePath(
			process.cwd(),
			'tests/e2e/utils/woopayments-native/resource-locks.ts'
		)
	).href;
	const source = `
		import { ResourceLockManager } from ${ JSON.stringify( moduleUrl ) };
		const request = ${ JSON.stringify( accountRequest ) };
		const manager = new ResourceLockManager( {
			lockDir: ${ JSON.stringify( lockDir ) },
			runId: ${ JSON.stringify( runId ) },
			leaseMs: ${ leaseMs },
			renewEveryMs: ${ Math.max( 10, Math.floor( leaseMs / 3 ) ) },
			autoRenew: ${ JSON.stringify( autoRenew ) },
		} );
		const lock = await manager.acquire( request );
		process.stdout.write( JSON.stringify( { event: 'acquired', payload: lock.payload } ) + '\\n' );
		if ( ${ JSON.stringify( exitAfterAcquire ) } ) {
			process.exit( 0 );
		}
		process.on( 'SIGTERM', async () => {
			await lock.release();
			process.exit( 0 );
		} );
		setInterval( () => {}, 1_000 );
	`;

	return spawn(
		process.execPath,
		[ '--no-warnings', '--input-type=module', '--eval', source ],
		{ stdio: [ 'pipe', 'pipe', 'pipe' ] }
	);
}

async function readWorkerMessage(
	worker: ChildProcessWithoutNullStreams
): Promise< { event: string; payload: { runId: string } } > {
	let stderr = '';
	worker.stderr.setEncoding( 'utf8' );
	worker.stderr.on( 'data', ( chunk: string ) => {
		stderr += chunk;
	} );
	const lines = createInterface( { input: worker.stdout } );
	for await ( const line of lines ) {
		if ( line.trim() ) {
			lines.close();
			return JSON.parse( line ) as {
				event: string;
				payload: { runId: string };
			};
		}
	}
	throw new Error( `Lock worker exited without a message: ${ stderr }` );
}

async function stopWorker(
	worker: ChildProcessWithoutNullStreams
): Promise< void > {
	if ( worker.exitCode !== null ) {
		return;
	}
	worker.kill( 'SIGTERM' );
	await once( worker, 'exit' );
}

async function waitForWorkerExit(
	worker: ChildProcessWithoutNullStreams
): Promise< void > {
	if ( worker.exitCode === null ) {
		await once( worker, 'exit' );
	}
}

async function waitForMilliseconds( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

test( 'atomically excludes a second process from the same resource', async () => {
	const directory = await lockDirectory();
	const first = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-first',
		pid: 1001,
		maxWaitMs: 0,
	} );
	const second = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-second',
		pid: 1002,
		maxWaitMs: 0,
	} );

	try {
		const lock = await first.acquire( accountRequest );

		await expect( second.acquire( accountRequest ) ).rejects.toThrow(
			/timed out/i
		);
		await lock.release();
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'excludes and renews the same resource across an actual process', async () => {
	const directory = await lockDirectory();
	const worker = spawnLockWorker(
		directory,
		'run-child-renewing',
		300,
		true,
		false
	);
	const contender = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-parent-contender',
		maxWaitMs: 0,
		autoRenew: false,
	} );

	try {
		const acquired = await readWorkerMessage( worker );
		expect( acquired ).toMatchObject( {
			event: 'acquired',
			payload: { runId: 'run-child-renewing' },
		} );
		await waitForMilliseconds( 450 );
		await expect( contender.acquire( accountRequest ) ).rejects.toThrow(
			/timed out/i
		);

		await stopWorker( worker );
		const lock = await contender.acquire( accountRequest );
		await expect( lock.release() ).resolves.toBe( true );
	} finally {
		await stopWorker( worker );
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'recovers an expired lock left by a terminated process', async () => {
	const directory = await lockDirectory();
	const worker = spawnLockWorker(
		directory,
		'run-child-terminated',
		100,
		false,
		true
	);

	try {
		const acquired = await readWorkerMessage( worker );
		expect( acquired.payload.runId ).toBe( 'run-child-terminated' );
		await waitForWorkerExit( worker );

		const manager = new ResourceLockManager( {
			lockDir: directory,
			runId: 'run-parent-recovery',
			maxWaitMs: 1_000,
			autoRenew: false,
		} );
		const recovered = await manager.acquire( accountRequest );

		expect( recovered.displacedOwner?.runId ).toBe(
			'run-child-terminated'
		);
		await expect( recovered.release() ).resolves.toBe( true );
	} finally {
		await stopWorker( worker );
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'renews an owned lease for another 90 seconds', async () => {
	const directory = await lockDirectory();
	let now = 10_000;
	const manager = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-renew',
		pid: 1003,
		now: () => now,
		autoRenew: false,
	} );

	try {
		const lock = await manager.acquire( accountRequest );
		now = 40_000;

		await lock.renew();

		expect( lock.payload.expiresAt ).toBe( 130_000 );
		await lock.release();
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'recovers only an expired lock and records the displaced owner', async () => {
	const directory = await lockDirectory();
	let now = 1_000;
	const first = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-stale',
		pid: 1004,
		now: () => now,
		autoRenew: false,
	} );

	try {
		await first.acquire( accountRequest );
		now = 91_001;
		const second = new ResourceLockManager( {
			lockDir: directory,
			runId: 'run-recovery',
			pid: 1005,
			now: () => now,
			autoRenew: false,
		} );

		const recovered = await second.acquire( accountRequest );
		const recoveryLog = await readFile( second.recoveryLogPath, 'utf8' );

		expect( recovered.displacedOwner?.runId ).toBe( 'run-stale' );
		expect( recoveryLog ).toContain( '"runId":"run-stale"' );
		await recovered.release();
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'enforces account to store to feature to record acquisition order', async () => {
	const directory = await lockDirectory();
	const manager = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-order',
		pid: 1006,
		autoRenew: false,
	} );

	try {
		const featureLock = await manager.acquire( {
			...accountRequest,
			kind: 'feature-setting',
			resource: 'manual-capture',
		} );

		await expect(
			manager.acquire( {
				...accountRequest,
				kind: 'store',
				resource: 'native-store',
			} )
		).rejects.toThrow( /acquisition order/i );
		await featureLock.release();
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'old owner cannot release or restore after stale recovery', async () => {
	const directory = await lockDirectory();
	let now = 1_000;
	const first = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-old',
		pid: 1007,
		now: () => now,
		autoRenew: false,
	} );

	try {
		const stale = await first.acquire( accountRequest );
		now = 91_001;
		const second = new ResourceLockManager( {
			lockDir: directory,
			runId: 'run-current',
			pid: 1008,
			now: () => now,
			autoRenew: false,
		} );
		const current = await second.acquire( accountRequest );
		let restored = false;

		await expect(
			stale.restoreIfOwned( async () => {
				restored = true;
			} )
		).resolves.toBe( false );
		await expect( stale.release() ).resolves.toBe( false );
		expect( restored ).toBe( false );
		await expect( current.release() ).resolves.toBe( true );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'blocks stale recovery while the current owner restores a setting', async () => {
	const directory = await lockDirectory();
	let now = 1_000;
	const first = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-restoring',
		pid: 1009,
		now: () => now,
		sleep: yieldToPeer,
		leaseMs: 100,
		autoRenew: false,
	} );
	const second = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-waiting',
		pid: 1010,
		now: () => now,
		sleep: yieldToPeer,
		leaseMs: 100,
		autoRenew: false,
	} );

	try {
		const restoring = await first.acquire( accountRequest );
		let restoreFinished = false;
		let recoveredBeforeRestoreFinished = false;
		const waiting: Promise< ResourceLock >[] = [];

		await expect(
			restoring.restoreIfOwned( async () => {
				now = 1_101;
				waiting.push(
					second.acquire( accountRequest ).then( ( lock ) => {
						recoveredBeforeRestoreFinished = ! restoreFinished;
						return lock;
					} )
				);
				await yieldToPeer();
				expect( recoveredBeforeRestoreFinished ).toBe( false );
				restoreFinished = true;
			} )
		).resolves.toBe( true );
		await expect( restoring.release() ).resolves.toBe( true );
		expect( waiting ).toHaveLength( 1 );
		const [ current ] = await Promise.all( waiting );

		expect( recoveredBeforeRestoreFinished ).toBe( false );
		await expect( current.release() ).resolves.toBe( true );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'rejects local use of the CI alias and alias reuse across stores', () => {
	expect( () =>
		assertAccountSeparation(
			{
				storeId: 'native-store',
				accountId: 'acct_local',
				accountAlias: 'ci-provider',
			},
			[
				{
					storeId: 'native-store',
					accountId: 'acct_local',
					accountAlias: 'ci-provider',
				},
			],
			{
				isCI: false,
				ciAccountAlias: 'ci-provider',
				ciAccountId: 'acct_ci',
			}
		)
	).toThrow( /CI account alias/i );

	expect( () =>
		assertAccountSeparation(
			{
				storeId: 'native-store',
				accountId: 'acct_ci',
				accountAlias: 'disguised-local',
			},
			[
				{
					storeId: 'native-store',
					accountId: 'acct_ci',
					accountAlias: 'disguised-local',
				},
			],
			{
				isCI: false,
				ciAccountAlias: 'ci-provider',
				ciAccountId: 'acct_ci',
			}
		)
	).toThrow( /CI account alias or ID/i );

	expect( () =>
		assertAccountSeparation(
			{
				storeId: 'native-store',
				accountId: 'acct_native',
				accountAlias: 'local-shared',
			},
			[
				{
					storeId: 'native-store',
					accountId: 'acct_native',
					accountAlias: 'local-shared',
				},
				{
					storeId: 'client-store',
					accountId: 'acct_client',
					accountAlias: 'local-shared',
				},
			],
			{
				isCI: false,
				ciAccountAlias: 'ci-provider',
				ciAccountId: 'acct_ci',
			}
		)
	).toThrow( /must be unique/i );
} );
