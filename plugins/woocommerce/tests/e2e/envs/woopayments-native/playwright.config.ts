import defaultConfig, {
	ADMIN_STATE_PATH,
	reporter,
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
	reporter,
	retries: 0,
	// One worker for the whole run, not only per project: specs in different projects toggle store-wide state (the
	// native payments state, WooPay on or off), and a run that selects several projects would otherwise run them side
	// by side.
	workers: 1,
	projects: [
		{
			name: readonlyProjectName,
			testMatch: wooPaymentsSpecs,
			use: { storageState: ADMIN_STATE_PATH },
			grepInvert:
				/@woopayments-provider|@woopayments-transition|@woopayments-extension-compat/,
			metadata: {
				woopaymentsReadonlySetup: readonlySetupSelected,
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
			},
			retries: 0,
			workers: serializedProjectWorkerLimit,
		},
	],
};
