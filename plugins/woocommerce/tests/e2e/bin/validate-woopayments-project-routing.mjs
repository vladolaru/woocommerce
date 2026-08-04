import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, isAbsolute, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { parseContractMap } from './lib/woopayments-contract-map.mjs';

const binDirectory = dirname( fileURLToPath( import.meta.url ) );
const validatorPath = fileURLToPath( import.meta.url );
const packageRoot = resolve( binDirectory, '../../..' );
const wooPaymentsTestRoot = 'tests/e2e/tests/woopayments-native';
const providerProject = 'woopayments-native-provider';
const readonlyProject = 'woopayments-native-readonly';
const transitionProject = 'woopayments-native-transition';
const providerPilotOrder = [
	'merchant-transaction-navigation',
	'merchant-manual-capture',
];
const transitionPilotOrder = [ 'saved-method-cutover' ];
const cardPaymentScenarioContract =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:53::Successful purchase › Carding protection false › using a basic card';
const cardPaymentScenarioFile = 'woopayments-native/scenarios/card-payment.ts';
const cardPaymentScenarioTitle =
	'Successful purchase › Carding protection false › using a basic card';
const cardPaymentScenarioTags = [
	'woopayments-native',
	'woopayments-provider',
	'woopayments-pr',
];

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
					annotations: test.annotations ?? [],
					expectedStatus: test.expectedStatus,
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

const collectContractAnnotationRecords = ( tests ) =>
	tests.flatMap( ( collectedTest ) =>
		collectedTest.annotations
			.filter(
				( annotation ) => annotation.type === 'woopayments-contract'
			)
			.map( ( annotation ) => ( {
				description: annotation.description,
				expectedStatus: collectedTest.expectedStatus,
				file: collectedTest.file,
				projectName: collectedTest.projectName,
				tags: collectedTest.tags,
				title: collectedTest.title,
			} ) )
	);

const hasCardPaymentContractAnnotation = ( collectedTest ) =>
	collectedTest.annotations.some(
		( annotation ) =>
			annotation.type === 'woopayments-contract' &&
			annotation.description === cardPaymentScenarioContract
	);

export const validateCardPaymentScenarioOwnership = ( tests ) => {
	const scenarioTests = tests.filter(
		( collectedTest ) =>
			collectedTest.file === cardPaymentScenarioFile ||
			collectedTest.title === cardPaymentScenarioTitle ||
			hasCardPaymentContractAnnotation( collectedTest )
	);

	if ( scenarioTests.length !== 1 ) {
		throw new Error(
			`Card payment scenario must be collected exactly once: found ${ scenarioTests.length }`
		);
	}

	const scenarioTest = scenarioTests[ 0 ];

	if ( scenarioTest.file !== cardPaymentScenarioFile ) {
		throw new Error(
			`Card payment scenario must use its canonical relative file path: ${ cardPaymentScenarioFile }`
		);
	}
	if ( scenarioTest.title !== cardPaymentScenarioTitle ) {
		throw new Error(
			`Card payment scenario must use its exact title: ${ cardPaymentScenarioTitle }`
		);
	}
	if ( scenarioTest.expectedStatus !== 'passed' ) {
		throw new Error( 'Card payment scenario must expect to pass' );
	}

	const contractAnnotations = scenarioTest.annotations.filter(
		( annotation ) =>
			annotation.type === 'woopayments-contract' &&
			annotation.description === cardPaymentScenarioContract
	);
	if ( contractAnnotations.length !== 1 ) {
		throw new Error(
			'Card payment scenario must include exactly one required contract annotation'
		);
	}

	for ( const requiredTag of cardPaymentScenarioTags ) {
		if ( ! scenarioTest.tags.includes( requiredTag ) ) {
			throw new Error(
				`Card payment scenario must include required tag: ${ requiredTag }`
			);
		}
	}

	if ( scenarioTest.projectName !== providerProject ) {
		throw new Error(
			`Card payment scenario must be owned by project: ${ providerProject }`
		);
	}
};

const owningProjectForTags = ( tags ) => {
	if ( tags.includes( 'woopayments-transition' ) ) {
		return transitionProject;
	}
	if ( tags.includes( 'woopayments-provider' ) ) {
		return providerProject;
	}
	return readonlyProject;
};

const canonicalAnnotationPath = ( file, packageDirectory ) =>
	isAbsolute( file )
		? resolve( file )
		: resolve( packageDirectory, 'tests/e2e/tests', file );

const canonicalLedgerTargetPath = ( file, packageDirectory ) =>
	resolve( packageDirectory, '../..', file );

const annotationTargetPathForRow = ( row ) => {
	const targetPaths = row.target_path.split( ';' );

	if ( targetPaths.length === 1 ) {
		return targetPaths[ 0 ];
	}

	const wooPaymentsNativeE2eSpecs = targetPaths.filter(
		( targetPath ) =>
			targetPath.startsWith(
				'plugins/woocommerce/tests/e2e/tests/woopayments-native/'
			) && targetPath.endsWith( '.spec.ts' )
	);

	if ( wooPaymentsNativeE2eSpecs.length !== 1 ) {
		throw new Error(
			`Terminal multi-target ledger contract must contain exactly one WooPayments-native E2E spec target: ${ row.case_id }`
		);
	}

	return wooPaymentsNativeE2eSpecs[ 0 ];
};

export const validateContractAnnotationBindings = (
	ledgerRows,
	annotationRecords,
	packageDirectory
) => {
	const ledgerCaseIds = new Set( ledgerRows.map( ( row ) => row.case_id ) );
	const recordsByDescription = new Map();

	for ( const record of annotationRecords ) {
		if ( ! ledgerCaseIds.has( record.description ) ) {
			throw new Error(
				`woopayments-contract annotation does not resolve to a ledger contract: ${ record.description }`
			);
		}

		const matchingRecords =
			recordsByDescription.get( record.description ) ?? [];
		matchingRecords.push( record );
		recordsByDescription.set( record.description, matchingRecords );
	}

	for ( const [ description, records ] of recordsByDescription ) {
		if ( records.length > 1 ) {
			throw new Error(
				`Duplicate woopayments-contract annotation records for ledger contract: ${ description }`
			);
		}
	}

	for ( const row of ledgerRows ) {
		if ( ! [ 'verified', 'closed' ].includes( row.migration_state ) ) {
			continue;
		}

		const record = recordsByDescription.get( row.case_id )?.[ 0 ];
		if ( ! record ) {
			throw new Error(
				`Terminal ledger contract has no collected woopayments-contract annotation: ${ row.case_id }`
			);
		}
		if ( record.expectedStatus !== 'passed' ) {
			throw new Error(
				`Terminal ledger contract annotation must expect to pass: ${ row.case_id }`
			);
		}
		if (
			canonicalAnnotationPath( record.file, packageDirectory ) !==
			canonicalLedgerTargetPath(
				annotationTargetPathForRow( row ),
				packageDirectory
			)
		) {
			throw new Error(
				`Terminal ledger contract annotation has the wrong target file: ${ row.case_id }`
			);
		}
		if ( record.title !== row.target_contract ) {
			throw new Error(
				`Terminal ledger contract annotation has the wrong test title: ${ row.case_id }`
			);
		}
		if ( record.projectName !== owningProjectForTags( record.tags ) ) {
			throw new Error(
				`Terminal ledger contract annotation has the wrong owning project: ${ row.case_id }`
			);
		}
	}
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
	const ledger = parseContractMap(
		readFileSync(
			resolve(
				packageRoot,
				'tests/e2e/tests/woopayments-native/client-contract-map.tsv'
			),
			'utf8'
		)
	);
	const contractAnnotationRecords = collectContractAnnotationRecords(
		wooPayments.tests
	);
	validateCardPaymentScenarioOwnership( wooPayments.tests );
	validateContractAnnotationBindings(
		ledger.rows,
		contractAnnotationRecords,
		packageRoot
	);

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
		wooPaymentsContractAnnotations: [
			...new Set(
				contractAnnotationRecords.map(
					( annotation ) => annotation.description
				)
			),
		].toSorted(),
	};
};

if ( process.argv[ 1 ] && resolve( process.argv[ 1 ] ) === validatorPath ) {
	console.log(
		JSON.stringify( validateWooPaymentsProjectRouting(), null, 2 )
	);
}
