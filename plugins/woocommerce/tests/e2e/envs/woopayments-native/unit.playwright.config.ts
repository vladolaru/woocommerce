import { defineConfig } from '@playwright/test';

export default defineConfig( {
	testDir: '../../utils',
	// The driver tests under `woopayments-native/` still cover modules live
	// specs import (P2-T); only Batch F, once every driver is gone, narrows
	// this to the helper test alone.
	testMatch: [ 'woopayments.test.ts', 'woopayments-native/**/*.test.ts' ],
	fullyParallel: false,
	retries: 0,
	workers: 1,
	reporter: 'list',
} );
