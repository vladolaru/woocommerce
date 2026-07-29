import { randomUUID } from 'node:crypto';
import {
	appendFile,
	type FileHandle,
	mkdir,
	open,
	readFile,
	rename,
	unlink,
} from 'node:fs/promises';
import { join } from 'node:path';

// @ts-expect-error Node's direct TypeScript lock workers require the explicit extension.
import {
	sha256,
	syncDirectory,
	writeDurableJson,
	writeNewDurableJson,
} from './durable-fs.ts';
// @ts-expect-error Node's direct TypeScript lock workers require the explicit extension.
import { assertResourcesUsable } from './resource-quarantine.ts';

export type ResourceLockKind =
	| 'account'
	| 'store'
	| 'feature-setting'
	| 'record-event';

export interface ResourceLockRequest {
	providerAccountId: string;
	storeId: string;
	kind: ResourceLockKind;
	resource: string;
	diagnosticPath: string;
}

/**
 * The single definition of a resource lock key. Quarantine receipts are keyed
 * by the SHA-256 of this string, so anything that wants to ask "is this
 * resource quarantined?" must derive the key here rather than hand-encode it —
 * a divergence would silently stop matching receipts instead of failing loudly.
 */
export function resourceLockKey(
	request: Pick<
		ResourceLockRequest,
		'providerAccountId' | 'storeId' | 'kind' | 'resource'
	>
): string {
	if ( request.kind === 'account' ) {
		return `${ request.providerAccountId }/${ request.kind }:${ request.resource }`;
	}
	return `${ request.providerAccountId }/${ request.storeId }/${ request.kind }:${ request.resource }`;
}

export interface ResourceLockPayload {
	key: string;
	runId: string;
	pid: number;
	acquiredAt: number;
	expiresAt: number;
	diagnosticPath: string;
}

export interface StoreAccountAllocation {
	storeId: string;
	accountId: string;
	accountAlias: string;
}

interface ResourceLockManagerOptions {
	lockDir?: string;
	runId: string;
	pid?: number;
	now?: () => number;
	sleep?: ( milliseconds: number ) => Promise< void >;
	leaseMs?: number;
	renewEveryMs?: number;
	mutationGuardLeaseMs?: number;
	maxWaitMs?: number;
	autoRenew?: boolean;
}

interface MutationGuardPayload {
	ownerId: string;
	pid: number;
	acquiredAt: number;
	expiresAt: number;
}

interface RestorationJournalPayload {
	version: 1;
	journalId: string;
	accountHash: string;
	storeHash: string;
	settingHash: string;
	runId: string;
	originalValue: boolean;
	createdAt: number;
}

const LOCK_ORDER: Record< ResourceLockKind, number > = {
	account: 0,
	store: 1,
	'feature-setting': 2,
	'record-event': 3,
};

const DEFAULT_LEASE_MS = 90_000;
const DEFAULT_RENEW_EVERY_MS = 30_000;
const DEFAULT_MAX_WAIT_MS = 120_000;
const RETRY_INTERVAL_MS = 100;

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

function exactOwner(
	left: ResourceLockPayload,
	right: ResourceLockPayload
): boolean {
	return (
		left.key === right.key &&
		left.runId === right.runId &&
		left.pid === right.pid &&
		left.acquiredAt === right.acquiredAt
	);
}

function exactGuardOwner(
	left: MutationGuardPayload,
	right: MutationGuardPayload
): boolean {
	return (
		left.ownerId === right.ownerId &&
		left.pid === right.pid &&
		left.acquiredAt === right.acquiredAt
	);
}

export function assertAccountSeparation(
	current: StoreAccountAllocation,
	allocations: StoreAccountAllocation[],
	options: {
		isCI: boolean;
		ciAccountAlias?: string;
		ciAccountId?: string;
	}
): void {
	if ( ! current.accountId || ! current.accountAlias || ! current.storeId ) {
		throw new Error(
			'Account separation failed: store, account ID, and account alias are required.'
		);
	}
	const hasCIAccountAlias = !! options.ciAccountAlias;
	const hasCIAccountId = !! options.ciAccountId;
	if ( hasCIAccountAlias !== hasCIAccountId ) {
		throw new Error(
			'Account separation failed: the protected CI account alias and ID must be supplied together.'
		);
	}
	if ( options.isCI && ! hasCIAccountAlias ) {
		throw new Error(
			'Account separation failed: CI requires the exact protected account alias and ID.'
		);
	}
	if (
		options.isCI &&
		( current.accountAlias !== options.ciAccountAlias ||
			current.accountId !== options.ciAccountId )
	) {
		throw new Error(
			'Account separation failed: CI must use the exact protected CI account alias and ID.'
		);
	}
	if (
		! options.isCI &&
		( current.accountAlias === options.ciAccountAlias ||
			current.accountId === options.ciAccountId )
	) {
		throw new Error(
			'Account separation failed: local runs cannot use the CI account alias or ID.'
		);
	}

	const matchingAllocations = allocations.filter(
		( allocation ) => allocation.storeId === current.storeId
	);
	if (
		matchingAllocations.length !== 1 ||
		matchingAllocations[ 0 ].accountId !== current.accountId ||
		matchingAllocations[ 0 ].accountAlias !== current.accountAlias
	) {
		throw new Error(
			'Account separation failed: the current store does not exactly match its declared account allocation.'
		);
	}

	const stores = new Set< string >();
	const accountIds = new Set< string >();
	const accountAliases = new Set< string >();
	for ( const allocation of allocations ) {
		if (
			stores.has( allocation.storeId ) ||
			accountIds.has( allocation.accountId ) ||
			accountAliases.has( allocation.accountAlias )
		) {
			throw new Error(
				'Account separation failed: store IDs, account IDs, and account aliases must be unique across the complete allocation manifest.'
			);
		}
		stores.add( allocation.storeId );
		accountIds.add( allocation.accountId );
		accountAliases.add( allocation.accountAlias );
	}
}

export class ResourceLock {
	public displacedOwner?: ResourceLockPayload;
	public payload: ResourceLockPayload;

	private readonly manager: ResourceLockManager;
	private renewalTimer?: ReturnType< typeof setInterval >;
	private renewalError?: Error;

	public constructor(
		manager: ResourceLockManager,
		payload: ResourceLockPayload,
		displacedOwner?: ResourceLockPayload
	) {
		this.manager = manager;
		this.payload = payload;
		this.displacedOwner = displacedOwner;
	}

	public startRenewal( renewEveryMs: number ): void {
		this.renewalTimer = setInterval( () => {
			void this.renew().catch( ( error: unknown ) => {
				this.renewalError =
					error instanceof Error
						? error
						: new Error( String( error ) );
				this.stopRenewal();
			} );
		}, renewEveryMs );
		this.renewalTimer.unref();
	}

	public async renew(): Promise< void > {
		this.throwRenewalError();
		this.payload = await this.manager.renew( this.payload );
	}

	public async isOwned(): Promise< boolean > {
		this.throwRenewalError();
		return this.manager.isOwned( this.payload );
	}

	public async release(): Promise< boolean > {
		this.stopRenewal();
		this.throwRenewalError();
		return this.manager.release( this.payload );
	}

	public async restoreIfOwned(
		restore: () => Promise< void >
	): Promise< boolean > {
		this.throwRenewalError();
		return this.manager.restoreIfOwned( this.payload, restore );
	}

	public async writeRestorationJournal(
		originalValue: boolean
	): Promise< void > {
		this.throwRenewalError();
		await this.manager.writeRestorationJournal(
			this.payload,
			originalValue
		);
	}

	public async restoreFromJournalIfOwned(
		restore: ( originalValue: boolean ) => Promise< void >
	): Promise< boolean > {
		this.throwRenewalError();
		return this.manager.restoreFromJournalIfOwned( this.payload, restore );
	}

	private stopRenewal(): void {
		if ( this.renewalTimer ) {
			clearInterval( this.renewalTimer );
			this.renewalTimer = undefined;
		}
	}

	private throwRenewalError(): void {
		if ( this.renewalError ) {
			throw this.renewalError;
		}
	}
}

export class ResourceLockManager {
	public readonly recoveryLogPath: string;

	private readonly lockDir: string;
	private readonly runId: string;
	private readonly pid: number;
	private readonly now: () => number;
	private readonly sleep: ( milliseconds: number ) => Promise< void >;
	private readonly leaseMs: number;
	private readonly renewEveryMs: number;
	private readonly mutationGuardLeaseMs: number;
	private readonly maxWaitMs: number;
	private readonly autoRenew: boolean;
	private readonly heldLocks = new Map<
		string,
		{
			request: ResourceLockRequest;
			payload: ResourceLockPayload;
		}
	>();

	public constructor( options: ResourceLockManagerOptions ) {
		const lockDir = options.lockDir ?? process.env.E2E_WOOPAYMENTS_LOCK_DIR;
		if ( ! lockDir ) {
			throw new Error(
				'E2E_WOOPAYMENTS_LOCK_DIR is required for provider-writing tests.'
			);
		}
		if ( ! options.runId ) {
			throw new Error( 'A non-empty E2E run ID is required for locks.' );
		}

		this.lockDir = lockDir;
		this.runId = options.runId;
		this.pid = options.pid ?? process.pid;
		this.now = options.now ?? Date.now;
		this.sleep = options.sleep ?? delay;
		this.leaseMs = options.leaseMs ?? DEFAULT_LEASE_MS;
		this.renewEveryMs = options.renewEveryMs ?? DEFAULT_RENEW_EVERY_MS;
		this.mutationGuardLeaseMs =
			options.mutationGuardLeaseMs ?? DEFAULT_LEASE_MS;
		this.maxWaitMs = options.maxWaitMs ?? DEFAULT_MAX_WAIT_MS;
		this.autoRenew = options.autoRenew ?? true;
		this.recoveryLogPath = join( lockDir, 'stale-recoveries.jsonl' );
	}

	public async acquire(
		request: ResourceLockRequest
	): Promise< ResourceLock > {
		this.assertRequest( request );
		this.assertAcquisitionOrder( request.kind );
		await this.assertAcquisitionPrerequisites( request );
		const key = this.getKey( request );
		await assertResourcesUsable( [ key ], this.lockDir );
		await mkdir( this.lockDir, { recursive: true } );

		const lockPath = this.getLockPath( key );
		const deadline = this.now() + this.maxWaitMs;

		for (;;) {
			const payload: ResourceLockPayload = {
				key,
				runId: this.runId,
				pid: this.pid,
				acquiredAt: this.now(),
				expiresAt: this.now() + this.leaseMs,
				diagnosticPath: request.diagnosticPath,
			};

			const acquisition = await this.withMutationGuard(
				lockPath,
				async () => {
					if ( await this.tryCreate( lockPath, payload ) ) {
						return {
							acquired: true,
						};
					}
					const displacedOwner = await this.tryRecoverExpired(
						lockPath,
						payload
					);
					return displacedOwner
						? { acquired: true, displacedOwner }
						: undefined;
				}
			);
			if ( acquisition?.acquired ) {
				try {
					await assertResourcesUsable( [ key ], this.lockDir );
				} catch ( error ) {
					await this.release( payload );
					throw error;
				}
				return this.trackLock(
					request,
					payload,
					acquisition.displacedOwner
				);
			}

			if ( this.now() >= deadline ) {
				throw new Error(
					`Timed out after ${ this.maxWaitMs }ms waiting for resource lock ${ key }.`
				);
			}
			await this.sleep(
				Math.min( RETRY_INTERVAL_MS, deadline - this.now() )
			);
		}
	}

	public async renew(
		ownedPayload: ResourceLockPayload
	): Promise< ResourceLockPayload > {
		const lockPath = this.getLockPath( ownedPayload.key );

		const renewed = await this.withMutationGuard( lockPath, async () => {
			const current = await this.readPayload( lockPath );
			if ( ! current || ! exactOwner( current, ownedPayload ) ) {
				throw new Error(
					`Cannot renew resource lock ${ ownedPayload.key }: the current process no longer owns it.`
				);
			}

			const renewedPayload = {
				...current,
				expiresAt: this.now() + this.leaseMs,
			};
			await this.writeLockPayload( lockPath, renewedPayload );
			return renewedPayload;
		} );
		const held = this.heldLocks.get( ownedPayload.key );
		if ( held && exactOwner( held.payload, ownedPayload ) ) {
			held.payload = renewed;
		}
		return renewed;
	}

	public async isOwned(
		ownedPayload: ResourceLockPayload
	): Promise< boolean > {
		const current = await this.readPayload(
			this.getLockPath( ownedPayload.key )
		);
		return (
			!! current &&
			exactOwner( current, ownedPayload ) &&
			current.expiresAt > this.now()
		);
	}

	public async restoreIfOwned(
		ownedPayload: ResourceLockPayload,
		restore: () => Promise< void >
	): Promise< boolean > {
		const lockPath = this.getLockPath( ownedPayload.key );

		return this.withMutationGuard( lockPath, async () => {
			const current = await this.readPayload( lockPath );
			if (
				! current ||
				! exactOwner( current, ownedPayload ) ||
				current.expiresAt <= this.now()
			) {
				return false;
			}

			await this.writeLockPayload( lockPath, {
				...current,
				expiresAt: this.now() + this.leaseMs,
			} );
			await restore();

			const afterRestore = await this.readPayload( lockPath );
			if (
				! afterRestore ||
				! exactOwner( afterRestore, ownedPayload )
			) {
				return false;
			}
			await this.writeLockPayload( lockPath, {
				...afterRestore,
				expiresAt: this.now() + this.leaseMs,
			} );
			return true;
		} );
	}

	public async writeRestorationJournal(
		ownedPayload: ResourceLockPayload,
		originalValue: boolean
	): Promise< void > {
		const request = this.getHeldFeatureRequest( ownedPayload );
		const lockPath = this.getLockPath( ownedPayload.key );
		const journalPath = this.getRestorationJournalPath( request );

		await this.withMutationGuard( lockPath, async () => {
			if ( ! ( await this.isOwned( ownedPayload ) ) ) {
				throw new Error(
					`Cannot journal restoration for ${ ownedPayload.key }: lock ownership was lost.`
				);
			}
			if ( await this.readRestorationJournal( journalPath ) ) {
				throw new Error(
					`Cannot replace an unresolved restoration journal: ${ journalPath }`
				);
			}
			const journal: RestorationJournalPayload = {
				version: 1,
				journalId: randomUUID(),
				accountHash: this.redactIdentity( request.providerAccountId ),
				storeHash: this.redactIdentity( request.storeId ),
				settingHash: this.redactIdentity( request.resource ),
				runId: this.runId,
				originalValue,
				createdAt: this.now(),
			};
			await this.writeDurableJson( journalPath, journal );
		} );
	}

	public async restoreFromJournalIfOwned(
		ownedPayload: ResourceLockPayload,
		restore: ( originalValue: boolean ) => Promise< void >
	): Promise< boolean > {
		const request = this.getHeldFeatureRequest( ownedPayload );
		const lockPath = this.getLockPath( ownedPayload.key );
		const journalPath = this.getRestorationJournalPath( request );

		return this.withMutationGuard( lockPath, async () => {
			const current = await this.readPayload( lockPath );
			if (
				! current ||
				! exactOwner( current, ownedPayload ) ||
				current.expiresAt <= this.now()
			) {
				return false;
			}

			const journal = await this.readRestorationJournal( journalPath );
			if ( ! journal ) {
				return false;
			}
			this.assertJournalIdentity( journal, request, journalPath );
			await this.writeLockPayload( lockPath, {
				...current,
				expiresAt: this.now() + this.leaseMs,
			} );

			await restore( journal.originalValue );

			const afterRestore = await this.readPayload( lockPath );
			const afterJournal = await this.readRestorationJournal(
				journalPath
			);
			if (
				! afterRestore ||
				! exactOwner( afterRestore, ownedPayload ) ||
				! afterJournal ||
				afterJournal.journalId !== journal.journalId
			) {
				return false;
			}
			await unlink( journalPath );
			await this.syncLockDirectory();
			return true;
		} );
	}

	public async release(
		ownedPayload: ResourceLockPayload
	): Promise< boolean > {
		const lockPath = this.getLockPath( ownedPayload.key );
		const released = await this.withMutationGuard( lockPath, async () => {
			const current = await this.readPayload( lockPath );
			if (
				! current ||
				! exactOwner( current, ownedPayload ) ||
				current.expiresAt <= this.now()
			) {
				return false;
			}

			await unlink( lockPath );
			return true;
		} );

		if ( released ) {
			this.heldLocks.delete( ownedPayload.key );
		}
		return released;
	}

	private trackLock(
		request: ResourceLockRequest,
		payload: ResourceLockPayload,
		displacedOwner?: ResourceLockPayload
	): ResourceLock {
		this.heldLocks.set( payload.key, { request, payload } );
		const lock = new ResourceLock( this, payload, displacedOwner );
		if ( this.autoRenew ) {
			lock.startRenewal( this.renewEveryMs );
		}
		return lock;
	}

	private assertRequest( request: ResourceLockRequest ): void {
		for ( const [ name, value ] of Object.entries( request ) ) {
			if ( typeof value !== 'string' || ! value.trim() ) {
				throw new Error(
					`Resource lock request ${ name } must be a non-empty string.`
				);
			}
		}
	}

	private assertAcquisitionOrder( nextKind: ResourceLockKind ): void {
		const nextOrder = LOCK_ORDER[ nextKind ];
		for ( const { request } of this.heldLocks.values() ) {
			const heldKind = request.kind;
			if ( LOCK_ORDER[ heldKind ] > nextOrder ) {
				throw new Error(
					`Resource lock acquisition order violation: ${ nextKind } cannot be acquired after ${ heldKind }.`
				);
			}
		}
	}

	private async assertAcquisitionPrerequisites(
		request: ResourceLockRequest
	): Promise< void > {
		if ( request.kind === 'account' ) {
			return;
		}

		const hasAccount = await this.hasOwnedPrerequisite(
			request,
			'account'
		);
		if ( request.kind === 'store' ) {
			if ( ! hasAccount ) {
				throw new Error(
					'Resource lock prerequisite violation: store requires the matching account lock.'
				);
			}
			return;
		}

		const hasStore = await this.hasOwnedPrerequisite( request, 'store' );
		if ( ! hasAccount || ! hasStore ) {
			throw new Error(
				`Resource lock prerequisite violation: ${ request.kind } requires matching account and store locks.`
			);
		}
	}

	private async hasOwnedPrerequisite(
		request: ResourceLockRequest,
		kind: 'account' | 'store'
	): Promise< boolean > {
		for ( const held of this.heldLocks.values() ) {
			if (
				held.request.kind === kind &&
				held.request.providerAccountId === request.providerAccountId &&
				held.request.storeId === request.storeId &&
				( await this.isOwned( held.payload ) )
			) {
				return true;
			}
		}
		return false;
	}

	private getKey( request: ResourceLockRequest ): string {
		return resourceLockKey( request );
	}

	private getLockPath( key: string ): string {
		return join( this.lockDir, `${ sha256( key ) }.lock` );
	}

	private getHeldFeatureRequest(
		ownedPayload: ResourceLockPayload
	): ResourceLockRequest {
		const held = this.heldLocks.get( ownedPayload.key );
		if (
			! held ||
			held.request.kind !== 'feature-setting' ||
			! exactOwner( held.payload, ownedPayload )
		) {
			throw new Error(
				'Restoration journals require the matching owned feature-setting lock.'
			);
		}
		return held.request;
	}

	private redactIdentity( identity: string ): string {
		return sha256( identity );
	}

	private getRestorationJournalPath( request: ResourceLockRequest ): string {
		const identity = [
			request.providerAccountId,
			request.storeId,
			request.resource,
		].join( '\u0000' );
		return join( this.lockDir, `${ sha256( identity ) }.restoration.json` );
	}

	private async writeDurableJson(
		path: string,
		payload: RestorationJournalPayload
	): Promise< void > {
		await writeDurableJson(
			this.lockDir,
			path,
			payload,
			payload.journalId
		);
	}

	private async writeLockPayload(
		path: string,
		payload: ResourceLockPayload
	): Promise< void > {
		await writeDurableJson( this.lockDir, path, payload, randomUUID() );
	}

	private async syncLockDirectory(): Promise< void > {
		await syncDirectory( this.lockDir );
	}

	private async readRestorationJournal(
		path: string
	): Promise< RestorationJournalPayload | undefined > {
		try {
			const data = JSON.parse(
				await readFile( path, 'utf8' )
			) as RestorationJournalPayload;
			if (
				data.version !== 1 ||
				typeof data.journalId !== 'string' ||
				typeof data.accountHash !== 'string' ||
				typeof data.storeHash !== 'string' ||
				typeof data.settingHash !== 'string' ||
				typeof data.runId !== 'string' ||
				typeof data.originalValue !== 'boolean' ||
				typeof data.createdAt !== 'number'
			) {
				throw new Error(
					`Invalid restoration journal payload: ${ path }`
				);
			}
			return data;
		} catch ( error ) {
			if (
				error instanceof Error &&
				'code' in error &&
				error.code === 'ENOENT'
			) {
				return undefined;
			}
			throw error;
		}
	}

	private assertJournalIdentity(
		journal: RestorationJournalPayload,
		request: ResourceLockRequest,
		path: string
	): void {
		if (
			journal.accountHash !==
				this.redactIdentity( request.providerAccountId ) ||
			journal.storeHash !== this.redactIdentity( request.storeId ) ||
			journal.settingHash !== this.redactIdentity( request.resource )
		) {
			throw new Error(
				`Restoration journal identity mismatch: ${ path }`
			);
		}
	}

	private async tryCreate(
		lockPath: string,
		payload: ResourceLockPayload
	): Promise< boolean > {
		return writeNewDurableJson(
			this.lockDir,
			lockPath,
			payload,
			randomUUID()
		);
	}

	private async tryRecoverExpired(
		lockPath: string,
		replacement: ResourceLockPayload
	): Promise< ResourceLockPayload | undefined > {
		const current = await this.readPayload( lockPath );
		if ( ! current || current.expiresAt > this.now() ) {
			return undefined;
		}

		const stalePath = `${ lockPath }.stale-${ this.now() }-${ this.pid }`;
		await rename( lockPath, stalePath );
		if ( ! ( await this.tryCreate( lockPath, replacement ) ) ) {
			return undefined;
		}
		await appendFile(
			this.recoveryLogPath,
			`${ JSON.stringify( {
				recoveredAt: this.now(),
				displacedOwner: current,
				recoveredBy: replacement,
				stalePath,
			} ) }\n`,
			{ mode: 0o600 }
		);
		return current;
	}

	private async readPayload(
		lockPath: string
	): Promise< ResourceLockPayload | undefined > {
		try {
			const data = JSON.parse(
				await readFile( lockPath, 'utf8' )
			) as ResourceLockPayload;
			if (
				typeof data.key !== 'string' ||
				typeof data.runId !== 'string' ||
				typeof data.pid !== 'number' ||
				typeof data.acquiredAt !== 'number' ||
				typeof data.expiresAt !== 'number' ||
				typeof data.diagnosticPath !== 'string'
			) {
				throw new Error(
					`Invalid resource lock payload: ${ lockPath }`
				);
			}
			return data;
		} catch ( error ) {
			if (
				error instanceof Error &&
				'code' in error &&
				error.code === 'ENOENT'
			) {
				return undefined;
			}
			throw error;
		}
	}

	private async withMutationGuard< Result >(
		lockPath: string,
		operation: () => Promise< Result >
	): Promise< Result > {
		const guardPath = `${ lockPath }.mutation`;
		const deadline = this.now() + this.maxWaitMs;
		const guardOwner: MutationGuardPayload = {
			ownerId: randomUUID(),
			pid: process.pid,
			acquiredAt: this.now(),
			expiresAt: this.now() + this.mutationGuardLeaseMs,
		};

		for (;;) {
			if ( await this.tryCreateMutationGuard( guardPath, guardOwner ) ) {
				break;
			}
			if (
				await this.tryRecoverExpiredMutationGuard(
					guardPath,
					guardOwner
				)
			) {
				break;
			}
			if ( this.now() >= deadline ) {
				throw new Error(
					`Timed out waiting for the mutation guard for ${ lockPath }.`
				);
			}
			await this.sleep(
				Math.min( RETRY_INTERVAL_MS, deadline - this.now() )
			);
		}

		let renewalError: Error | undefined;
		let renewalInFlight = Promise.resolve();
		const renewalTimer = setInterval( () => {
			renewalInFlight = renewalInFlight.then( async () => {
				try {
					await this.renewMutationGuard( guardPath, guardOwner );
				} catch ( error ) {
					renewalError =
						error instanceof Error
							? error
							: new Error( String( error ) );
				}
			} );
		}, Math.max( 10, Math.floor( this.mutationGuardLeaseMs / 3 ) ) );
		renewalTimer.unref();

		let primaryError: unknown;
		let result: Result | undefined;
		try {
			result = await operation();
		} catch ( error ) {
			primaryError = error;
		} finally {
			clearInterval( renewalTimer );
		}
		await renewalInFlight;

		let releaseError: unknown;
		try {
			await this.releaseMutationGuard( guardPath, guardOwner );
		} catch ( error ) {
			releaseError = error;
		}

		if ( primaryError !== undefined ) {
			throw primaryError;
		}
		if ( renewalError ) {
			throw renewalError;
		}
		if ( releaseError !== undefined ) {
			throw releaseError;
		}
		return result as Result;
	}

	private async tryCreateMutationGuard(
		guardPath: string,
		owner: MutationGuardPayload
	): Promise< boolean > {
		return writeNewDurableJson(
			this.lockDir,
			guardPath,
			owner,
			randomUUID()
		);
	}

	private async tryRecoverExpiredMutationGuard(
		guardPath: string,
		replacement: MutationGuardPayload
	): Promise< boolean > {
		const current = await this.readMutationGuard( guardPath );
		if ( ! current ) {
			return false;
		}
		const released = await this.isMutationGuardReleased(
			guardPath,
			current
		);
		if (
			! released &&
			( current.expiresAt + this.mutationGuardLeaseMs > this.now() ||
				this.isProcessAlive( current.pid ) )
		) {
			return false;
		}

		const stalePath = `${ guardPath }.stale-${ replacement.ownerId }`;
		try {
			await rename( guardPath, stalePath );
		} catch ( error ) {
			if (
				error instanceof Error &&
				'code' in error &&
				error.code === 'ENOENT'
			) {
				return false;
			}
			throw error;
		}

		const displaced = await this.readMutationGuard( stalePath );
		const displacedReleased = displaced
			? await this.isMutationGuardReleased( guardPath, displaced )
			: false;
		if (
			! displaced ||
			! exactGuardOwner( displaced, current ) ||
			( ! displacedReleased &&
				( displaced.expiresAt + this.mutationGuardLeaseMs >
					this.now() ||
					this.isProcessAlive( displaced.pid ) ) )
		) {
			try {
				await rename( stalePath, guardPath );
			} catch {
				// A live contender already installed an owned guard.
			}
			return false;
		}

		const created = await this.tryCreateMutationGuard(
			guardPath,
			replacement
		);
		await unlink( stalePath );
		if ( displacedReleased ) {
			await this.removeMutationGuardReleaseMarker( guardPath, displaced );
		}
		return created;
	}

	private async renewMutationGuard(
		guardPath: string,
		owner: MutationGuardPayload
	): Promise< void > {
		const { handle, current } = await this.openOwnedMutationGuard(
			guardPath,
			owner,
			'renew'
		);
		try {
			const renewed = {
				...current,
				expiresAt: this.now() + this.mutationGuardLeaseMs,
			};
			const serialized = `${ JSON.stringify( renewed ) }\n`;
			await handle.write( serialized, 0, 'utf8' );
			await handle.truncate( Buffer.byteLength( serialized ) );
			await handle.sync();
			owner.expiresAt = renewed.expiresAt;
		} finally {
			await handle.close();
		}
	}

	private async releaseMutationGuard(
		guardPath: string,
		owner: MutationGuardPayload
	): Promise< void > {
		const { handle } = await this.openOwnedMutationGuard(
			guardPath,
			owner,
			'release'
		);
		await handle.close();

		const markerPath = this.getMutationGuardReleaseMarkerPath(
			guardPath,
			owner
		);
		try {
			const marker = await open( markerPath, 'wx', 0o600 );
			try {
				await marker.writeFile( `${ owner.ownerId }\n` );
				await marker.sync();
			} finally {
				await marker.close();
			}
		} catch ( error ) {
			if (
				! (
					error instanceof Error &&
					'code' in error &&
					error.code === 'EEXIST'
				)
			) {
				throw error;
			}
		}
	}

	private async openOwnedMutationGuard(
		guardPath: string,
		owner: MutationGuardPayload,
		action: 'renew' | 'release'
	): Promise< {
		handle: FileHandle;
		current: MutationGuardPayload;
	} > {
		const expected = await this.readMutationGuard( guardPath );
		if ( ! expected || ! exactGuardOwner( expected, owner ) ) {
			throw new Error(
				`Cannot ${ action } mutation guard ${ guardPath }: ownership was lost.`
			);
		}

		let handle: FileHandle;
		try {
			handle = await open( guardPath, 'r+' );
		} catch ( error ) {
			if (
				error instanceof Error &&
				'code' in error &&
				error.code === 'ENOENT'
			) {
				throw new Error(
					`Cannot ${ action } mutation guard ${ guardPath }: ownership was lost.`,
					{ cause: error }
				);
			}
			throw error;
		}

		try {
			const current = this.parseMutationGuard(
				await handle.readFile( 'utf8' ),
				guardPath
			);
			if ( ! exactGuardOwner( current, owner ) ) {
				throw new Error(
					`Cannot ${ action } mutation guard ${ guardPath }: ownership was lost.`
				);
			}
			return { handle, current };
		} catch ( error ) {
			await handle.close();
			throw error;
		}
	}

	private async readMutationGuard(
		guardPath: string
	): Promise< MutationGuardPayload | undefined > {
		try {
			return this.parseMutationGuard(
				await readFile( guardPath, 'utf8' ),
				guardPath
			);
		} catch ( error ) {
			if (
				error instanceof Error &&
				'code' in error &&
				error.code === 'ENOENT'
			) {
				return undefined;
			}
			throw error;
		}
	}

	private parseMutationGuard(
		serialized: string,
		guardPath: string
	): MutationGuardPayload {
		const data = JSON.parse( serialized ) as MutationGuardPayload;
		if (
			typeof data.ownerId !== 'string' ||
			typeof data.pid !== 'number' ||
			typeof data.acquiredAt !== 'number' ||
			typeof data.expiresAt !== 'number'
		) {
			throw new Error( `Invalid mutation guard payload: ${ guardPath }` );
		}
		return data;
	}

	private getMutationGuardReleaseMarkerPath(
		guardPath: string,
		owner: MutationGuardPayload
	): string {
		return `${ guardPath }.released-${ owner.ownerId }`;
	}

	private async isMutationGuardReleased(
		guardPath: string,
		owner: MutationGuardPayload
	): Promise< boolean > {
		try {
			return (
				(
					await readFile(
						this.getMutationGuardReleaseMarkerPath(
							guardPath,
							owner
						),
						'utf8'
					)
				 ).trim() === owner.ownerId
			);
		} catch ( error ) {
			if (
				error instanceof Error &&
				'code' in error &&
				error.code === 'ENOENT'
			) {
				return false;
			}
			throw error;
		}
	}

	private async removeMutationGuardReleaseMarker(
		guardPath: string,
		owner: MutationGuardPayload
	): Promise< void > {
		try {
			await unlink(
				this.getMutationGuardReleaseMarkerPath( guardPath, owner )
			);
		} catch ( error ) {
			if (
				! (
					error instanceof Error &&
					'code' in error &&
					error.code === 'ENOENT'
				)
			) {
				throw error;
			}
		}
	}

	private isProcessAlive( pid: number ): boolean {
		try {
			process.kill( pid, 0 );
			return true;
		} catch ( error ) {
			return ! (
				error instanceof Error &&
				'code' in error &&
				error.code === 'ESRCH'
			);
		}
	}
}
