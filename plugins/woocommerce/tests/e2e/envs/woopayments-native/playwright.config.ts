import defaultConfig from '../../playwright.config';

const pilotSpecs = '**/tests/woopayments-native/pilots/*.spec.ts';

export default {
	...defaultConfig,
	retries: 0,
	projects: [
		{
			name: 'woopayments-native-readonly',
			testMatch: pilotSpecs,
			grepInvert: /@woopayments-provider|@woopayments-transition/,
			retries: process.env.CI ? 1 : 0,
		},
		{
			name: 'woopayments-native-provider',
			testMatch: pilotSpecs,
			grep: /@woopayments-provider/,
			grepInvert: /@woopayments-transition/,
			retries: 0,
			workers: 1,
		},
		{
			name: 'woopayments-native-transition',
			testMatch: pilotSpecs,
			grep: /@woopayments-transition/,
			retries: 0,
			workers: 1,
		},
	],
};
