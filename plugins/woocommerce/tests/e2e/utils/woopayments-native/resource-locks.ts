import { createHash } from 'node:crypto';
import {
	appendFile,
	mkdir,
	open,
	readFile,
	rename,
	unlink,
	writeFile,
} from 'node:fs/promises';
import { join } from 'node:path';

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
	maxWaitMs?: number;
	autoRenew?: boolean;
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

export function assertAccountSeparation(
	current: StoreAccountAllocation,
	allocations: StoreAccountAllocation[],
	options: {
		isCI: boolean;
		ciAccountAlias: string;
		ciAccountId: string;
	}
): void {
	if ( ! current.accountId || ! current.accountAlias || ! current.storeId ) {
		throw new Error(
			'Account separation failed: store, account ID, and account alias are required.'
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
		await mkdir( this.lockDir, { recursive: true } );

		const key = this.getKey( request );
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

			const created = await this.tryCreate( lockPath, payload );
			if ( created ) {
				return this.trackLock( request, payload );
			}

			const displacedOwner = await this.tryRecoverExpired(
				lockPath,
				payload
			);
			if ( displacedOwner ) {
				return this.trackLock( request, payload, displacedOwner );
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
			await writeFile(
				lockPath,
				`${ JSON.stringify( renewedPayload ) }\n`,
				{
					mode: 0o600,
				}
			);
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

			await writeFile(
				lockPath,
				`${ JSON.stringify( {
					...current,
					expiresAt: this.now() + this.leaseMs,
				} ) }\n`,
				{ mode: 0o600 }
			);
			await restore();

			const afterRestore = await this.readPayload( lockPath );
			if (
				! afterRestore ||
				! exactOwner( afterRestore, ownedPayload )
			) {
				return false;
			}
			await writeFile(
				lockPath,
				`${ JSON.stringify( {
					...afterRestore,
					expiresAt: this.now() + this.leaseMs,
				} ) }\n`,
				{ mode: 0o600 }
			);
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
		return `${ request.providerAccountId }/${ request.storeId }/${ request.kind }:${ request.resource }`;
	}

	private getLockPath( key: string ): string {
		const hash = createHash( 'sha256' ).update( key ).digest( 'hex' );
		return join( this.lockDir, `${ hash }.lock` );
	}

	private async tryCreate(
		lockPath: string,
		payload: ResourceLockPayload
	): Promise< boolean > {
		try {
			const handle = await open( lockPath, 'wx', 0o600 );
			try {
				await handle.writeFile( `${ JSON.stringify( payload ) }\n` );
			} finally {
				await handle.close();
			}
			return true;
		} catch ( error ) {
			if (
				error instanceof Error &&
				'code' in error &&
				error.code === 'EEXIST'
			) {
				return false;
			}
			throw error;
		}
	}

	private async tryRecoverExpired(
		lockPath: string,
		replacement: ResourceLockPayload
	): Promise< ResourceLockPayload | undefined > {
		return this.withMutationGuard( lockPath, async () => {
			const current = await this.readPayload( lockPath );
			if ( ! current || current.expiresAt > this.now() ) {
				return undefined;
			}

			const stalePath = `${ lockPath }.stale-${ this.now() }-${
				this.pid
			}`;
			await rename( lockPath, stalePath );
			const created = await this.tryCreate( lockPath, replacement );
			if ( ! created ) {
				throw new Error(
					`Failed to atomically replace expired resource lock ${ current.key }.`
				);
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
		} );
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
		let guard;

		for (;;) {
			try {
				guard = await open( guardPath, 'wx', 0o600 );
				break;
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
				if ( this.now() >= deadline ) {
					throw new Error(
						`Timed out waiting for the mutation guard for ${ lockPath }.`,
						{ cause: error }
					);
				}
				await this.sleep( RETRY_INTERVAL_MS );
			}
		}

		try {
			return await operation();
		} finally {
			await guard.close();
			await unlink( guardPath );
		}
	}
}
