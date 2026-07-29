import { randomUUID } from 'node:crypto';
import { constants } from 'node:fs';
import { lstat, mkdir, open, readdir, unlink } from 'node:fs/promises';
import { basename, dirname, join } from 'node:path';

import { sha256, syncDirectory, writeNewDurableJson } from './durable-fs';

const ATTEMPTS_DIRECTORY = 'provider-write-attempts';
const ATTEMPT_KEYS = [
	'attemptId',
	'description',
	'resourceKeys',
	'runId',
	'startedAt',
	'version',
];
const UUID_PATTERN =
	/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export interface ProviderWriteAttempt {
	version: 1;
	attemptId: string;
	runId: string;
	resourceKeys: string[];
	description: string;
	startedAt: number;
}

export type NewProviderWriteAttempt = Omit< ProviderWriteAttempt, 'attemptId' >;

function assertProviderWriteAttempt(
	value: unknown,
	path: string
): ProviderWriteAttempt {
	if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
		throw new Error(
			`Invalid WooPayments provider write attempt: ${ path }`
		);
	}
	const attempt = value as Partial< ProviderWriteAttempt >;
	if (
		JSON.stringify( Object.keys( value ).toSorted() ) !==
			JSON.stringify( ATTEMPT_KEYS ) ||
		attempt.version !== 1 ||
		typeof attempt.attemptId !== 'string' ||
		! UUID_PATTERN.test( attempt.attemptId ) ||
		typeof attempt.runId !== 'string' ||
		attempt.runId === '' ||
		! Array.isArray( attempt.resourceKeys ) ||
		attempt.resourceKeys.length === 0 ||
		attempt.resourceKeys.some(
			( key ) => typeof key !== 'string' || key === ''
		) ||
		new Set( attempt.resourceKeys ).size !== attempt.resourceKeys.length ||
		typeof attempt.description !== 'string' ||
		attempt.description === '' ||
		typeof attempt.startedAt !== 'number' ||
		! Number.isFinite( attempt.startedAt ) ||
		attempt.startedAt <= 0
	) {
		throw new Error(
			`Invalid WooPayments provider write attempt: ${ path }`
		);
	}
	return attempt as ProviderWriteAttempt;
}

function getAttemptFilename( attempt: ProviderWriteAttempt ): string {
	return `${ sha256(
		JSON.stringify( [
			attempt.version,
			attempt.attemptId,
			attempt.runId,
			attempt.resourceKeys,
			attempt.description,
			attempt.startedAt,
		] )
	) }.json`;
}

function isErrorCode( error: unknown, code: string ): boolean {
	return error instanceof Error && 'code' in error && error.code === code;
}

async function readProviderWriteAttempt(
	attemptPath: string
): Promise< ProviderWriteAttempt | undefined > {
	let entryStatus;
	try {
		entryStatus = await lstat( attemptPath );
	} catch ( error ) {
		if ( isErrorCode( error, 'ENOENT' ) ) {
			return undefined;
		}
		throw error;
	}
	if ( entryStatus.isSymbolicLink() ) {
		throw new Error(
			`WooPayments provider write attempt must not be a symbolic link: ${ attemptPath }`
		);
	}
	if ( ! entryStatus.isFile() ) {
		throw new Error(
			`WooPayments provider write attempt must be a regular file: ${ attemptPath }`
		);
	}

	let attemptFile;
	try {
		const readOnlyNoFollowFlag =
			typeof constants.O_NOFOLLOW === 'number'
				? constants.O_NOFOLLOW
				: constants.O_RDONLY;
		attemptFile = await open( attemptPath, readOnlyNoFollowFlag );
	} catch ( error ) {
		if ( isErrorCode( error, 'ENOENT' ) ) {
			return undefined;
		}
		if ( isErrorCode( error, 'ELOOP' ) ) {
			throw new Error(
				`WooPayments provider write attempt must not be a symbolic link: ${ attemptPath }`,
				{ cause: error }
			);
		}
		throw error;
	}

	try {
		const openedStatus = await attemptFile.stat();
		if ( ! openedStatus.isFile() ) {
			throw new Error(
				`WooPayments provider write attempt must be a regular file: ${ attemptPath }`
			);
		}
		const attempt = assertProviderWriteAttempt(
			JSON.parse( await attemptFile.readFile( 'utf8' ) ),
			attemptPath
		);
		if ( basename( attemptPath ) !== getAttemptFilename( attempt ) ) {
			throw new Error(
				`WooPayments provider write attempt filename does not bind its identity: ${ attemptPath }`
			);
		}
		return attempt;
	} finally {
		await attemptFile.close();
	}
}

export async function openProviderWriteAttempt(
	lockDir: string,
	newAttempt: NewProviderWriteAttempt
): Promise< string > {
	const directory = join( lockDir, ATTEMPTS_DIRECTORY );
	await mkdir( directory, { recursive: true, mode: 0o700 } );
	await syncDirectory( lockDir );
	const attempt = assertProviderWriteAttempt(
		{ ...newAttempt, attemptId: randomUUID() },
		'new attempt'
	);
	const attemptPath = join( directory, getAttemptFilename( attempt ) );
	const created = await writeNewDurableJson(
		directory,
		attemptPath,
		attempt,
		randomUUID()
	);
	if ( ! created ) {
		throw new Error(
			`WooPayments provider write attempt already exists: ${ attemptPath }`
		);
	}
	return attemptPath;
}

export async function resolveProviderWriteAttempt(
	attemptPath: string
): Promise< void > {
	await unlink( attemptPath );
	await syncDirectory( dirname( attemptPath ) );
}

export async function findUnresolvedProviderWriteAttempts(
	lockDir: string,
	resourceKeys: string[],
	currentRunId: string
): Promise< ProviderWriteAttempt[] > {
	const directory = join( lockDir, ATTEMPTS_DIRECTORY );
	let entries: string[];
	try {
		entries = await readdir( directory );
	} catch ( error ) {
		if (
			error instanceof Error &&
			'code' in error &&
			error.code === 'ENOENT'
		) {
			return [];
		}
		throw error;
	}

	const keys = new Set( resourceKeys );
	const unresolved: ProviderWriteAttempt[] = [];
	for ( const entry of entries.toSorted() ) {
		if ( ! entry.endsWith( '.json' ) ) {
			continue;
		}
		const attemptPath = join( directory, entry );
		const attempt = await readProviderWriteAttempt( attemptPath );
		if ( ! attempt ) {
			continue;
		}
		if (
			attempt.runId !== currentRunId &&
			attempt.resourceKeys.some( ( key ) => keys.has( key ) )
		) {
			unresolved.push( attempt );
		}
	}
	return unresolved;
}
