import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const binDirectory = dirname( fileURLToPath( import.meta.url ) );
const validatorPath = fileURLToPath( import.meta.url );
const packageRoot = resolve( binDirectory, '../../..' );
const wooPaymentsTestRoot = 'tests/e2e/tests/woopayments-native';
const providerPilotOrder = [
	'shopper-card-payment',
	'merchant-transaction-navigation',
	'merchant-manual-capture',
];
const transitionPilotOrder = [ 'saved-method-cutover' ];

const listTests = ( configPath ) => {
	let output;

	try {
		output = execFileSync(
			'pnpm',
			[
				'exec',
				'playwright',
				'test',
				`--config=${ configPath }`,
				'--list',
				'--reporter=json',
				wooPaymentsTestRoot,
			],
			{
				cwd: packageRoot,
				encoding: 'utf8',
				env: {
					...process.env,
					BASE_URL: process.env.BASE_URL ?? 'http://localhost:8086',
					CI: '1',
				},
				maxBuffer: 50 * 1024 * 1024,
			}
		);
	} catch ( error ) {
		if ( typeof error.stdout !== 'string' ) {
			throw error;
		}
		output = error.stdout;
	}

	const report = JSON.parse( output );
	const unexpectedErrors = report.errors.filter(
		( error ) => ! error.message.includes( 'No tests found.' )
	);

	if ( unexpectedErrors.length > 0 ) {
		throw new Error(
			unexpectedErrors.map( ( error ) => error.message ).join( '\n' )
		);
	}

	const projects = new Map(
		report.config.projects.map( ( project ) => [ project.name, project ] )
	);
	const tests = [];

	const collectTests = ( suite ) => {
		for ( const spec of suite.specs ?? [] ) {
			for ( const test of spec.tests ) {
				tests.push( {
					file: spec.file,
					projectName: test.projectName,
					tags: spec.tags ?? [],
					title: spec.title,
				} );
			}
		}
		for ( const childSuite of suite.suites ?? [] ) {
			collectTests( childSuite );
		}
	};

	for ( const suite of report.suites ) {
		collectTests( suite );
	}

	return { projects, tests };
};

const pilotNames = ( tests, projectName, canonicalOrder ) => {
	const names = tests
		.filter(
			( test ) =>
				test.projectName === projectName &&
				test.file.includes( '/pilots/' )
		)
		.map( ( test ) =>
			test.file
				.split( '/' )
				.at( -1 )
				.replace( /\.spec\.ts$/, '' )
		);

	return names.toSorted( ( left, right ) => {
		const leftIndex = canonicalOrder.indexOf( left );
		const rightIndex = canonicalOrder.indexOf( right );

		if ( leftIndex === -1 || rightIndex === -1 ) {
			return left.localeCompare( right );
		}

		return leftIndex - rightIndex;
	} );
};

export const validateWooPaymentsProjectRouting = () => {
	const base = listTests( 'tests/e2e/playwright.config.ts' );
	const wooPayments = listTests(
		'tests/e2e/envs/woopayments-native/playwright.config.ts'
	);
	const providerProject = 'woopayments-native-provider';
	const readonlyProject = 'woopayments-native-readonly';
	const transitionProject = 'woopayments-native-transition';

	return {
		baseProjectsCollectWooPayments: base.tests.length > 0,
		readonlyCollectsProviderTests: wooPayments.tests.some(
			( test ) =>
				test.projectName === readonlyProject &&
				test.tags.includes( 'woopayments-provider' )
		),
		providerCollectsExactly: pilotNames(
			wooPayments.tests,
			providerProject,
			providerPilotOrder
		),
		transitionCollectsExactly: pilotNames(
			wooPayments.tests,
			transitionProject,
			transitionPilotOrder
		),
		everyReadonlyRetryCount:
			wooPayments.projects.get( readonlyProject )?.retries,
		everyProviderRetryCount:
			wooPayments.projects.get( providerProject )?.retries,
		everyTransitionRetryCount:
			wooPayments.projects.get( transitionProject )?.retries,
		providerWorkerCount:
			wooPayments.projects.get( providerProject )?.metadata
				.woopaymentsWorkerLimit,
		transitionWorkerCount:
			wooPayments.projects.get( transitionProject )?.metadata
				.woopaymentsWorkerLimit,
		futureWooPaymentsSpecProjects: [
			...new Set(
				wooPayments.tests
					.filter(
						( test ) =>
							test.title ===
							'future WooPayments nested routing sentinel'
					)
					.map( ( test ) => test.projectName )
			),
		].toSorted(),
	};
};

if ( process.argv[ 1 ] && resolve( process.argv[ 1 ] ) === validatorPath ) {
	console.log(
		JSON.stringify( validateWooPaymentsProjectRouting(), null, 2 )
	);
}
