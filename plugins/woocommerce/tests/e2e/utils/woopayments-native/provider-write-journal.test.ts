import { readFile, readdir, rm, symlink, writeFile } from 'node:fs/promises';
import { basename, join } from 'node:path';

import { expect, test } from '@playwright/test';

import {
	findUnresolvedProviderWriteAttempts,
	openProviderWriteAttempt,
	resolveProviderWriteAttempt,
} from './provider-write-journal';
import { temporaryLockDirectory } from './lock-test-helpers';

const temporaryDirectories: string[] = [];

test.afterEach( async () => {
	await Promise.all(
		temporaryDirectories
			.splice( 0 )
			.map( ( directory ) =>
				rm( directory, { recursive: true, force: true } )
			)
	);
} );

async function journalDirectory(): Promise< string > {
	const directory = await temporaryLockDirectory(
		'woopayments-journal-test-'
	);
	temporaryDirectories.push( directory );
	return directory;
}

const attempt = ( runId: string, resourceKeys: string[] ) => ( {
	version: 1 as const,
	runId,
	resourceKeys,
	description: 'saved-card-classic-checkout',
	startedAt: 1753790000000,
} );

test( 'an open attempt is durable and discoverable by resource key', async () => {
	const lockDir = await journalDirectory();

	const attemptPath = await openProviderWriteAttempt(
		lockDir,
		attempt( 'run-a', [
			'acct_x/account:provider-writes',
			'store-1/store:store-1',
		] )
	);

	expect(
		await findUnresolvedProviderWriteAttempts(
			lockDir,
			[ 'acct_x/account:provider-writes' ],
			'run-b'
		)
	).toHaveLength( 1 );
	expect(
		await findUnresolvedProviderWriteAttempts(
			lockDir,
			[ 'acct_other/account:provider-writes' ],
			'run-b'
		)
	).toHaveLength( 0 );
	expect(
		await findUnresolvedProviderWriteAttempts(
			lockDir,
			[ 'acct_x/account:provider-writes' ],
			'run-a'
		)
	).toHaveLength( 0 );

	await resolveProviderWriteAttempt( attemptPath );

	expect(
		await findUnresolvedProviderWriteAttempts(
			lockDir,
			[ 'acct_x/account:provider-writes' ],
			'run-b'
		)
	).toHaveLength( 0 );
	expect(
		await readdir( join( lockDir, 'provider-write-attempts' ) )
	).toHaveLength( 0 );
} );

test( 'a malformed attempt file fails closed', async () => {
	const lockDir = await journalDirectory();
	await openProviderWriteAttempt(
		lockDir,
		attempt( 'run-a', [ 'acct_x/account:provider-writes' ] )
	);
	await writeFile(
		join( lockDir, 'provider-write-attempts', 'garbage.json' ),
		'not json',
		{ mode: 0o600 }
	);

	await expect(
		findUnresolvedProviderWriteAttempts(
			lockDir,
			[ 'acct_x/account:provider-writes' ],
			'run-b'
		)
	).rejects.toThrow();
} );

test( 'a structurally invalid attempt file fails closed', async () => {
	const lockDir = await journalDirectory();
	await openProviderWriteAttempt(
		lockDir,
		attempt( 'run-a', [ 'acct_x/account:provider-writes' ] )
	);
	await writeFile(
		join( lockDir, 'provider-write-attempts', 'invalid.json' ),
		JSON.stringify( {
			version: 1,
			runId: 'run-a',
			resourceKeys: [],
			description: '',
			startedAt: 'not-a-timestamp',
		} ),
		{ mode: 0o600 }
	);

	await expect(
		findUnresolvedProviderWriteAttempts(
			lockDir,
			[ 'acct_x/account:provider-writes' ],
			'run-b'
		)
	).rejects.toThrow( /Invalid WooPayments provider write attempt/ );
} );

test( 'identical new attempts receive distinct durable identities', async () => {
	const lockDir = await journalDirectory();
	const newAttempt = attempt( 'run-a', [ 'acct_x/account:provider-writes' ] );

	const [ firstPath, secondPath ] = await Promise.all( [
		openProviderWriteAttempt( lockDir, newAttempt ),
		openProviderWriteAttempt( lockDir, newAttempt ),
	] );

	expect( firstPath ).not.toBe( secondPath );
	expect(
		( await readdir( join( lockDir, 'provider-write-attempts' ) ) ).filter(
			( entry ) => entry.endsWith( '.json' )
		)
	).toHaveLength( 2 );
} );

test( 'discovery tolerates an attempt resolved after its directory snapshot', async () => {
	const lockDir = await journalDirectory();
	const relevantPath = await openProviderWriteAttempt(
		lockDir,
		attempt( 'relevant-run', [ 'acct_x/account:provider-writes' ] )
	);
	const disjointPaths = await Promise.all(
		Array.from( { length: 64 }, ( _, index ) =>
			openProviderWriteAttempt(
				lockDir,
				attempt( `disjoint-run-${ index }`, [
					`acct_disjoint_${ index }/account:provider-writes`,
				] )
			)
		)
	);
	const orderedPaths = [ relevantPath, ...disjointPaths ].toSorted();
	const slowFirstPath = orderedPaths[ 0 ];
	const resolvedPath = disjointPaths.toSorted().at( -1 ) as string;
	const slowContents = await readFile( slowFirstPath, 'utf8' );
	await writeFile(
		slowFirstPath,
		`${ ' '.repeat( 8 * 1024 * 1024 ) }${ slowContents }`
	);

	const discovery = findUnresolvedProviderWriteAttempts(
		lockDir,
		[ 'acct_x/account:provider-writes' ],
		'current-run'
	);
	await new Promise( ( resolveDelay ) => setTimeout( resolveDelay, 5 ) );
	await resolveProviderWriteAttempt( resolvedPath );

	await expect( discovery ).resolves.toHaveLength( 1 );
} );

test( 'discovery rejects a symlinked attempt entry', async () => {
	const lockDir = await journalDirectory();
	const attemptPath = await openProviderWriteAttempt(
		lockDir,
		attempt( 'run-a', [ 'acct_x/account:provider-writes' ] )
	);
	await symlink(
		attemptPath,
		join( lockDir, 'provider-write-attempts', 'linked.json' )
	);

	await expect(
		findUnresolvedProviderWriteAttempts(
			lockDir,
			[ 'acct_x/account:provider-writes' ],
			'run-b'
		)
	).rejects.toThrow( /symbolic link|regular file/i );
} );

test( 'discovery rejects a filename that does not bind the attempt identity', async () => {
	const lockDir = await journalDirectory();
	const attemptPath = await openProviderWriteAttempt(
		lockDir,
		attempt( 'run-a', [ 'acct_x/account:provider-writes' ] )
	);
	await writeFile(
		join( lockDir, 'provider-write-attempts', 'wrong-name.json' ),
		await readFile( attemptPath )
	);

	await expect(
		findUnresolvedProviderWriteAttempts(
			lockDir,
			[ 'acct_x/account:provider-writes' ],
			'run-b'
		)
	).rejects.toThrow( /filename.*identity/i );
	expect( basename( attemptPath ) ).not.toBe( 'wrong-name.json' );
} );
