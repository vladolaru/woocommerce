import { createHash, randomUUID } from 'node:crypto';
import { mkdir, open, readFile, rename, unlink } from 'node:fs/promises';
import { basename, join } from 'node:path';

export interface ResourceQuarantineReceipt {
	version: 1;
	resourceKeyHash: string;
	runId: string;
	reasonCode:
		| 'cleanup-failed'
		| 'restoration-failed'
		| 'lock-ownership-lost'
		| 'teardown-failed';
	evidencePath: string;
	quarantinedAt: number;
}

const RECEIPT_KEYS = [
	'evidencePath',
	'quarantinedAt',
	'reasonCode',
	'resourceKeyHash',
	'runId',
	'version',
];
const REASON_CODES = new Set< ResourceQuarantineReceipt[ 'reasonCode' ] >( [
	'cleanup-failed',
	'restoration-failed',
	'lock-ownership-lost',
	'teardown-failed',
] );
const IN_PROGRESS_RECEIPT_READ_ATTEMPTS = 20;
const IN_PROGRESS_RECEIPT_READ_INTERVAL_MS = 5;

function sha256( value: string ): string {
	return createHash( 'sha256' ).update( value ).digest( 'hex' );
}

function getLockDirectory(
	required: boolean,
	lockDirOverride?: string
): string | undefined {
	const lockDir = lockDirOverride ?? process.env.E2E_WOOPAYMENTS_LOCK_DIR;
	if ( ! lockDir && required ) {
		throw new Error(
			'E2E_WOOPAYMENTS_LOCK_DIR is required to quarantine WooPayments resources.'
		);
	}
	return lockDir;
}

function getRunId( evidencePath: string ): string {
	const normalized = evidencePath.replace( /\/+$/, '' );
	const runId = basename( normalized );
	if ( ! runId || runId === '.' ) {
		throw new Error(
			'WooPayments quarantine evidence path must identify a run.'
		);
	}
	return runId;
}

function createReceipt(
	resourceKeyHash: string,
	reasonCode: ResourceQuarantineReceipt[ 'reasonCode' ],
	evidencePath: string
): ResourceQuarantineReceipt {
	if ( ! REASON_CODES.has( reasonCode ) ) {
		throw new Error(
			`Invalid WooPayments quarantine reason code: ${ reasonCode }`
		);
	}
	return {
		version: 1,
		resourceKeyHash,
		runId: getRunId( evidencePath ),
		reasonCode,
		evidencePath,
		quarantinedAt: Date.now(),
	};
}

function assertReceipt(
	value: unknown,
	expectedHash: string,
	path: string
): ResourceQuarantineReceipt {
	if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
		throw new Error( `Invalid WooPayments quarantine receipt: ${ path }` );
	}
	const receipt = value as Partial< ResourceQuarantineReceipt >;
	if (
		JSON.stringify( Object.keys( value ).sort() ) !==
			JSON.stringify( RECEIPT_KEYS ) ||
		receipt.version !== 1 ||
		typeof receipt.resourceKeyHash !== 'string' ||
		typeof receipt.runId !== 'string' ||
		! receipt.runId ||
		typeof receipt.reasonCode !== 'string' ||
		! REASON_CODES.has(
			receipt.reasonCode as ResourceQuarantineReceipt[ 'reasonCode' ]
		) ||
		typeof receipt.evidencePath !== 'string' ||
		! receipt.evidencePath ||
		typeof receipt.quarantinedAt !== 'number' ||
		! Number.isFinite( receipt.quarantinedAt )
	) {
		throw new Error( `Invalid WooPayments quarantine receipt: ${ path }` );
	}
	if ( receipt.resourceKeyHash !== expectedHash ) {
		throw new Error(
			`WooPayments quarantine receipt identity mismatch: ${ path }`
		);
	}
	return receipt as ResourceQuarantineReceipt;
}

async function readReceipt(
	path: string,
	expectedHash: string
): Promise< ResourceQuarantineReceipt | undefined > {
	let contents: string;
	try {
		contents = await readFile( path, 'utf8' );
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

	try {
		return assertReceipt( JSON.parse( contents ), expectedHash, path );
	} catch ( error ) {
		if (
			error instanceof Error &&
			/^(Invalid|WooPayments quarantine receipt identity)/.test(
				error.message
			)
		) {
			throw error;
		}
		throw new Error( `Invalid WooPayments quarantine receipt: ${ path }`, {
			cause: error,
		} );
	}
}

async function readClaimedReceipt(
	path: string,
	expectedHash: string
): Promise< ResourceQuarantineReceipt | undefined > {
	for (
		let attempt = 1;
		attempt <= IN_PROGRESS_RECEIPT_READ_ATTEMPTS;
		attempt += 1
	) {
		try {
			const receipt = await readReceipt( path, expectedHash );
			if ( receipt || attempt === IN_PROGRESS_RECEIPT_READ_ATTEMPTS ) {
				return receipt;
			}
		} catch ( error ) {
			const claimIsStillInProgress =
				error instanceof Error &&
				error.message ===
					`Invalid WooPayments quarantine receipt: ${ path }`;
			if (
				! claimIsStillInProgress ||
				attempt === IN_PROGRESS_RECEIPT_READ_ATTEMPTS
			) {
				throw error;
			}
		}
		await new Promise( ( resolve ) =>
			setTimeout( resolve, IN_PROGRESS_RECEIPT_READ_INTERVAL_MS )
		);
	}
	return undefined;
}

async function syncDirectory( directoryPath: string ): Promise< void > {
	const directory = await open( directoryPath, 'r' );
	try {
		await directory.sync();
	} finally {
		await directory.close();
	}
}

async function writeNewReceipt(
	quarantineDir: string,
	receiptPath: string,
	receipt: ResourceQuarantineReceipt
): Promise< boolean > {
	let claim;
	try {
		claim = await open( receiptPath, 'wx', 0o600 );
		await claim.close();
	} catch ( error ) {
		await claim?.close();
		if (
			error instanceof Error &&
			'code' in error &&
			error.code === 'EEXIST'
		) {
			return false;
		}
		throw error;
	}

	const temporaryPath = `${ receiptPath }.tmp-${ randomUUID() }`;
	try {
		const temporary = await open( temporaryPath, 'wx', 0o600 );
		try {
			await temporary.writeFile( `${ JSON.stringify( receipt ) }\n` );
			await temporary.sync();
		} finally {
			await temporary.close();
		}
		await rename( temporaryPath, receiptPath );
		await syncDirectory( quarantineDir );
		return true;
	} catch ( error ) {
		await unlink( temporaryPath ).catch( () => {} );
		throw error;
	}
}

async function appendQuarantineEvent(
	quarantineDir: string,
	receipt: ResourceQuarantineReceipt
): Promise< void > {
	const eventsPath = join( quarantineDir, 'events.jsonl' );
	const events = await open( eventsPath, 'a', 0o600 );
	try {
		await events.writeFile( `${ JSON.stringify( receipt ) }\n` );
		await events.sync();
	} finally {
		await events.close();
	}
	await syncDirectory( quarantineDir );
}

async function hasQuarantineEvent(
	quarantineDir: string,
	resourceKeyHash: string
): Promise< boolean > {
	let contents: string;
	const eventsPath = join( quarantineDir, 'events.jsonl' );
	try {
		contents = await readFile( eventsPath, 'utf8' );
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

	return contents
		.split( '\n' )
		.filter( Boolean )
		.map( ( line ) => {
			try {
				const event = JSON.parse( line ) as {
					resourceKeyHash?: unknown;
				};
				if ( typeof event.resourceKeyHash !== 'string' ) {
					throw new Error( 'Missing resource key hash.' );
				}
				return assertReceipt(
					event,
					event.resourceKeyHash,
					eventsPath
				);
			} catch ( error ) {
				throw new Error(
					`Invalid WooPayments quarantine event log: ${ eventsPath }`,
					{ cause: error }
				);
			}
		} )
		.some( ( event ) => event.resourceKeyHash === resourceKeyHash );
}

export async function assertResourcesUsable(
	resourceKeys: string[],
	lockDirOverride?: string
): Promise< void > {
	const lockDir = getLockDirectory( false, lockDirOverride );
	if ( ! lockDir ) {
		return;
	}
	const quarantineDir = join( lockDir, 'quarantine' );

	for ( const resourceKey of resourceKeys ) {
		const resourceKeyHash = sha256( resourceKey );
		const receipt = await readReceipt(
			join( quarantineDir, `${ resourceKeyHash }.json` ),
			resourceKeyHash
		);
		if ( receipt ) {
			throw new Error(
				`WooPayments resource ${ resourceKeyHash } is quarantined; see ${ receipt.evidencePath }.`
			);
		}
		if ( await hasQuarantineEvent( quarantineDir, resourceKeyHash ) ) {
			throw new Error(
				`WooPayments resource ${ resourceKeyHash } has a missing quarantine receipt and remains quarantined.`
			);
		}
	}
}

export async function quarantineResources(
	resourceKeys: string[],
	reasonCode: ResourceQuarantineReceipt[ 'reasonCode' ],
	evidencePath: string,
	lockDirOverride?: string
): Promise< ResourceQuarantineReceipt[] > {
	const lockDir = getLockDirectory( true, lockDirOverride ) as string;
	const quarantineDir = join( lockDir, 'quarantine' );
	await mkdir( quarantineDir, { recursive: true, mode: 0o700 } );
	const receipts: ResourceQuarantineReceipt[] = [];

	for ( const resourceKey of resourceKeys ) {
		const resourceKeyHash = sha256( resourceKey );
		const receiptPath = join( quarantineDir, `${ resourceKeyHash }.json` );
		const nextReceipt = createReceipt(
			resourceKeyHash,
			reasonCode,
			evidencePath
		);
		if (
			await writeNewReceipt( quarantineDir, receiptPath, nextReceipt )
		) {
			await appendQuarantineEvent( quarantineDir, nextReceipt );
			receipts.push( nextReceipt );
			continue;
		}

		const firstReceipt = await readClaimedReceipt(
			receiptPath,
			resourceKeyHash
		);
		if ( ! firstReceipt ) {
			throw new Error(
				`WooPayments quarantine receipt disappeared: ${ receiptPath }`
			);
		}
		await appendQuarantineEvent( quarantineDir, nextReceipt );
		receipts.push( firstReceipt );
	}

	return receipts;
}
