import defaultConfig, {
	ADMIN_STATE_PATH,
	reporter,
	TESTS_ROOT_PATH,
} from '../../playwright.config';

const wooPaymentsSpecs = '**/tests/woopayments-native/**/*.spec.ts';
const serializedProjectWorkerLimit = 1;
const readonlyProjectName = 'woopayments-native-readonly';

export function requestedProjectNames( argv: string[] ): string[] {
	const projects: string[] = [];
	for ( let index = 0; index < argv.length; index++ ) {
		const argument = argv[ index ];
		if ( argument === '--project' && argv[ index + 1 ] ) {
			projects.push( argv[ ++index ] );
		} else if ( argument.startsWith( '--project=' ) ) {
			projects.push( argument.slice( '--project='.length ) );
		}
	}
	return projects;
}

const requestedProjects = requestedProjectNames( process.argv.slice( 2 ) );
const readonlySetupSelected =
	requestedProjects.length === 0 ||
	requestedProjects.includes( readonlyProjectName );

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
			name: readonlyProjectName,
			testMatch: wooPaymentsSpecs,
			use: { storageState: ADMIN_STATE_PATH },
			grepInvert:
				/@woopayments-provider|@woopayments-transition|@woopayments-extension-compat/,
			metadata: {
				woopaymentsReadonlySetup: readonlySetupSelected,
				...( readonlySetupSelected
					? { woopaymentsAdminStatePath: ADMIN_STATE_PATH }
					: {} ),
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
				woopaymentsReadonlySetup: false,
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
			metadata: {
				woopaymentsReadonlySetup: false,
			},
			retries: 0,
			workers: serializedProjectWorkerLimit,
		},
		{
			name: 'woopayments-native-ci-profile-skips',
			testMatch: '**/tests/woopayments-native/profile-dispositions.ci.ts',
			metadata: {
				woopaymentsReadonlySetup: false,
			},
			retries: 0,
			workers: serializedProjectWorkerLimit,
		},
		{
			name: 'woopayments-native-transition',
			testMatch: wooPaymentsSpecs,
			grep: /@woopayments-transition/,
			metadata: {
				woopaymentsReadonlySetup: false,
				woopaymentsWorkerLimit: serializedProjectWorkerLimit,
			},
			retries: 0,
			workers: serializedProjectWorkerLimit,
		},
	],
};
