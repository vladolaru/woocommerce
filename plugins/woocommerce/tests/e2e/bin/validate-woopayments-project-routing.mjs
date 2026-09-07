import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { dirname, isAbsolute, join, relative, resolve } from 'node:path';
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
// A closed ledger row whose targets include a test module under this tree
// must be proven by a collected Playwright annotation; rows whose targets are
// only PHP/JS unit tests are closed at that lower layer directly.
const e2eTestTreePrefix = 'plugins/woocommerce/tests/e2e/tests/';
const providerInvolvementTags = [
	'woopayments-provider',
	'woopayments-transition',
];
// Modules that drive the payment provider or handle its records. Reaching any
// of them is what makes a test provider-involved in substance, which must
// match the tags it declares.
const providerMachineryPrefixes = [
	'tests/e2e/utils/woopayments-native/drivers/',
	'tests/e2e/utils/woopayments-native/provider-',
];
// Matches `import x from '...'`, `export … from '...'` and bare `import '...'`.
// Deliberately permissive: over-matching costs a false positive that a human
// resolves by adding a tag, while under-matching silently ungates a test.
const importSourcePattern = /(?:\bfrom|\bimport)\s*['"]([^'"]+)['"]/g;

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
				profileUnavailable: collectedTest.annotations.some(
					( candidate ) => candidate.type === 'profile-unavailable'
				),
				profileUnavailableReason: collectedTest.annotations.find(
					( candidate ) => candidate.type === 'profile-unavailable'
				)?.description,
				tags: collectedTest.tags,
				title: collectedTest.title,
			} ) )
	);

const canonicalAnnotationPath = ( file, packageDirectory ) =>
	isAbsolute( file )
		? resolve( file )
		: resolve( packageDirectory, 'tests/e2e/tests', file );

const canonicalLedgerTargetPath = ( file, packageDirectory ) =>
	resolve( packageDirectory, '../..', file );

const e2eSpecTargetsForRow = ( row ) =>
	row.target_path
		.split( ';' )
		.filter(
			( targetPath ) =>
				targetPath.startsWith( e2eTestTreePrefix ) &&
				targetPath.endsWith( '.ts' )
		);

const isInsideWooPaymentsTestTree = ( candidate, packageDirectory ) =>
	candidate.startsWith(
		`${ resolve( packageDirectory, wooPaymentsTestRoot ) }/`
	);

const readModuleSource = ( modulePath ) => {
	for ( const candidate of [
		modulePath,
		`${ modulePath }.ts`,
		join( modulePath, 'index.ts' ),
	] ) {
		if ( existsSync( candidate ) && statSync( candidate ).isFile() ) {
			return readFileSync( candidate, 'utf8' );
		}
	}

	return null;
};

/**
 * Whether the module defining a test reaches provider machinery. The walk
 * follows relative imports that stay inside the WooPayments-native test tree,
 * because a spec commonly defines its tests in a shared scenario module and
 * that module is where the provider work lives. It deliberately does not
 * descend into the fixtures barrel or the wider utils tree: every spec imports
 * the barrel, and the barrel itself imports provider machinery, so following
 * it would mark every test provider-involved.
 */
export const reachesProviderMachinery = ( entryFile, packageDirectory ) => {
	const visited = new Set();
	const pending = [ entryFile ];

	while ( pending.length > 0 ) {
		const current = pending.pop();

		if ( visited.has( current ) ) {
			continue;
		}
		visited.add( current );

		const source = readModuleSource( current );

		if ( source === null ) {
			continue;
		}

		for ( const match of source.matchAll( importSourcePattern ) ) {
			const specifier = match[ 1 ];

			if ( ! specifier.startsWith( '.' ) ) {
				continue;
			}

			const resolved = resolve( dirname( current ), specifier );
			const relativePath = relative( packageDirectory, resolved );

			if (
				providerMachineryPrefixes.some( ( prefix ) =>
					relativePath.startsWith( prefix )
				)
			) {
				return true;
			}

			if ( isInsideWooPaymentsTestTree( resolved, packageDirectory ) ) {
				pending.push( resolved );
			}
		}
	}

	return false;
};

/**
 * Provider readiness and provider-resource quarantine are gated on the
 * provider tags, so a test that reaches provider machinery without carrying
 * one runs against the real provider with no account assertion, no callback
 * proof and no quarantine check. Mis-tagging used to be harmless because
 * every test was gated; now it is the whole decision, so it is checked here.
 */
export const validateProviderMachineryTags = ( tests, packageDirectory ) => {
	const reachabilityByFile = new Map();
	const violations = new Set();

	for ( const collectedTest of tests ) {
		const definingFile = canonicalAnnotationPath(
			collectedTest.file,
			packageDirectory
		);

		if ( ! reachabilityByFile.has( definingFile ) ) {
			reachabilityByFile.set(
				definingFile,
				reachesProviderMachinery( definingFile, packageDirectory )
			);
		}

		if ( ! reachabilityByFile.get( definingFile ) ) {
			continue;
		}

		if (
			collectedTest.tags.some( ( tag ) =>
				providerInvolvementTags.includes( tag )
			)
		) {
			continue;
		}

		violations.add( `${ collectedTest.file }::${ collectedTest.title }` );
	}

	if ( violations.size > 0 ) {
		throw new Error(
			'WooPayments tests reaching provider machinery must carry @woopayments-provider or @woopayments-transition, otherwise provider readiness and provider-resource quarantine are skipped for them:\n' +
				[ ...violations ].toSorted().join( '\n' )
		);
	}
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
		if (
			row.migration_state !== 'closed' ||
			// A retired contract was never migrated, so no native test carries
			// its annotation; its retained contract is checked on its own row.
			row.native_support_state === 'not-applicable-retired'
		) {
			continue;
		}

		const e2eSpecTargets = e2eSpecTargetsForRow( row );

		// Rows whose targets are only PHP/JS unit tests close at that lower
		// layer directly; the target-existence check lives in the ledger
		// validator.
		if ( e2eSpecTargets.length === 0 ) {
			continue;
		}

		const record = recordsByDescription.get( row.case_id )?.[ 0 ];
		if ( ! record ) {
			throw new Error(
				`Closed ledger contract has no collected woopayments-contract annotation: ${ row.case_id }`
			);
		}
		const isUnavailableExtensionProfile =
			record.expectedStatus === 'skipped' &&
			record.profileUnavailable === true &&
			record.tags?.includes( 'woopayments-extension-compat' );
		const isUnavailableProviderProfile =
			record.expectedStatus === 'skipped' &&
			record.profileUnavailable === true &&
			record.profileUnavailableReason?.startsWith( 'RULE 5:' ) &&
			record.tags?.includes( 'woopayments-provider' );
		if (
			record.expectedStatus !== 'passed' &&
			! isUnavailableExtensionProfile &&
			! isUnavailableProviderProfile
		) {
			throw new Error(
				`Closed ledger contract annotation must expect to pass: ${ row.case_id }`
			);
		}
		if ( record.title !== row.target_contract ) {
			throw new Error(
				`Closed ledger contract annotation has the wrong test title: ${ row.case_id }`
			);
		}

		const annotationPath = canonicalAnnotationPath(
			record.file,
			packageDirectory
		);
		if (
			! e2eSpecTargets.some(
				( targetPath ) =>
					canonicalLedgerTargetPath(
						targetPath,
						packageDirectory
					) === annotationPath
			)
		) {
			throw new Error(
				`Closed ledger contract annotation has the wrong target file: ${ row.case_id }`
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
	validateContractAnnotationBindings(
		ledger.rows,
		contractAnnotationRecords,
		packageRoot
	);
	validateProviderMachineryTags( wooPayments.tests, packageRoot );

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
