import { mkdtemp, readFile, readdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { expect, test } from '@playwright/test';

test( 'publishes one complete create-if-absent JSON record', async () => {
	const directoryPath = await mkdtemp(
		join( tmpdir(), 'woopayments-durable-fs-test-' )
	);
	const filePath = join( directoryPath, 'resource.lock' );
	const durableFs = ( await import( './durable-fs' ) ) as unknown as {
		writeNewDurableJson: (
			directory: string,
			path: string,
			payload: unknown,
			suffix: string
		) => Promise< boolean >;
	};

	try {
		expect( typeof durableFs.writeNewDurableJson ).toBe( 'function' );

		expect(
			await durableFs.writeNewDurableJson(
				directoryPath,
				filePath,
				{ owner: 'first' },
				'first'
			)
		).toBe( true );
		expect(
			await durableFs.writeNewDurableJson(
				directoryPath,
				filePath,
				{ owner: 'second' },
				'second'
			)
		).toBe( false );

		expect( await readFile( filePath, 'utf8' ) ).toBe(
			'{"owner":"first"}\n'
		);
		expect( await readdir( directoryPath ) ).toEqual( [ 'resource.lock' ] );
	} finally {
		await rm( directoryPath, { recursive: true, force: true } );
	}
} );
