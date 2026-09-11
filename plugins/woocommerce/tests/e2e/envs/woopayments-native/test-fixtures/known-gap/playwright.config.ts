import { defineConfig } from '@playwright/test';

export default defineConfig( {
	testDir: '.',
	testMatch: 'known-gap.spec.ts',
	fullyParallel: false,
	retries: process.env.KNOWN_GAP_CASE === 'retry' ? 1 : 0,
	workers: 1,
	reporter: [ [ '../../../../reporters/woopayments-known-gaps.ts' ] ],
} );
