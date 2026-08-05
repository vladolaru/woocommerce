import { spawn, type ChildProcessWithoutNullStreams } from 'node:child_process';
import { once } from 'node:events';
import { readFile, readdir, rm, stat, writeFile } from 'node:fs/promises';
import { join, resolve as resolvePath } from 'node:path';
import { createInterface } from 'node:readline';
import { pathToFileURL } from 'node:url';

import { expect, test } from '@playwright/test';

import {
	assertAccountSeparation,
	assertNoDisplacedProviderLocks,
	type JsonValue,
	ResourceLock,
	ResourceLockManager,
	ResourceQuarantineRequiredError,
	type ResourceLockPayload,
	type ResourceLockRequest,
} from './resource-locks';
import { sha256 } from './durable-fs';
import { quarantineResources } from './resource-quarantine';
import { temporaryLockDirectory, useLockDirectory } from './lock-test-helpers';

const accountRequest: ResourceLockRequest = {
	providerAccountId: 'acct_local',
	storeId: 'native-store',
	kind: 'account',
	resource: 'provider-writes',
	diagnosticPath: 'test-results/run-lock',
};

async function lockDirectory(): Promise< string > {
	return temporaryLockDirectory( 'woopayments-native-lock-test-' );
}

async function acquireFeatureSettingLocks(
	manager: ResourceLockManager
): Promise< {
	account: ResourceLock;
	store: ResourceLock;
	setting: ResourceLock;
} > {
	const account = await manager.acquire( accountRequest );
	const store = await manager.acquire( {
		...accountRequest,
		kind: 'store',
		resource: accountRequest.storeId,
	} );
	const setting = await manager.acquire( {
		...accountRequest,
		kind: 'feature-setting',
		resource: 'manual-capture',
	} );

	return { account, store, setting };
}

async function writeUntrustedRestorationValue(
	lock: ResourceLock,
	value: unknown
): Promise< void > {
	return (
		lock.writeRestorationJournal as unknown as (
			originalValue: unknown
		) => Promise< void >
	 )( value );
}

function nestedArray( depth: number ): unknown[] {
	let value: unknown[] = [];
	for ( let index = 0; index < depth; index += 1 ) {
		value = [ value ];
	}
	return value;
}

async function yieldToPeer(): Promise< void > {
	return new Promise( ( resolve ) => setImmediate( resolve ) );
}

function noop(): void {}

test( 'a displaced provider lock owner forces quarantine instead of reuse', () => {
	const displacedKey = 'acct_x/account:provider-writes';
	const displaced = {
		payload: { key: displacedKey },
		displacedOwner: {
			key: displacedKey,
			runId: 'dead-run',
			pid: 4242,
			acquiredAt: 1,
			expiresAt: 2,
			diagnosticPath: 'test-results/dead-run',
		},
	} as unknown as ResourceLock;
	const clean = {
		payload: { key: 'store-1/store:store-1' },
	} as unknown as ResourceLock;

	let quarantineError: unknown;
	try {
		assertNoDisplacedProviderLocks( [ clean, displaced ] );
	} catch ( error ) {
		quarantineError = error;
	}

	expect( quarantineError ).toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( quarantineError ).toMatchObject( {
		reasonCode: 'uncertain-provider-write',
	} );
	expect( ( quarantineError as Error ).message ).toContain(
		`sha256:${ sha256( displacedKey ) }`
	);
	expect( ( quarantineError as Error ).message ).not.toContain(
		displacedKey
	);
	expect( () => assertNoDisplacedProviderLocks( [ clean ] ) ).not.toThrow();
} );

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

function spawnGuardWorker(
	lockDir: string,
	runId: string,
	leaseMs: number
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
			mutationGuardLeaseMs: ${ leaseMs },
			autoRenew: false,
		} );
		const lock = await manager.acquire( request );
		await lock.restoreIfOwned( async () => {
			process.stdout.write( JSON.stringify( { event: 'guard-held', payload: lock.payload } ) + '\\n' );
			await new Promise( () => {} );
		} );
	`;

	return spawn(
		process.execPath,
		[ '--no-warnings', '--input-type=module', '--eval', source ],
		{ stdio: [ 'pipe', 'pipe', 'pipe' ] }
	);
}

function spawnJournalWorker(
	lockDir: string,
	statePath: string,
	runId: string,
	leaseMs: number
): ChildProcessWithoutNullStreams {
	const moduleUrl = pathToFileURL(
		resolvePath(
			process.cwd(),
			'tests/e2e/utils/woopayments-native/resource-locks.ts'
		)
	).href;
	const storeRequest = {
		...accountRequest,
		kind: 'store',
		resource: accountRequest.storeId,
	};
	const settingRequest = {
		...accountRequest,
		kind: 'feature-setting',
		resource: 'manual-capture',
	};
	const source = `
		import { writeFile } from 'node:fs/promises';
		import { ResourceLockManager } from ${ JSON.stringify( moduleUrl ) };
		const manager = new ResourceLockManager( {
			lockDir: ${ JSON.stringify( lockDir ) },
			runId: ${ JSON.stringify( runId ) },
			leaseMs: ${ leaseMs },
			mutationGuardLeaseMs: ${ leaseMs },
			autoRenew: false,
		} );
		const account = await manager.acquire( ${ JSON.stringify( accountRequest ) } );
		const store = await manager.acquire( ${ JSON.stringify( storeRequest ) } );
		const setting = await manager.acquire( ${ JSON.stringify( settingRequest ) } );
		await setting.writeRestorationJournal( false );
		await writeFile( ${ JSON.stringify( statePath ) }, 'true' );
		process.stdout.write( JSON.stringify( {
			event: 'setting-enabled',
			payload: setting.payload,
			lockPayloads: [ account.payload, store.payload, setting.payload ],
		} ) + '\\n' );
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
): Promise< {
	event: string;
	payload: ResourceLockPayload;
	lockPayloads?: ResourceLockPayload[];
} > {
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
				payload: ResourceLockPayload;
				lockPayloads?: ResourceLockPayload[];
			};
		}
	}
	throw new Error( `Lock worker exited without a message: ${ stderr }` );
}

async function stopWorker(
	worker: ChildProcessWithoutNullStreams
): Promise< void > {
	if ( worker.exitCode !== null || worker.signalCode !== null ) {
		return;
	}
	worker.kill( 'SIGTERM' );
	await once( worker, 'exit' );
}

async function waitForWorkerExit(
	worker: ChildProcessWithoutNullStreams
): Promise< void > {
	if ( worker.exitCode === null && worker.signalCode === null ) {
		await once( worker, 'exit' );
	}
}

async function forceStopWorker(
	worker: ChildProcessWithoutNullStreams
): Promise< void > {
	if ( worker.exitCode === null && worker.signalCode === null ) {
		worker.kill( 'SIGKILL' );
		await waitForWorkerExit( worker );
	}
}

function getPeerAttempt< Result >(
	attempts: Promise< Result >[],
	firstIndex: number
): Promise< Result > {
	return attempts[ firstIndex === 0 ? 1 : 0 ];
}

async function waitForMilliseconds( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

async function expireExactLockPayloads(
	lockDir: string,
	payloads: ResourceLockPayload[]
): Promise< void > {
	for ( const payload of payloads ) {
		const lockPath = join( lockDir, `${ sha256( payload.key ) }.lock` );
		const current = JSON.parse(
			await readFile( lockPath, 'utf8' )
		) as ResourceLockPayload;
		expect( current ).toEqual( payload );
		await writeFile(
			lockPath,
			`${ JSON.stringify( { ...payload, expiresAt: 0 } ) }\n`
		);
	}
}

interface MutationGuardTestPayload {
	ownerId: string;
	pid: number;
	acquiredAt: number;
	expiresAt: number;
}

interface MutationGuardTestManager {
	readMutationGuard(
		guardPath: string
	): Promise< MutationGuardTestPayload | undefined >;
	renewMutationGuard(
		guardPath: string,
		owner: MutationGuardTestPayload
	): Promise< void >;
	releaseMutationGuard(
		guardPath: string,
		owner: MutationGuardTestPayload
	): Promise< void >;
}

interface ResourceLockAcquisitionTestManager {
	tryCreate(
		lockPath: string,
		payload: ResourceLockPayload
	): Promise< boolean >;
	tryCreateMutationGuard(
		guardPath: string,
		owner: MutationGuardTestPayload
	): Promise< boolean >;
}

function pauseSecondTryCreate( manager: ResourceLockManager ): {
	replacementReached: Promise< void >;
	resumeReplacement: () => void;
} {
	const internals = manager as unknown as ResourceLockAcquisitionTestManager;
	const originalTryCreate = internals.tryCreate.bind( manager );
	let callCount = 0;
	let signalReplacementReached: () => void = noop;
	let resumeReplacement: () => void = noop;
	const replacementReached = new Promise< void >( ( resolve ) => {
		signalReplacementReached = resolve;
	} );
	const replacementResumed = new Promise< void >( ( resolve ) => {
		resumeReplacement = resolve;
	} );

	internals.tryCreate = async ( lockPath, payload ) => {
		callCount += 1;
		if ( callCount === 2 ) {
			signalReplacementReached();
			await replacementResumed;
		}
		return originalTryCreate( lockPath, payload );
	};

	return { replacementReached, resumeReplacement };
}

function observeFreshAcquisitionArbitration(
	manager: ResourceLockManager
): Promise< 'canonical-created' | 'guard-contended' > {
	const internals = manager as unknown as ResourceLockAcquisitionTestManager;
	const originalTryCreate = internals.tryCreate.bind( manager );
	const originalTryCreateMutationGuard =
		internals.tryCreateMutationGuard.bind( manager );
	let signalCanonicalCreated: () => void = noop;
	let signalGuardContended: () => void = noop;
	const canonicalCreated = new Promise< void >( ( resolve ) => {
		signalCanonicalCreated = resolve;
	} );
	const guardContended = new Promise< void >( ( resolve ) => {
		signalGuardContended = resolve;
	} );

	internals.tryCreate = async ( lockPath, payload ) => {
		const created = await originalTryCreate( lockPath, payload );
		if ( created ) {
			signalCanonicalCreated();
		}
		return created;
	};
	internals.tryCreateMutationGuard = async ( guardPath, owner ) => {
		const created = await originalTryCreateMutationGuard(
			guardPath,
			owner
		);
		if ( ! created ) {
			signalGuardContended();
		}
		return created;
	};

	return Promise.race( [
		canonicalCreated.then( () => 'canonical-created' as const ),
		guardContended.then( () => 'guard-contended' as const ),
	] );
}

async function settleAcquisitionRace(
	arbitration: Promise< 'canonical-created' | 'guard-contended' >,
	resumeReplacement: () => void,
	recovererAttempt: Promise< ResourceLock >,
	freshAttempt: Promise< ResourceLock >,
	isFreshSettled: () => boolean
): Promise< {
	arbitration: 'canonical-created' | 'guard-contended';
	recovered?: ResourceLock;
	recovererError?: unknown;
	fresh: ResourceLock;
	freshSettledBeforeRecoveryRelease: boolean;
} > {
	const observed = await arbitration;
	resumeReplacement();
	const recovererOutcome = await recovererAttempt.then(
		( lock ) => ( { lock } ),
		( error: unknown ) => ( { error } )
	);
	if ( 'error' in recovererOutcome ) {
		const fresh = await freshAttempt;
		await fresh.release();
		return {
			arbitration: observed,
			recovererError: recovererOutcome.error,
			fresh,
			freshSettledBeforeRecoveryRelease: isFreshSettled(),
		};
	}

	const freshSettledBeforeRecoveryRelease = isFreshSettled();
	await recovererOutcome.lock.release();
	const fresh = await freshAttempt;
	await fresh.release();
	return {
		arbitration: observed,
		recovered: recovererOutcome.lock,
		fresh,
		freshSettledBeforeRecoveryRelease,
	};
}

async function expectStaleGuardOperationBlocked(
	manager: ResourceLockManager,
	internals: MutationGuardTestManager,
	guardPath: string,
	staleOwner: MutationGuardTestPayload,
	recoveredOwner: MutationGuardTestPayload,
	operation: (
		guardPath: string,
		owner: MutationGuardTestPayload
	) => Promise< void >
): Promise< void > {
	const originalRead = internals.readMutationGuard.bind( manager );
	await writeFile( guardPath, `${ JSON.stringify( staleOwner ) }\n` );
	let replaced = false;
	internals.readMutationGuard = async ( path ) => {
		const current = await originalRead( path );
		if ( ! replaced ) {
			replaced = true;
			await writeFile(
				guardPath,
				`${ JSON.stringify( recoveredOwner ) }\n`
			);
		}
		return current;
	};

	await expect( operation( guardPath, staleOwner ) ).rejects.toThrow(
		/ownership was lost/i
	);
	expect(
		JSON.parse(
			await readFile( guardPath, 'utf8' )
		) as MutationGuardTestPayload
	).toEqual( recoveredOwner );
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

test( 'excludes the same provider account across different stores and processes', async () => {
	const directory = await lockDirectory();
	const worker = spawnLockWorker(
		directory,
		'run-account-store-a',
		1_000,
		true,
		false
	);
	const contender = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-account-store-b',
		maxWaitMs: 0,
		autoRenew: false,
	} );

	try {
		await readWorkerMessage( worker );
		await expect(
			contender.acquire( {
				...accountRequest,
				storeId: 'different-store',
			} )
		).rejects.toThrow( /timed out/i );
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

test( 'serializes fresh acquisition while an expired lock is being replaced', async () => {
	const directory = await lockDirectory();
	let resumeReplacement = noop;
	const expiredManager = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-expired-owner',
		leaseMs: 10,
		autoRenew: false,
	} );
	const recoverer = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-expired-recoverer',
		leaseMs: 1_000,
		maxWaitMs: 1_000,
		autoRenew: false,
	} );
	const freshContender = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-fresh-contender',
		leaseMs: 1_000,
		maxWaitMs: 1_000,
		autoRenew: false,
	} );

	try {
		await expiredManager.acquire( accountRequest );
		await waitForMilliseconds( 20 );

		const pause = pauseSecondTryCreate( recoverer );
		resumeReplacement = pause.resumeReplacement;
		const recovererAttempt = recoverer.acquire( accountRequest );
		void recovererAttempt.catch( () => {} );
		await pause.replacementReached;

		let freshSettled = false;
		const arbitration =
			observeFreshAcquisitionArbitration( freshContender );
		const freshAttempt = freshContender
			.acquire( accountRequest )
			.then( ( lock ) => {
				freshSettled = true;
				return lock;
			} );
		const outcome = await settleAcquisitionRace(
			arbitration,
			pause.resumeReplacement,
			recovererAttempt,
			freshAttempt,
			() => freshSettled
		);
		expect( outcome.arbitration ).toBe( 'guard-contended' );
		expect( outcome.recovererError ).toBeUndefined();
		expect( outcome.recovered?.displacedOwner?.runId ).toBe(
			'run-expired-owner'
		);
		expect( outcome.freshSettledBeforeRecoveryRelease ).toBe( false );
		expect( outcome.fresh.displacedOwner ).toBeUndefined();

		const staleFiles = ( await readdir( directory ) ).filter( ( file ) =>
			file.includes( '.stale-' )
		);
		expect( staleFiles ).toHaveLength( 1 );
		expect(
			JSON.parse(
				await readFile( join( directory, staleFiles[ 0 ] ), 'utf8' )
			) as ResourceLockPayload
		).toMatchObject( { runId: 'run-expired-owner' } );
		const recoveryLog = await readFile( recoverer.recoveryLogPath, 'utf8' );
		expect( recoveryLog.match( /"displacedOwner"/g ) ).toHaveLength( 1 );
	} finally {
		resumeReplacement();
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'recovers after a worker is killed while holding the mutation guard', async () => {
	const directory = await lockDirectory();
	const worker = spawnGuardWorker( directory, 'run-killed-guard', 100 );

	try {
		const held = await readWorkerMessage( worker );
		expect( held.event ).toBe( 'guard-held' );
		worker.kill( 'SIGKILL' );
		await waitForWorkerExit( worker );
		await waitForMilliseconds( 250 );

		const contender = new ResourceLockManager( {
			lockDir: directory,
			runId: 'run-after-killed-guard',
			leaseMs: 100,
			mutationGuardLeaseMs: 100,
			maxWaitMs: 1_000,
			autoRenew: false,
		} );
		const recovered = await contender.acquire( accountRequest );
		expect( recovered.displacedOwner?.runId ).toBe( 'run-killed-guard' );
		await expect( recovered.renew() ).resolves.toBeUndefined();
		await expect( recovered.release() ).resolves.toBe( true );
	} finally {
		await forceStopWorker( worker );
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'allows only one contender to recover a stale mutation guard at a time', async () => {
	const directory = await lockDirectory();
	const worker = spawnGuardWorker( directory, 'run-stale-guard-race', 100 );

	try {
		await readWorkerMessage( worker );
		worker.kill( 'SIGKILL' );
		await waitForWorkerExit( worker );
		await waitForMilliseconds( 250 );

		const managers = [ 'a', 'b' ].map(
			( suffix, index ) =>
				new ResourceLockManager( {
					lockDir: directory,
					runId: `run-guard-contender-${ suffix }`,
					pid: 2_000 + index,
					leaseMs: 500,
					mutationGuardLeaseMs: 100,
					maxWaitMs: 1_000,
					autoRenew: false,
				} )
		);
		const attempts = managers.map( async ( manager, index ) => ( {
			index,
			lock: await manager.acquire( accountRequest ),
		} ) );
		const first = await Promise.race( attempts );

		expect( first.lock.displacedOwner?.runId ).toBe(
			'run-stale-guard-race'
		);
		await expect( first.lock.release() ).resolves.toBe( true );
		const second = await getPeerAttempt( attempts, first.index );
		expect( second.lock.displacedOwner ).toBeUndefined();
		await expect( second.lock.release() ).resolves.toBe( true );
	} finally {
		await forceStopWorker( worker );
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'a resumed stale guard owner cannot clobber or delete a recovered guard', async () => {
	const directory = await lockDirectory();
	const guardPath = join( directory, 'interleaving.mutation' );
	const manager = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-stale-owner-interleaving',
		mutationGuardLeaseMs: 100,
		autoRenew: false,
	} );
	const internals = manager as unknown as MutationGuardTestManager;
	const staleOwner: MutationGuardTestPayload = {
		ownerId: 'stale-owner',
		pid: process.pid,
		acquiredAt: 100,
		expiresAt: 200,
	};
	const recoveredOwner: MutationGuardTestPayload = {
		ownerId: 'recovered-owner',
		pid: process.pid,
		acquiredAt: 300,
		expiresAt: 400,
	};

	try {
		await expectStaleGuardOperationBlocked(
			manager,
			internals,
			guardPath,
			staleOwner,
			recoveredOwner,
			internals.renewMutationGuard.bind( manager )
		);
		await expectStaleGuardOperationBlocked(
			manager,
			internals,
			guardPath,
			staleOwner,
			recoveredOwner,
			internals.releaseMutationGuard.bind( manager )
		);
		expect(
			JSON.parse(
				await readFile( guardPath, 'utf8' )
			) as MutationGuardTestPayload
		).toEqual( recoveredOwner );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'restores a durable original setting after the enabling worker is killed', async () => {
	const directory = await lockDirectory();
	const statePath = join( directory, 'manual-capture-state.txt' );
	await writeFile( statePath, 'false' );
	const worker = spawnJournalWorker(
		directory,
		statePath,
		'run-setting-crash',
		30_000
	);

	try {
		const enabled = await readWorkerMessage( worker );
		expect( enabled.event ).toBe( 'setting-enabled' );
		expect( enabled.lockPayloads ).toHaveLength( 3 );
		expect( await readFile( statePath, 'utf8' ) ).toBe( 'true' );
		const journalPath = ( await readdir( directory ) ).find( ( file ) =>
			file.endsWith( '.restoration.json' )
		);
		expect( journalPath ).toBeTruthy();
		const journal = await readFile(
			join( directory, journalPath as string ),
			'utf8'
		);
		expect( journal ).not.toContain( accountRequest.providerAccountId );
		expect( journal ).not.toContain( accountRequest.storeId );

		worker.kill( 'SIGKILL' );
		await waitForWorkerExit( worker );
		await expireExactLockPayloads(
			directory,
			enabled.lockPayloads as ResourceLockPayload[]
		);

		const manager = new ResourceLockManager( {
			lockDir: directory,
			runId: 'run-setting-recovery',
			leaseMs: 30_000,
			mutationGuardLeaseMs: 30_000,
			maxWaitMs: 1_000,
			autoRenew: false,
		} );
		const account = await manager.acquire( accountRequest );
		const store = await manager.acquire( {
			...accountRequest,
			kind: 'store',
			resource: accountRequest.storeId,
		} );
		const setting = await manager.acquire( {
			...accountRequest,
			kind: 'feature-setting',
			resource: 'manual-capture',
		} );
		for ( const lock of [ account, store, setting ] ) {
			expect( lock.displacedOwner?.runId ).toBe( 'run-setting-crash' );
		}
		let recoveredOriginal: boolean | undefined;

		await expect(
			setting.restoreFromJournalIfOwned( async ( original ) => {
				recoveredOriginal = original;
				await writeFile( statePath, String( original ) );
			} )
		).resolves.toBe( true );
		expect( recoveredOriginal ).toBe( false );
		expect( await readFile( statePath, 'utf8' ) ).toBe( 'false' );

		const newBaseline = ( await readFile( statePath, 'utf8' ) ) === 'true';
		await setting.writeRestorationJournal( newBaseline );
		await writeFile( statePath, 'true' );
		await expect(
			setting.restoreFromJournalIfOwned( async ( original ) => {
				await writeFile( statePath, String( original ) );
			} )
		).resolves.toBe( true );
		expect( await readFile( statePath, 'utf8' ) ).toBe( 'false' );

		await expect( setting.release() ).resolves.toBe( true );
		await expect( store.release() ).resolves.toBe( true );
		await expect( account.release() ).resolves.toBe( true );
	} finally {
		await forceStopWorker( worker );
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'persists and recovers a nested JSON setting snapshot after stale takeover', async () => {
	const directory = await lockDirectory();
	let now = 1_000;
	const originalSnapshot = {
		accountOption: {
			exists: true,
			valueBase64: 'YTozOntzOjQ6ImRhdGEiO2I6MDt9',
			autoload: 'off',
		},
		forceOption: {
			exists: false,
			valueBase64: null,
			autoload: null,
		},
		page: {
			slug: 'classic-checkout',
			marker: 'run-nested-json',
			postId: null,
		},
		flags: [ true, false, 10.99 ],
	} satisfies JsonValue;
	const first = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-nested-json-writer',
		pid: 1101,
		now: () => now,
		leaseMs: 100,
		mutationGuardLeaseMs: 100,
		autoRenew: false,
	} );

	try {
		const firstLocks = await acquireFeatureSettingLocks( first );
		await firstLocks.setting.writeRestorationJournal( originalSnapshot );
		const journalFile = ( await readdir( directory ) ).find( ( file ) =>
			file.endsWith( '.restoration.json' )
		);
		expect( journalFile ).toBeDefined();
		const journalPath = join( directory, journalFile as string );
		const persisted = JSON.parse(
			await readFile( journalPath, 'utf8' )
		) as { originalValue: unknown };
		expect( persisted.originalValue ).toEqual( originalSnapshot );
		expect( ( await stat( journalPath ) ).mode % 0o1000 ).toBe( 0o600 );

		now = 1_101;
		const recoverer = new ResourceLockManager( {
			lockDir: directory,
			runId: 'run-nested-json-recoverer',
			pid: 1102,
			now: () => now,
			leaseMs: 500,
			mutationGuardLeaseMs: 100,
			maxWaitMs: 1_000,
			autoRenew: false,
		} );
		const recoveredLocks = await acquireFeatureSettingLocks( recoverer );
		let recoveredSnapshot: unknown;

		await expect(
			recoveredLocks.setting.restoreFromJournalIfOwned(
				async ( originalValue: JsonValue ) => {
					recoveredSnapshot = originalValue;
				}
			)
		).resolves.toBe( true );
		expect( recoveredSnapshot ).toEqual( originalSnapshot );
		await expect( readFile( journalPath, 'utf8' ) ).rejects.toMatchObject( {
			code: 'ENOENT',
		} );

		await expect( recoveredLocks.setting.release() ).resolves.toBe( true );
		await expect( recoveredLocks.store.release() ).resolves.toBe( true );
		await expect( recoveredLocks.account.release() ).resolves.toBe( true );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

const invalidRestorationValues: Array< {
	name: string;
	create: () => unknown;
} > = [
	{ name: 'undefined', create: () => undefined },
	{
		name: 'a function',
		create: () => ( { nested: () => false } ),
	},
	{
		name: 'a symbol',
		create: () => ( { nested: Symbol( 'not-json' ) } ),
	},
	{
		name: 'a bigint',
		create: () => ( { nested: BigInt( 1 ) } ),
	},
	{ name: 'NaN', create: () => ( { nested: Number.NaN } ) },
	{
		name: 'positive infinity',
		create: () => Number.POSITIVE_INFINITY,
	},
	{
		name: 'negative infinity',
		create: () => Number.NEGATIVE_INFINITY,
	},
	{
		name: 'a cycle',
		create: () => {
			const cyclic: unknown[] = [];
			cyclic.push( cyclic );
			return cyclic;
		},
	},
	{
		name: 'an unsafe prototype',
		create: () =>
			Object.assign( Object.create( { inherited: true } ), {
				privateSnapshotMarker: 'must-not-appear-in-errors',
			} ),
	},
	{
		name: 'a non-plain object',
		create: () => new Date( '2026-08-05T00:00:00.000Z' ),
	},
	{
		name: 'excessive nesting',
		create: () => nestedArray( 100 ),
	},
	{
		name: 'a serialized UTF-8 value above 64 KiB',
		create: () => ( { nested: '💳'.repeat( 16_384 ) } ),
	},
];

for ( const invalid of invalidRestorationValues ) {
	test( `rejects ${ invalid.name } before writing a restoration journal`, async () => {
		const directory = await lockDirectory();
		const manager = new ResourceLockManager( {
			lockDir: directory,
			runId: `run-reject-${ invalid.name }`,
			autoRenew: false,
		} );

		try {
			const { setting } = await acquireFeatureSettingLocks( manager );
			let rejection: unknown;
			try {
				await writeUntrustedRestorationValue(
					setting,
					invalid.create()
				);
			} catch ( error ) {
				rejection = error;
			}

			expect( rejection ).toBeInstanceOf( Error );
			expect( ( rejection as Error ).message ).toMatch(
				/invalid restoration journal value/i
			);
			expect( ( rejection as Error ).message ).not.toContain(
				'must-not-appear-in-errors'
			);
			expect(
				( await readdir( directory ) ).filter( ( file ) =>
					file.endsWith( '.restoration.json' )
				)
			).toEqual( [] );
		} finally {
			await rm( directory, { recursive: true, force: true } );
		}
	} );
}

test( 'revalidates bounded JSON values read from a restoration journal', async () => {
	const directory = await lockDirectory();
	const manager = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-revalidate-journal',
		autoRenew: false,
	} );

	try {
		const { setting } = await acquireFeatureSettingLocks( manager );
		await setting.writeRestorationJournal( false );
		const journalFile = ( await readdir( directory ) ).find( ( file ) =>
			file.endsWith( '.restoration.json' )
		);
		expect( journalFile ).toBeDefined();
		const journalPath = join( directory, journalFile as string );
		const journalText = await readFile( journalPath, 'utf8' );
		const journal = JSON.parse( journalText ) as Record< string, unknown >;
		const tamperedJournalValues = [
			JSON.stringify( {
				...journal,
				originalValue: nestedArray( 100 ),
			} ),
			JSON.stringify( {
				...journal,
				originalValue: { nested: '💳'.repeat( 16_384 ) },
			} ),
			journalText.replace(
				'"originalValue":false',
				'"originalValue":1e400'
			),
		];

		for ( const tamperedJournal of tamperedJournalValues ) {
			await writeFile( journalPath, `${ tamperedJournal }\n` );
			let restoreCalled = false;
			await expect(
				setting.restoreFromJournalIfOwned( async () => {
					restoreCalled = true;
				} )
			).rejects.toThrow( /invalid restoration journal value/i );
			expect( restoreCalled ).toBe( false );
		}
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'redacts malformed restoration journal values from syntax errors', async () => {
	const directory = await lockDirectory();
	const manager = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-redact-malformed-journal',
		autoRenew: false,
	} );
	const privateMarker = 'PVT42';

	try {
		const { setting } = await acquireFeatureSettingLocks( manager );
		await setting.writeRestorationJournal( false );
		const journalFile = ( await readdir( directory ) ).find( ( file ) =>
			file.endsWith( '.restoration.json' )
		);
		expect( journalFile ).toBeDefined();
		const journalPath = join( directory, journalFile as string );
		await writeFile(
			journalPath,
			`{"originalValue":${ privateMarker }}\n`
		);
		let restoreCalled = false;
		let rejection: unknown;

		try {
			await setting.restoreFromJournalIfOwned( async () => {
				restoreCalled = true;
			} );
		} catch ( error ) {
			rejection = error;
		}

		expect( restoreCalled ).toBe( false );
		expect( rejection ).toBeInstanceOf( Error );
		expect( ( rejection as Error ).message ).not.toContain( privateMarker );
		expect( ( rejection as Error ).message ).toBe(
			`Invalid restoration journal payload: ${ journalPath }`
		);
	} finally {
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

test( 'atomically replaces the published lock record during renewal', async () => {
	const directory = await lockDirectory();
	const manager = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-atomic-renew',
		pid: 1003,
		autoRenew: false,
	} );

	try {
		const lock = await manager.acquire( accountRequest );
		const lockFile = ( await readdir( directory ) ).find( ( file ) =>
			file.endsWith( '.lock' )
		);
		expect( lockFile ).toBeDefined();
		const lockPath = join( directory, lockFile as string );
		const before = await stat( lockPath );

		await lock.renew();

		const after = await stat( lockPath );
		expect( after.ino ).not.toBe( before.ino );
		await expect( lock.isOwned() ).resolves.toBe( true );
		await lock.release();
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'surfaces a background renewal failure before reporting ownership', async () => {
	let renewalFailed!: () => void;
	const renewalAttempt = new Promise< void >( ( resolve ) => {
		renewalFailed = resolve;
	} );
	const manager = {
		isOwned: async () => true,
		renew: async () => {
			renewalFailed();
			throw new Error( 'Background lease renewal failed.' );
		},
	} as unknown as ResourceLockManager;
	const lock = new ResourceLock( manager, {
		key: accountRequest.providerAccountId,
		runId: 'run-renewal-failure',
		pid: 1003,
		acquiredAt: 1_000,
		expiresAt: 91_000,
		diagnosticPath: accountRequest.diagnosticPath,
	} );

	lock.startRenewal( 1 );
	await renewalAttempt;
	await yieldToPeer();

	await expect( lock.isOwned() ).rejects.toThrow(
		/Background lease renewal failed/
	);
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

test( 'rejects a store lock when the matching account lock is not owned', async () => {
	const directory = await lockDirectory();
	const manager = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-order',
		pid: 1006,
		autoRenew: false,
	} );

	try {
		await expect(
			manager.acquire( {
				...accountRequest,
				kind: 'store',
				resource: 'native-store',
			} )
		).rejects.toThrow( /requires.*account/i );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'rejects setting and record locks without matching account and store ownership', async () => {
	const directory = await lockDirectory();
	const manager = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-prerequisites',
		pid: 1011,
		autoRenew: false,
	} );

	try {
		await expect(
			manager.acquire( {
				...accountRequest,
				kind: 'feature-setting',
				resource: 'manual-capture',
			} )
		).rejects.toThrow( /requires.*account.*store/i );
		await expect(
			manager.acquire( {
				...accountRequest,
				kind: 'record-event',
				resource: 'order-42',
			} )
		).rejects.toThrow( /requires.*account.*store/i );

		const accountLock = await manager.acquire( accountRequest );
		await expect(
			manager.acquire( {
				...accountRequest,
				kind: 'feature-setting',
				resource: 'manual-capture',
			} )
		).rejects.toThrow( /requires.*store/i );

		const storeLock = await manager.acquire( {
			...accountRequest,
			kind: 'store',
			resource: 'native-store',
		} );
		const featureLock = await manager.acquire( {
			...accountRequest,
			kind: 'feature-setting',
			resource: 'manual-capture',
		} );
		const recordLock = await manager.acquire( {
			...accountRequest,
			kind: 'record-event',
			resource: 'order-42',
		} );

		await expect( recordLock.release() ).resolves.toBe( true );
		await expect( featureLock.release() ).resolves.toBe( true );
		await expect( storeLock.release() ).resolves.toBe( true );
		await expect( accountLock.release() ).resolves.toBe( true );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'rejects a lower hierarchy lock after a higher hierarchy lock', async () => {
	const directory = await lockDirectory();
	const manager = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-order-regression',
		pid: 1012,
		autoRenew: false,
	} );

	try {
		const accountLock = await manager.acquire( accountRequest );
		const storeLock = await manager.acquire( {
			...accountRequest,
			kind: 'store',
			resource: 'native-store',
		} );
		const recordLock = await manager.acquire( {
			...accountRequest,
			kind: 'record-event',
			resource: 'order-42',
		} );

		await expect(
			manager.acquire( {
				...accountRequest,
				kind: 'feature-setting',
				resource: 'manual-capture',
			} )
		).rejects.toThrow( /acquisition order/i );

		await expect( recordLock.release() ).resolves.toBe( true );
		await expect( storeLock.release() ).resolves.toBe( true );
		await expect( accountLock.release() ).resolves.toBe( true );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'a second run cannot acquire the same quarantined account', async () => {
	const directory = await lockDirectory();
	const restoreLockDirectory = useLockDirectory( directory );

	try {
		await quarantineResources(
			[ 'acct_local/account:provider-writes' ],
			'cleanup-failed',
			'test-results/run-first'
		);
		const second = new ResourceLockManager( {
			lockDir: directory,
			runId: 'run-second',
			autoRenew: false,
		} );

		await expect( second.acquire( accountRequest ) ).rejects.toThrow(
			/quarantined/i
		);
	} finally {
		restoreLockDirectory();
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'a waiter rejects quarantine created while it waits for a resource', async () => {
	const directory = await lockDirectory();
	let resumeWaiter: () => void = noop;
	let waiterIsWaiting: () => void = noop;
	const waiterWaiting = new Promise< void >( ( resolve ) => {
		waiterIsWaiting = resolve;
	} );
	const waiterMayRetry = new Promise< void >( ( resolve ) => {
		resumeWaiter = resolve;
	} );
	const first = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-first',
		autoRenew: false,
	} );
	const second = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-second',
		autoRenew: false,
		maxWaitMs: 1_000,
		sleep: async () => {
			waiterIsWaiting();
			await waiterMayRetry;
		},
	} );

	try {
		const held = await first.acquire( accountRequest );
		const waiting = second.acquire( accountRequest );
		await waiterWaiting;
		await quarantineResources(
			[ 'acct_local/account:provider-writes' ],
			'cleanup-failed',
			'test-results/run-first',
			directory
		);
		await held.release();
		resumeWaiter();

		await expect( waiting ).rejects.toThrow( /quarantined/i );
		expect(
			( await readdir( directory ) ).filter( ( file ) =>
				file.endsWith( '.lock' )
			)
		).toEqual( [] );
	} finally {
		resumeWaiter();
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'an explicit lock directory rejects its quarantined account without an environment lock directory', async () => {
	const directory = await lockDirectory();
	const restoreLockDirectory = useLockDirectory( directory );

	try {
		await quarantineResources(
			[ 'acct_local/account:provider-writes' ],
			'cleanup-failed',
			'test-results/run-first'
		);
		delete process.env.E2E_WOOPAYMENTS_LOCK_DIR;
		const second = new ResourceLockManager( {
			lockDir: directory,
			runId: 'run-second',
			autoRenew: false,
		} );

		await expect( second.acquire( accountRequest ) ).rejects.toThrow(
			/quarantined/i
		);
	} finally {
		restoreLockDirectory();
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'a disjoint account/store remains usable', async () => {
	const directory = await lockDirectory();
	const restoreLockDirectory = useLockDirectory( directory );

	try {
		await quarantineResources(
			[ 'acct_local/account:provider-writes' ],
			'cleanup-failed',
			'test-results/run-first'
		);
		const disjoint = new ResourceLockManager( {
			lockDir: directory,
			runId: 'run-disjoint',
			autoRenew: false,
		} );
		const lock = await disjoint.acquire( {
			...accountRequest,
			providerAccountId: 'acct_disjoint',
			storeId: 'disjoint-store',
		} );

		await expect( lock.release() ).resolves.toBe( true );
	} finally {
		restoreLockDirectory();
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

test( 'allows a local-only allocation manifest without inventing a CI identity', () => {
	expect( () =>
		assertAccountSeparation(
			{
				storeId: 'native-store',
				accountId: 'acct_native',
				accountAlias: 'local-native',
			},
			[
				{
					storeId: 'native-store',
					accountId: 'acct_native',
					accountAlias: 'local-native',
				},
				{
					storeId: 'client-store',
					accountId: 'acct_client',
					accountAlias: 'local-client',
				},
			],
			{
				isCI: false,
			}
		)
	).not.toThrow();
} );

test( 'requires the protected account identity for CI execution', () => {
	expect( () =>
		assertAccountSeparation(
			{
				storeId: 'ci-store',
				accountId: 'acct_ci',
				accountAlias: 'ci-provider',
			},
			[
				{
					storeId: 'ci-store',
					accountId: 'acct_ci',
					accountAlias: 'ci-provider',
				},
			],
			{
				isCI: true,
			}
		)
	).toThrow( /CI.*alias.*ID/i );
} );
