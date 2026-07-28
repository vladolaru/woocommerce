import { mkdtemp } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/**
 * Helpers shared by the resource lock, quarantine, and pilot runtime test
 * suites. They all need a throwaway lock directory and a scoped override of
 * E2E_WOOPAYMENTS_LOCK_DIR, so the save/restore dance lives here rather than
 * being copied into each suite.
 *
 * This is deliberately a separate module from resource-quarantine.ts, whose
 * export surface is asserted to stay minimal.
 */

export async function temporaryLockDirectory(
	prefix: string
): Promise< string > {
	return mkdtemp( join( tmpdir(), prefix ) );
}

export function useLockDirectory( directory: string ): () => void {
	const previous = process.env.E2E_WOOPAYMENTS_LOCK_DIR;
	process.env.E2E_WOOPAYMENTS_LOCK_DIR = directory;
	return () => {
		if ( previous === undefined ) {
			delete process.env.E2E_WOOPAYMENTS_LOCK_DIR;
		} else {
			process.env.E2E_WOOPAYMENTS_LOCK_DIR = previous;
		}
	};
}
