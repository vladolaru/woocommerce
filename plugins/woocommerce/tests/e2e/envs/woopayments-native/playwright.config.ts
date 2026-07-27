import defaultConfig from '../../playwright.config';

const wooPaymentsSpecs = '**/tests/woopayments-native/**/*.spec.ts';
const serializedProjectWorkerLimit = 1;

export default {
	...defaultConfig,
	retries: 0,
	projects: [
		{
			name: 'woopayments-native-readonly',
			testMatch: wooPaymentsSpecs,
			grepInvert: /@woopayments-provider|@woopayments-transition/,
			retries: 0,
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
