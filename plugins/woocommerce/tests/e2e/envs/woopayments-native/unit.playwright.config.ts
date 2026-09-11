import { defineConfig } from '@playwright/test';

export default defineConfig( {
	testDir: '../../utils/woopayments-native',
	testMatch: '**/*.test.ts',
	fullyParallel: false,
	retries: 0,
	workers: 1,
	reporter: 'list',
} );
