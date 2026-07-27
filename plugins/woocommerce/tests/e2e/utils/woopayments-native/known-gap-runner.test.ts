import { spawnSync } from 'node:child_process';

import { expect, test } from '@playwright/test';

const cases = [
	{ caseName: 'exact', expectedExitCode: 0 },
	{ caseName: 'wrong', expectedExitCode: 1 },
	{ caseName: 'pass', expectedExitCode: 1 },
	{ caseName: 'cleanup', expectedExitCode: 1 },
	{ caseName: 'retry', expectedExitCode: 1 },
];

for ( const { caseName, expectedExitCode } of cases ) {
	test( `${ caseName } known-gap result exits ${ expectedExitCode }`, () => {
		const result = spawnSync(
			'pnpm',
			[
				'exec',
				'playwright',
				'test',
				'--config=tests/e2e/envs/woopayments-native/test-fixtures/known-gap/playwright.config.ts',
			],
			{
				cwd: process.cwd(),
				encoding: 'utf8',
				timeout: 15_000,
				env: {
					...process.env,
					KNOWN_GAP_CASE: caseName,
				},
			}
		);

		expect( result.error ).toBeUndefined();
		expect( result.status, `${ result.stdout }\n${ result.stderr }` ).toBe(
			expectedExitCode
		);
	} );
}
