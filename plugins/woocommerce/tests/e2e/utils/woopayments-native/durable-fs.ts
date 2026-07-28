import { createHash } from 'node:crypto';
import { open, rename, unlink } from 'node:fs/promises';

/**
 * Crash-safety primitives shared by the WooPayments resource lock and
 * quarantine stores. Both persist small JSON records that must survive an
 * abrupt process death, so they need the same recipe: write to a unique
 * temporary file, fsync it, rename it into place, then fsync the directory.
 * Keeping one copy means a durability fix cannot land in only half of them.
 */

export function sha256( value: string ): string {
	return createHash( 'sha256' ).update( value ).digest( 'hex' );
}

export async function syncDirectory( directoryPath: string ): Promise< void > {
	const directory = await open( directoryPath, 'r' );
	try {
		await directory.sync();
	} finally {
		await directory.close();
	}
}

export async function writeDurableJson(
	directoryPath: string,
	filePath: string,
	payload: unknown,
	temporarySuffix: string
): Promise< void > {
	const temporaryPath = `${ filePath }.tmp-${ temporarySuffix }`;
	const temporary = await open( temporaryPath, 'wx', 0o600 );

	try {
		try {
			await temporary.writeFile( `${ JSON.stringify( payload ) }\n` );
			await temporary.sync();
		} finally {
			await temporary.close();
		}
		await rename( temporaryPath, filePath );
		await syncDirectory( directoryPath );
	} catch ( error ) {
		await unlink( temporaryPath ).catch( () => {} );
		throw error;
	}
}
