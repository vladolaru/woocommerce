import { defineConfig } from '@playwright/test';

export default defineConfig( {
	// Narrowed in Batch F (T.4 Task 16) once the harness drivers were gone:
	// the provider helper's own test plus the one surviving unit test for a
	// kept `envs/woopayments-native/` module.
	testDir: '../..',
	testMatch: [
		'utils/woopayments.test.ts',
		'envs/woopayments-native/readonly-global-setup.test.ts',
	],
	fullyParallel: false,
	retries: 0,
	workers: 1,
	reporter: 'list',
} );
