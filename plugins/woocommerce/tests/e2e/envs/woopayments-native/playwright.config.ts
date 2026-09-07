import defaultConfig, {
	ADMIN_STATE_PATH,
	reporter,
	TESTS_ROOT_PATH,
} from '../../playwright.config';

const wooPaymentsSpecs = '**/tests/woopayments-native/**/*.spec.ts';
const serializedProjectWorkerLimit = 1;

export default {
	...defaultConfig,
	globalSetup: `${ __dirname }/readonly-global-setup.ts`,
	// The known-gap gate is a WooPayments policy, so it belongs to this env
	// rather than to every Core E2E project inheriting the root config.
	reporter: [
		...reporter,
		[ `${ TESTS_ROOT_PATH }/reporters/woopayments-known-gaps.ts` ],
	],
	retries: 0,
	projects: [
		{
			name: 'woopayments-native-readonly',
			testMatch: wooPaymentsSpecs,
			grepInvert:
				/@woopayments-provider|@woopayments-transition|@woopayments-extension-compat/,
			metadata: {
				woopaymentsAdminStatePath: ADMIN_STATE_PATH,
			},
			retries: 0,
			workers: serializedProjectWorkerLimit,
		},
		{
			name: 'woopayments-native-provider',
			testMatch: wooPaymentsSpecs,
			grep: /@woopayments-provider/,
			grepInvert: /@woopayments-transition/,
			metadata: {
				woopaymentsWorkerLimit: serializedProjectWorkerLimit,
			},
			retries: 0,
			workers: serializedProjectWorkerLimit,
		},
		{
			name: 'woopayments-native-extension-compat',
			testMatch: wooPaymentsSpecs,
			grep: /@woopayments-extension-compat/,
			grepInvert: /@woopayments-provider|@woopayments-transition/,
			retries: 0,
			workers: serializedProjectWorkerLimit,
		},
		{
			name: 'woopayments-native-transition',
			testMatch: wooPaymentsSpecs,
			grep: /@woopayments-transition/,
			metadata: {
				woopaymentsWorkerLimit: serializedProjectWorkerLimit,
			},
			retries: 0,
			workers: serializedProjectWorkerLimit,
		},
	],
};
