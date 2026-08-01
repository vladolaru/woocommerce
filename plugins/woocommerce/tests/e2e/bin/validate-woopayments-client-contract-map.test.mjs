import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
	existsSync,
	mkdirSync,
	mkdtempSync,
	readFileSync,
	rmdirSync,
	rmSync,
	symlinkSync,
	unlinkSync,
	writeFileSync,
} from 'node:fs';
import {
	dirname,
	join,
	relative,
	resolve,
	sep as pathSeparator,
} from 'node:path';
import { tmpdir } from 'node:os';
import { afterEach, test } from 'node:test';
import { fileURLToPath } from 'node:url';

import {
	parseContractMap,
	validateContractMap,
	validateDispositionTransition,
	validateStateTransition,
} from './lib/woopayments-contract-map.mjs';
import {
	assertClosureBundleCoverage,
	collectClosureBundlePaths,
	REQUIRED_CLOSURE_REVIEW_ROLES,
	validateMigrationEvidence,
} from './lib/woopayments-migration-evidence.mjs';
import { runCli } from './validate-woopayments-client-contract-map.mjs';

const binDirectory = dirname( fileURLToPath( import.meta.url ) );
const repositoryRoot = resolve( binDirectory, '../../../../..' );
const ledgerPath = resolve(
	binDirectory,
	'../tests/woopayments-native/client-contract-map.tsv'
);
const metadataPath = resolve(
	binDirectory,
	'../tests/woopayments-native/client-contract-map.meta.json'
);
const metadata = JSON.parse( readFileSync( metadataPath, 'utf8' ) );
const ledgerContent = readFileSync( ledgerPath, 'utf8' );
const contractMap = parseContractMap( ledgerContent );
const currentCommit = execFileSync(
	'git',
	[ '-C', repositoryRoot, 'rev-parse', 'HEAD' ],
	{ encoding: 'utf8' }
).trim();
const temporaryDirectories = [];
const externalTemporaryDirectories = [];
const temporaryFiles = [];
const temporaryTargetDirectories = new Set();

const cloneContractMap = ( source = contractMap ) => ( {
	headers: [ ...source.headers ],
	rows: source.rows.map( ( row ) => ( { ...row } ) ),
} );

const createEvidenceContext = ( row ) => ( {
	row,
	metadata,
	repositoryRoot,
} );

const calculateSourceBundleSha256 = (
	repositoryPaths,
	commit = currentCommit
) =>
	createHash( 'sha256' )
		.update(
			JSON.stringify(
				repositoryPaths.toSorted().map( ( repositoryPath ) => [
					repositoryPath,
					createHash( 'sha256' )
						.update(
							execFileSync( 'git', [
								'-C',
								repositoryRoot,
								'show',
								`${ commit }:${ repositoryPath }`,
							] )
						)
						.digest( 'hex' ),
				] )
			)
		)
		.digest( 'hex' );

const calculateCurrentSourceBundleSha256 = (
	repositoryPaths,
	sourceRepositoryRoot = repositoryRoot
) =>
	createHash( 'sha256' )
		.update(
			JSON.stringify(
				repositoryPaths.toSorted().map( ( repositoryPath ) => [
					repositoryPath,
					createHash( 'sha256' )
						.update(
							readFileSync(
								resolve( sourceRepositoryRoot, repositoryPath )
							)
						)
						.digest( 'hex' ),
				] )
			)
		)
		.digest( 'hex' );

const DEFAULT_SOURCE_TEST_PATHS = [
	'plugins/woocommerce/tests/e2e/utils/woopayments-native/known-gap-format.mjs',
];
const DEFAULT_SOURCE_TEST_SHA256 = calculateSourceBundleSha256(
	DEFAULT_SOURCE_TEST_PATHS
);

const createClosureEntry = ( row, overrides = {} ) => ( {
	contract_id: row.case_id,
	target: {
		path: row.target_path,
		contract: row.target_contract,
	},
	verification: [
		{
			command: 'pnpm test:e2e:woopayments:controller',
			exit_code: 0,
			summary: 'closure verification passed',
		},
	],
	reviews: REQUIRED_CLOSURE_REVIEW_ROLES.map( ( role ) => ( {
		role,
		verdict: 'APPROVE',
		source_test_sha256: DEFAULT_SOURCE_TEST_SHA256,
		summary: `${ role } closure review approved`,
	} ) ),
	...overrides,
} );

const serializeLegacyContractMap = () => {
	const legacyHeaders = [
		...contractMap.headers.slice( 0, 32 ),
		'accepted_disposition',
		'target_path',
		'implementation_owner',
		'closure_state',
	];

	return (
		[
			legacyHeaders,
			...contractMap.rows.map( ( row ) =>
				legacyHeaders.map( ( header ) =>
					header === 'closure_state'
						? row.migration_state
						: row[ header ]
				)
			),
		]
			.map( ( values ) => values.join( '\t' ) )
			.join( '\n' ) + '\n'
	);
};

const serializeContractMap = ( map ) =>
	[
		map.headers,
		...map.rows.map( ( row ) =>
			map.headers.map( ( header ) => row[ header ] )
		),
	]
		.map( ( values ) => values.join( '\t' ) )
		.join( '\n' ) + '\n';

const runHistoryComparison = ( currentMap, previousMap ) =>
	runCli( [ '--from-git-ref', 'HEAD' ], {
		ledgerContent: serializeContractMap( currentMap ),
		metadata,
		repositoryRoot,
		loadFromGitRef: () => serializeContractMap( previousMap ),
		log: () => {},
	} );

const repositoryRelativePath = ( absolutePath ) =>
	relative( repositoryRoot, absolutePath ).split( pathSeparator ).join( '/' );

const relativeModuleSpecifier = ( fromFile, toFile ) => {
	const relativePath = relative( dirname( fromFile ), toFile )
		.split( pathSeparator )
		.join( '/' )
		.replace( /\.(?:mjs|tsx?)$/, '' );

	return relativePath.startsWith( '.' )
		? relativePath
		: `./${ relativePath }`;
};
const repositoryRelativePathFromRoot = ( absolutePath, sourceRepositoryRoot ) =>
	relative( sourceRepositoryRoot, absolutePath )
		.split( pathSeparator )
		.join( '/' );

const createValidEvidence = (
	fixtureRow,
	overrides = {},
	sourceRepositoryRoot = repositoryRoot
) => {
	const isTerminal = [ 'verified', 'closed' ].includes(
		fixtureRow.migration_state
	);
	let sourceTestPaths;

	if ( Object.hasOwn( overrides, 'source_test_paths' ) ) {
		sourceTestPaths = overrides.source_test_paths;
	} else if ( isTerminal ) {
		sourceTestPaths = collectClosureBundlePaths(
			fixtureRow,
			sourceRepositoryRoot
		);
	} else {
		sourceTestPaths = [ ...DEFAULT_SOURCE_TEST_PATHS ];
	}

	let sourceTestSha256;

	if ( Object.hasOwn( overrides, 'source_test_sha256' ) ) {
		sourceTestSha256 = overrides.source_test_sha256;
	} else if ( isTerminal ) {
		sourceTestSha256 = calculateCurrentSourceBundleSha256(
			sourceTestPaths,
			sourceRepositoryRoot
		);
	} else {
		sourceTestSha256 = DEFAULT_SOURCE_TEST_SHA256;
	}

	const closures = ( overrides.closures ?? [] ).map( ( closure ) => ( {
		...closure,
		reviews: closure.reviews.map( ( review ) =>
			review.source_test_sha256 === DEFAULT_SOURCE_TEST_SHA256
				? { ...review, source_test_sha256: sourceTestSha256 }
				: review
		),
	} ) );

	return {
		schema_version: 2,
		slice_id: 'fixture-slice',
		wc_base_commit: '1'.repeat( 40 ),
		verified_at_commit: currentCommit,
		reference_contract_commit: '6dda1d4eb101f05c22f60882d67a22f75281ae45',
		contract_ids: [ fixtureRow.case_id ],
		targets: [
			{
				path: fixtureRow.target_path,
				contract: fixtureRow.target_contract,
			},
		],
		implementation_commits: [ '3'.repeat( 40 ) ],
		verification: [
			{
				command: 'pnpm test:e2e:woopayments:controller',
				exit_code: 0,
				summary: 'controller checks passed',
			},
		],
		reviews: [
			{
				role: 'spec',
				verdict: 'APPROVE',
				source_test_sha256: sourceTestSha256,
				summary: 'contract preserved',
			},
			{
				role: 'code',
				verdict: 'APPROVE',
				source_test_sha256: sourceTestSha256,
				summary: 'implementation approved',
			},
		],
		known_gaps: [],
		deferral: null,
		...overrides,
		source_test_paths: sourceTestPaths,
		source_test_sha256: sourceTestSha256,
		closures,
	};
};

const createRepositoryTemporaryDirectory = () => {
	const temporaryDirectory = mkdtempSync(
		join( binDirectory, '.contract-map-test-' )
	);

	temporaryDirectories.push( temporaryDirectory );
	return temporaryDirectory;
};

const createKnownGapEvidence = ( reference = 'issue:known-gap' ) => ( {
	id: 'WPNATIVE-GAP-0001',
	owner: 'woocommerce-e2e',
	reference,
	fingerprint: {
		error_name: 'Error',
		message_pattern: '^Native contract remains unavailable\\.$',
	},
} );

const createDecisionReadyDeferral = (
	reference = 'issue:inventory-deferral'
) => ( {
	blocker: 'The required external authority is unavailable',
	affected_scope: 'The selected WooPayments contract',
	no_allowlisted_action_reason:
		'No repository-local action can grant the external authority',
	reference,
	unlock_decision: 'Grant the required external account authority',
	quarantine_status: 'No shared resource was allocated',
} );

const createEvidenceFile = (
	row,
	overrides = {},
	sourceRepositoryRoot = repositoryRoot
) => {
	const temporaryDirectory =
		sourceRepositoryRoot === repositoryRoot
			? createRepositoryTemporaryDirectory()
			: mkdtempSync(
					join( sourceRepositoryRoot, '.contract-map-test-' )
			  );
	const evidencePath = join( temporaryDirectory, 'evidence.json' );
	const stateOverrides = {};

	if ( row.migration_state === 'deferred' ) {
		stateOverrides.implementation_commits = [];
		stateOverrides.verification = [];
		stateOverrides.deferral = createDecisionReadyDeferral(
			row.gap_or_decision_reference
		);
	}
	if ( row.native_support_state === 'known-gap' ) {
		stateOverrides.known_gaps = [
			createKnownGapEvidence( row.gap_or_decision_reference ),
		];
	}

	writeFileSync(
		evidencePath,
		`${ JSON.stringify(
			createValidEvidence(
				row,
				{
					...stateOverrides,
					...overrides,
				},
				sourceRepositoryRoot
			),
			null,
			2
		) }\n`
	);

	return repositoryRelativePathFromRoot( evidencePath, sourceRepositoryRoot );
};

const createExternalTemporaryFile = () => {
	const temporaryDirectory = mkdtempSync(
		join( tmpdir(), 'woocommerce-contract-map-' )
	);
	const filePath = join( temporaryDirectory, 'external-evidence.txt' );

	externalTemporaryDirectories.push( temporaryDirectory );
	writeFileSync( filePath, 'external contract evidence\n' );
	return filePath;
};

const createTemporaryGitRepository = () => {
	const temporaryDirectory = mkdtempSync(
		join( tmpdir(), 'woocommerce-contract-map-' )
	);

	externalTemporaryDirectories.push( temporaryDirectory );
	execFileSync( 'git', [ '-C', temporaryDirectory, 'init', '--quiet' ] );
	return temporaryDirectory;
};

const createTrackedClosureRepository = ( files ) => {
	const sourceRepositoryRoot = createTemporaryGitRepository();

	for ( const [ repositoryPath, source ] of Object.entries( files ) ) {
		const absolutePath = resolve( sourceRepositoryRoot, repositoryPath );
		mkdirSync( dirname( absolutePath ), { recursive: true } );
		writeFileSync( absolutePath, source );
	}

	execFileSync( 'git', [ '-C', sourceRepositoryRoot, 'add', '--all' ] );
	return sourceRepositoryRoot;
};

const createMissingTargetFile = ( repositoryPath ) => {
	const absolutePath = resolve( repositoryRoot, repositoryPath );

	if ( existsSync( absolutePath ) ) {
		return;
	}

	const missingDirectories = [];
	let directory = dirname( absolutePath );

	while ( ! existsSync( directory ) ) {
		assert.equal(
			directory.startsWith( `${ repositoryRoot }${ pathSeparator }` ),
			true
		);
		missingDirectories.push( directory );
		directory = dirname( directory );
	}

	mkdirSync( dirname( absolutePath ), { recursive: true } );
	for ( const missingDirectory of missingDirectories ) {
		temporaryTargetDirectories.add( missingDirectory );
	}

	writeFileSync( absolutePath, '// Contract-map test fixture.\n' );
	temporaryFiles.push( absolutePath );
};

const validate = ( map, options = {} ) =>
	validateContractMap( map, {
		metadata,
		repositoryRoot,
		...options,
	} );

const specify = ( row ) => {
	row.accepted_disposition = row.planned_disposition;
	row.target_contract = `Native contract for ${ row.case_id }`;
	row.implementation_owner = 'woocommerce-e2e';
	row.migration_state = 'specified';
};

const close = ( row, sourceRepositoryRoot = repositoryRoot ) => {
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	row.gap_or_decision_reference = 'none';
	row.evidence_path = createEvidenceFile(
		row,
		{
			closures: [ createClosureEntry( row ) ],
		},
		sourceRepositoryRoot
	);
};

const closeInventory = ( map ) => {
	const sourceRepositoryRoot = createTemporaryGitRepository();

	for ( const row of map.rows ) {
		for ( const repositoryPath of [
			...row.target_path.split( ';' ),
			...row.native_owner_paths.split( ';' ),
			...row.native_lower_layer_context.split( ';' ),
		] ) {
			const absolutePath = resolve(
				sourceRepositoryRoot,
				repositoryPath
			);
			mkdirSync( dirname( absolutePath ), { recursive: true } );
			if ( ! existsSync( absolutePath ) ) {
				writeFileSync( absolutePath, '// Empty source fixture.\n' );
			}
		}
	}

	execFileSync( 'git', [ '-C', sourceRepositoryRoot, 'add', '--all' ] );

	for ( const row of map.rows ) {
		close( row, sourceRepositoryRoot );
		if (
			row.accepted_disposition ===
			'Retire because the test is stale, redundant, or guards only obsolete plugin structure'
		) {
			row.native_support_state = 'not-applicable-retired';
			row.gap_or_decision_reference =
				'human-approved:inventory-retirement-review';
		}
	}

	execFileSync( 'git', [ '-C', sourceRepositoryRoot, 'add', '--all' ] );
	return sourceRepositoryRoot;
};

afterEach( () => {
	for ( const temporaryDirectory of temporaryDirectories.splice( 0 ) ) {
		assert.equal(
			temporaryDirectory.startsWith(
				join( binDirectory, '.contract-map-test-' )
			),
			true
		);
		rmSync( temporaryDirectory, { recursive: true, force: true } );
	}

	for ( const temporaryDirectory of externalTemporaryDirectories.splice(
		0
	) ) {
		assert.equal(
			temporaryDirectory.startsWith(
				join( tmpdir(), 'woocommerce-contract-map-' )
			),
			true
		);
		rmSync( temporaryDirectory, { recursive: true, force: true } );
	}

	for ( const temporaryFile of temporaryFiles.splice( 0 ) ) {
		if ( existsSync( temporaryFile ) ) {
			unlinkSync( temporaryFile );
		}
	}

	const targetDirectories = [ ...temporaryTargetDirectories ].toSorted(
		( first, second ) => second.length - first.length
	);
	temporaryTargetDirectories.clear();
	for ( const temporaryDirectory of targetDirectories ) {
		if ( existsSync( temporaryDirectory ) ) {
			rmdirSync( temporaryDirectory );
		}
	}
} );

test( 'bundle coverage detects side-effect and double-quoted static imports', () => {
	const sourceRepositoryRoot = createTrackedClosureRepository( {
		'sample.spec.ts':
			'import \'./side-effect\';\nimport { behavior } from "./behavior";\nexport const use = behavior;\n',
		'side-effect.ts': 'export const sideEffect = true;\n',
		'behavior.ts': 'export const behavior = true;\n',
	} );
	const row = { ...contractMap.rows[ 0 ] };
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	row.target_path = 'sample.spec.ts';

	assert.throws(
		() =>
			assertClosureBundleCoverage(
				row,
				{ source_test_paths: [ row.target_path ] },
				sourceRepositoryRoot
			),
		/bundle must attest every behavior module/
	);
	assert.doesNotThrow( () =>
		assertClosureBundleCoverage(
			row,
			{
				source_test_paths: [
					row.target_path,
					'side-effect.ts',
					'behavior.ts',
				],
			},
			sourceRepositoryRoot
		)
	);
} );

test( 'bundle coverage resolves TSX modules and directory indexes', () => {
	const sourceRepositoryRoot = createTrackedClosureRepository( {
		'sample.spec.ts':
			"export { direct } from './direct';\nexport { indexedTs } from './indexed-ts';\nexport { indexedTsx } from './indexed-tsx';\nexport { indexedMjs } from './indexed-mjs';\n",
		'direct.tsx': 'export const direct = true;\n',
		'indexed-ts/index.ts': 'export const indexedTs = true;\n',
		'indexed-tsx/index.tsx': 'export const indexedTsx = true;\n',
		'indexed-mjs/index.mjs': 'export const indexedMjs = true;\n',
	} );
	const row = { ...contractMap.rows[ 0 ] };
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	row.target_path = 'sample.spec.ts';

	assert.throws(
		() =>
			assertClosureBundleCoverage(
				row,
				{ source_test_paths: [ row.target_path ] },
				sourceRepositoryRoot
			),
		/bundle must attest every behavior module/
	);
	assert.doesNotThrow( () =>
		assertClosureBundleCoverage(
			row,
			{
				source_test_paths: [
					row.target_path,
					'direct.tsx',
					'indexed-ts/index.ts',
					'indexed-tsx/index.tsx',
					'indexed-mjs/index.mjs',
				],
			},
			sourceRepositoryRoot
		)
	);
} );

test( 'bundle coverage resolves extensionless JavaScript modules and directory indexes', () => {
	const sourceRepositoryRoot = createTrackedClosureRepository( {
		'sample.spec.js':
			"export { directJs } from './direct-js';\nexport { directJsx } from './direct-jsx';\nexport { indexedJs } from './indexed-js';\nexport { indexedJsx } from './indexed-jsx';\n",
		'direct-js.js': 'export const directJs = true;\n',
		'direct-jsx.jsx': 'export const directJsx = true;\n',
		'indexed-js/index.js': 'export const indexedJs = true;\n',
		'indexed-jsx/index.jsx': 'export const indexedJsx = true;\n',
	} );
	const row = { ...contractMap.rows[ 0 ] };
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	row.target_path = 'sample.spec.js';

	assert.deepEqual(
		collectClosureBundlePaths( row, sourceRepositoryRoot ).toSorted(),
		[
			row.target_path,
			'direct-js.js',
			'direct-jsx.jsx',
			'indexed-js/index.js',
			'indexed-jsx/index.jsx',
		].toSorted()
	);
} );

test( 'closure collection follows a real ledger JavaScript target dependency', () => {
	const targetPath =
		'plugins/woocommerce/client/blocks/assets/js/blocks/multi-currency-switcher/test/index.js';
	const ledgerRow = contractMap.rows.find( ( row ) =>
		row.target_path.split( ';' ).includes( targetPath )
	);
	assert.notEqual( ledgerRow, undefined );
	const row = { ...ledgerRow, target_path: targetPath };
	const requiredPaths = collectClosureBundlePaths( row, repositoryRoot );

	assert.equal( requiredPaths.includes( targetPath ), true );
	assert.equal(
		requiredPaths.includes(
			'plugins/woocommerce/client/blocks/assets/js/blocks/multi-currency-switcher/index.js'
		),
		true
	);
	assert.equal(
		requiredPaths.includes(
			'plugins/woocommerce/client/blocks/assets/js/blocks/multi-currency-switcher/block.js'
		),
		true
	);
} );

test( 'bundle coverage ignores commented and dynamic relative imports', () => {
	const sourceRepositoryRoot = createTrackedClosureRepository( {
		'sample.spec.ts':
			"/*\nimport { commented } from './commented';\n*/\nexport const load = () => import('./dynamic');\n",
		'commented.ts': 'export const commented = true;\n',
		'dynamic.ts': 'export const dynamic = true;\n',
	} );
	const row = { ...contractMap.rows[ 0 ] };
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	row.target_path = 'sample.spec.ts';

	assert.doesNotThrow( () =>
		assertClosureBundleCoverage(
			row,
			{ source_test_paths: [ row.target_path ] },
			sourceRepositoryRoot
		)
	);
} );

test( 'bundle coverage rejects an import that escapes into an infrastructure-looking path', () => {
	const sourceRepositoryRoot = createTrackedClosureRepository( {
		'sample.spec.ts': 'export const use = true;\n',
	} );
	const externalDirectory = mkdtempSync(
		join( tmpdir(), 'woocommerce-contract-map-outside-' )
	);
	externalTemporaryDirectories.push( externalDirectory );
	const externalPath = join(
		externalDirectory,
		'tests/e2e/fixtures/behavior.ts'
	);
	mkdirSync( dirname( externalPath ), { recursive: true } );
	writeFileSync( externalPath, 'export const behavior = true;\n' );
	const specPath = resolve( sourceRepositoryRoot, 'sample.spec.ts' );
	const externalSpecifier = relativeModuleSpecifier( specPath, externalPath );
	writeFileSync(
		specPath,
		`import { behavior } from '${ externalSpecifier }';\nexport const use = behavior;\n`
	);
	const row = { ...contractMap.rows[ 0 ] };
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	row.target_path = 'sample.spec.ts';

	assert.throws(
		() =>
			assertClosureBundleCoverage(
				row,
				{ source_test_paths: [ row.target_path ] },
				sourceRepositoryRoot
			),
		/bundle must attest every behavior module.*tracked regular repository file/
	);
} );

test( 'bundle coverage rejects a symlinked infrastructure module', () => {
	const sourceRepositoryRoot = createTrackedClosureRepository( {
		'sample.spec.ts':
			"import { behavior } from './plugins/woocommerce/tests/e2e/fixtures/behavior';\nexport const use = behavior;\n",
		'behavior.ts': 'export const behavior = true;\n',
	} );
	const symlinkPath = resolve(
		sourceRepositoryRoot,
		'plugins/woocommerce/tests/e2e/fixtures/behavior.ts'
	);
	mkdirSync( dirname( symlinkPath ), { recursive: true } );
	symlinkSync( resolve( sourceRepositoryRoot, 'behavior.ts' ), symlinkPath );
	execFileSync( 'git', [ '-C', sourceRepositoryRoot, 'add', '--all' ] );
	const row = { ...contractMap.rows[ 0 ] };
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	row.target_path = 'sample.spec.ts';

	assert.throws(
		() =>
			assertClosureBundleCoverage(
				row,
				{ source_test_paths: [ row.target_path ] },
				sourceRepositoryRoot
			),
		/bundle must attest every behavior module.*without symlinks/
	);
} );

test( 'bundle coverage rejects unresolved relative imports', () => {
	const sourceRepositoryRoot = createTrackedClosureRepository( {
		'sample.spec.ts':
			"import { behavior } from './missing';\nexport const use = behavior;\n",
	} );
	const row = { ...contractMap.rows[ 0 ] };
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	row.target_path = 'sample.spec.ts';

	assert.throws(
		() =>
			assertClosureBundleCoverage(
				row,
				{ source_test_paths: [ row.target_path ] },
				sourceRepositoryRoot
			),
		/bundle must attest every behavior module.*unresolved relative import/
	);
} );

test( 'bundle coverage rejects an untracked relative import', () => {
	const sourceRepositoryRoot = createTrackedClosureRepository( {
		'sample.spec.ts':
			"import { behavior } from './behavior';\nexport const use = behavior;\n",
	} );
	writeFileSync(
		resolve( sourceRepositoryRoot, 'behavior.ts' ),
		'export const behavior = true;\n'
	);
	const row = { ...contractMap.rows[ 0 ] };
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	row.target_path = 'sample.spec.ts';

	assert.throws(
		() =>
			assertClosureBundleCoverage(
				row,
				{ source_test_paths: [ row.target_path ] },
				sourceRepositoryRoot
			),
		/bundle must attest every behavior module.*tracked regular repository file/
	);
} );

test( 'a terminal row evidence bundle must cover the transitive spec import closure', () => {
	const sourceRepositoryRoot = createTrackedClosureRepository( {
		'sample.spec.ts':
			"import { widget } from './drivers/widget';\nexport const use = widget;\n",
		'drivers/widget.ts':
			"export { helper } from './widget-helper';\nexport const widget = 1;\n",
		'drivers/widget-helper.mjs':
			"import { widget } from './widget';\nexport const helper = widget;\n",
		'drivers/unused.ts': 'export const unused = true;\n',
	} );
	const specRepositoryPath = 'sample.spec.ts';
	const driverRepositoryPath = 'drivers/widget.ts';
	const helperRepositoryPath = 'drivers/widget-helper.mjs';
	const unusedRepositoryPath = 'drivers/unused.ts';
	const row = { ...contractMap.rows[ 0 ] };
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	row.target_path = specRepositoryPath;

	assert.throws(
		() =>
			assertClosureBundleCoverage(
				row,
				{ source_test_paths: [ specRepositoryPath ] },
				sourceRepositoryRoot
			),
		new RegExp(
			`bundle must attest every behavior module.*${ driverRepositoryPath }`
		)
	);
	assert.throws(
		() =>
			assertClosureBundleCoverage(
				row,
				{
					source_test_paths: [
						specRepositoryPath,
						driverRepositoryPath,
					],
				},
				sourceRepositoryRoot
			),
		new RegExp(
			`bundle must attest every behavior module.*${ helperRepositoryPath }`
		)
	);

	assert.doesNotThrow( () =>
		assertClosureBundleCoverage(
			row,
			{
				source_test_paths: [
					specRepositoryPath,
					driverRepositoryPath,
					helperRepositoryPath,
					unusedRepositoryPath,
				],
			},
			sourceRepositoryRoot
		)
	);
} );

test( 'bundle coverage traverses every trimmed multi-target segment', () => {
	const sourceRepositoryRoot = createTrackedClosureRepository( {
		'first.spec.ts':
			"import { first } from './first-driver';\nexport const useFirst = first;\n",
		'second.spec.ts':
			"import { second } from './second-driver';\nexport const useSecond = second;\n",
		'first-driver.ts': 'export const first = 1;\n',
		'second-driver.mjs': 'export const second = 2;\n',
	} );
	const firstSpecRepositoryPath = 'first.spec.ts';
	const secondSpecRepositoryPath = 'second.spec.ts';
	const firstDriverRepositoryPath = 'first-driver.ts';
	const secondDriverRepositoryPath = 'second-driver.mjs';
	const row = { ...contractMap.rows[ 0 ] };
	specify( row );
	row.migration_state = 'verified';
	row.native_support_state = 'supported';
	row.target_path = `${ firstSpecRepositoryPath }; ${ secondSpecRepositoryPath }`;

	assert.throws(
		() =>
			assertClosureBundleCoverage(
				row,
				{
					source_test_paths: [
						firstSpecRepositoryPath,
						firstDriverRepositoryPath,
						secondSpecRepositoryPath,
					],
				},
				sourceRepositoryRoot
			),
		new RegExp(
			`bundle must attest every behavior module.*${ secondDriverRepositoryPath }`
		)
	);

	assert.doesNotThrow( () =>
		assertClosureBundleCoverage(
			row,
			{
				source_test_paths: [
					firstSpecRepositoryPath,
					firstDriverRepositoryPath,
					secondSpecRepositoryPath,
					secondDriverRepositoryPath,
				],
			},
			sourceRepositoryRoot
		)
	);
} );

test( 'bundle coverage traversal stops at controller infrastructure modules', () => {
	const infrastructurePaths = [
		'plugins/woocommerce/tests/e2e/fixtures/woopayments-native.ts',
		'plugins/woocommerce/tests/e2e/reporters/environment-reporter.ts',
		'plugins/woocommerce/tests/e2e/test-data/data.ts',
		'plugins/woocommerce/tests/e2e/utils/woopayments-native/resource-locks.ts',
	];
	const specPath = resolve( '/', 'infra.spec.ts' );
	const imports = infrastructurePaths
		.map( ( infrastructurePath, index ) => {
			const absoluteInfrastructurePath = resolve(
				'/',
				infrastructurePath
			);

			return `import { value${ index } } from '${ relativeModuleSpecifier(
				specPath,
				absoluteInfrastructurePath
			) }';`;
		} )
		.join( '\n' );
	const sourceRepositoryRoot = createTrackedClosureRepository( {
		'infra.spec.ts': `${ imports }\nexport const use = true;\n`,
		...Object.fromEntries(
			infrastructurePaths.map( ( infrastructurePath, index ) => [
				infrastructurePath,
				`export const value${ index } = true;\n`,
			] )
		),
	} );
	const specRepositoryPath = 'infra.spec.ts';
	const row = { ...contractMap.rows[ 0 ] };
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	row.target_path = specRepositoryPath;

	assert.doesNotThrow( () =>
		assertClosureBundleCoverage(
			row,
			{ source_test_paths: [ specRepositoryPath ] },
			sourceRepositoryRoot
		)
	);
} );

test( 'bundle coverage attests infrastructure basename collisions with unlisted extensions', () => {
	const behaviorPath =
		'plugins/woocommerce/tests/e2e/utils/woopayments-native/known-gap.tsx';
	const sourceRepositoryRoot = createTrackedClosureRepository( {
		'sample.spec.ts':
			"import { behavior } from './plugins/woocommerce/tests/e2e/utils/woopayments-native/known-gap';\nexport const use = behavior;\n",
		[ behaviorPath ]: 'export const behavior = true;\n',
	} );
	const row = { ...contractMap.rows[ 0 ] };
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	row.target_path = 'sample.spec.ts';

	assert.throws(
		() =>
			assertClosureBundleCoverage(
				row,
				{ source_test_paths: [ row.target_path ] },
				sourceRepositoryRoot
			),
		new RegExp(
			`bundle must attest every behavior module.*${ behaviorPath }`
		)
	);
	assert.doesNotThrow( () =>
		assertClosureBundleCoverage(
			row,
			{ source_test_paths: [ row.target_path, behaviorPath ] },
			sourceRepositoryRoot
		)
	);
} );

test( 'keeps normal parsing strict while adapting the historical ledger for transition checks', () => {
	const legacyContent = serializeLegacyContractMap();

	assert.throws(
		() => parseContractMap( legacyContent ),
		/Invalid contract-map schema; expected the exact 40 ordered columns/
	);

	const historicalContractMap = parseContractMap( legacyContent, {
		allowLegacySchema: true,
	} );

	assert.equal( historicalContractMap.headers.length, 40 );
	assert.equal(
		historicalContractMap.rows[ 0 ].migration_state,
		contractMap.rows[ 0 ].migration_state
	);
	assert.equal( historicalContractMap.rows[ 0 ].target_contract, 'pending' );
} );

test( 'CLI from-git-ref rejects an illegal transition in a changed row', () => {
	const previousContractMap = cloneContractMap();
	const row = previousContractMap.rows[ 0 ];
	let gitLoadCount = 0;

	row.migration_state = 'closed';

	assert.throws(
		() =>
			runCli( [ '--from-git-ref', 'HEAD' ], {
				ledgerContent,
				metadata,
				repositoryRoot,
				loadFromGitRef: ( gitRef, repositoryPath ) => {
					gitLoadCount++;
					assert.equal( gitRef, 'HEAD' );
					assert.equal(
						repositoryPath,
						'plugins/woocommerce/tests/e2e/tests/woopayments-native/client-contract-map.tsv'
					);
					return serializeContractMap( previousContractMap );
				},
				log: () => {},
			} ),
		/Illegal migration transition: closed -> planned/
	);
	assert.equal( gitLoadCount, 1 );
} );

test( 'CLI from-git-ref HEAD runs git show and prints the real summary', () => {
	const logLines = [];

	const summary = runCli( [ '--from-git-ref', 'HEAD', '--summary' ], {
		ledgerContent,
		metadata,
		repositoryRoot,
		log: ( line ) => logLines.push( line ),
	} );

	assert.equal( summary.rowCount, 181 );
	assert.equal(
		logLines[ 0 ],
		'Validated 181 WooPayments client contracts.'
	);
	const summarizedMigrationStates = Object.entries(
		summary.migrationStateCounts
	)
		.filter( ( [ , count ] ) => count > 0 )
		.map(
			( [ state, count ] ) => `${ count }\tmigration_state\t${ state }`
		);
	assert.equal(
		summarizedMigrationStates.every( ( line ) =>
			logLines.includes( line )
		),
		true
	);
	assert.equal(
		logLines.includes(
			`${ summary.nativeSupportStateCounts[ 'not-assessed' ] }\tnative_support_state\tnot-assessed`
		),
		true
	);
	assert.equal(
		logLines.includes(
			'117\tExtract a shared scenario with thin runtime adapters'
		),
		true
	);
} );

test( 'CLI history rejects returning to the planned disposition without fresh approval', () => {
	const currentMap = cloneContractMap();
	const previousMap = cloneContractMap();
	const currentRow = currentMap.rows[ 0 ];
	const previousRow = previousMap.rows[ 0 ];

	specify( currentRow );
	specify( previousRow );
	previousRow.accepted_disposition =
		'Retire because the test is stale, redundant, or guards only obsolete plugin structure';
	previousRow.gap_or_decision_reference = 'human-approved:initial-retirement';

	assert.throws(
		() => runHistoryComparison( currentMap, previousMap ),
		new RegExp(
			`Disposition transition requires fresh human approval: ${ currentRow.case_id }`
		)
	);
} );

test( 'CLI history rejects a changed disposition with reused approval', () => {
	const currentMap = cloneContractMap();
	const previousMap = cloneContractMap();
	const currentRow = currentMap.rows.find(
		( row ) =>
			row.planned_disposition ===
			'Rewrite as a native-specific E2E test preserving the contract'
	);
	const previousRow = previousMap.rows.find(
		( row ) => row.case_id === currentRow.case_id
	);

	specify( currentRow );
	specify( previousRow );
	previousRow.accepted_disposition =
		'Retire because the test is stale, redundant, or guards only obsolete plugin structure';
	previousRow.gap_or_decision_reference = 'human-approved:first-decision';
	currentRow.gap_or_decision_reference = 'human-approved:first-decision';

	assert.throws(
		() => runHistoryComparison( currentMap, previousMap ),
		new RegExp(
			`Disposition transition requires fresh human approval: ${ currentRow.case_id }`
		)
	);
} );

for ( const [ description, plannedDisposition ] of [
	[
		'returning to the planned disposition',
		'Run unchanged against both runtimes',
	],
	[
		'selecting a different non-pending disposition',
		'Rewrite as a native-specific E2E test preserving the contract',
	],
] ) {
	test( `CLI history accepts fresh approval when ${ description }`, () => {
		const currentMap = cloneContractMap();
		const previousMap = cloneContractMap();
		const currentRow = currentMap.rows.find(
			( row ) => row.planned_disposition === plannedDisposition
		);
		const previousRow = previousMap.rows.find(
			( row ) => row.case_id === currentRow.case_id
		);

		specify( currentRow );
		specify( previousRow );
		previousRow.accepted_disposition =
			'Retire because the test is stale, redundant, or guards only obsolete plugin structure';
		previousRow.gap_or_decision_reference = 'human-approved:first-decision';
		currentRow.gap_or_decision_reference = 'human-approved:fresh-follow-up';

		assert.doesNotThrow( () =>
			runHistoryComparison( currentMap, previousMap )
		);
	} );
}

test( 'disposition history allows initial pending to select the planned disposition', () => {
	const currentMap = cloneContractMap();
	const previousMap = cloneContractMap();
	const currentRow = currentMap.rows[ 0 ];
	const previousRow = previousMap.rows[ 0 ];

	specify( currentRow );

	assert.doesNotThrow( () =>
		validateDispositionTransition( previousRow, currentRow )
	);
	assert.doesNotThrow( () =>
		runHistoryComparison( currentMap, previousMap )
	);
} );

for ( const [ column, invalidValue ] of [
	[ 'migration_state', 'complete-ish' ],
	[ 'native_support_state', 'probably-supported' ],
] ) {
	test( `rejects an unknown ${ column }`, () => {
		const map = cloneContractMap();
		map.rows[ 0 ][ column ] = invalidValue;

		assert.throws(
			() => validate( map ),
			new RegExp( `Invalid ${ column }.*${ invalidValue }` )
		);
	} );
}

for ( const [ description, mutate ] of [
	[
		'an unsupported native state',
		( row ) => {
			close( row );
			row.native_support_state = 'known-gap';
			row.gap_or_decision_reference = 'issue:known-gap';
		},
	],
	[
		'missing evidence',
		( row ) => {
			close( row );
			row.evidence_path = 'none';
		},
	],
	[
		'a missing evidence file',
		( row ) => {
			close( row );
			row.evidence_path =
				'plugins/woocommerce/tests/e2e/bin/missing-evidence.txt';
		},
	],
] ) {
	test( `rejects closure with ${ description }`, () => {
		const map = cloneContractMap();
		const row = map.rows[ 0 ];

		mutate( row );

		assert.throws(
			() => validate( map ),
			new RegExp(
				`Closed contract must be supported and reference evidence: ${ row.case_id }`
			)
		);
	} );
}

test( 'rejects a changed disposition without human approval', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];
	const replacementDisposition = map.rows.find(
		( candidate ) =>
			candidate.planned_disposition !== row.planned_disposition
	).planned_disposition;

	specify( row );
	row.accepted_disposition = replacementDisposition;

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Disposition change requires an explicit decision reference for ${ row.case_id }`
		)
	);
} );

test( 'accepts a changed disposition with human approval', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];
	const replacementDisposition = map.rows.find(
		( candidate ) =>
			candidate.planned_disposition !== row.planned_disposition
	).planned_disposition;

	specify( row );
	row.accepted_disposition = replacementDisposition;
	row.gap_or_decision_reference = 'human-approved:contract-review';

	assert.doesNotThrow( () => validate( map ) );
} );

for ( const [ description, decisionReference ] of [
	[ 'an empty authority', 'human-approved:' ],
	[ 'whitespace-only authority', 'human-approved: ' ],
	[ 'leading authority whitespace', 'human-approved: review' ],
	[ 'trailing authority whitespace', 'human-approved:review ' ],
	[ 'a none placeholder', 'human-approved:none' ],
	[ 'a pending placeholder', 'human-approved:pending' ],
	[
		'an owner-decision placeholder',
		'human-approved:owner-decision-required',
	],
] ) {
	test( `rejects a changed disposition with ${ description }`, () => {
		const map = cloneContractMap();
		const row = map.rows[ 0 ];
		const replacementDisposition = map.rows.find(
			( candidate ) =>
				candidate.planned_disposition !== row.planned_disposition
		).planned_disposition;

		specify( row );
		row.accepted_disposition = replacementDisposition;
		row.gap_or_decision_reference = decisionReference;

		assert.throws(
			() => validate( map ),
			new RegExp(
				`Disposition change requires an explicit decision reference for ${ row.case_id }`
			)
		);
	} );
}

for ( const [ previous, next ] of [ [ 'planned', 'closed' ] ] ) {
	test( `rejects the ${ previous } -> ${ next } migration transition`, () => {
		assert.throws(
			() => validateStateTransition( previous, next ),
			new RegExp(
				`Illegal migration transition: ${ previous } -> ${ next }`
			)
		);
	} );
}

test( 'allows reopening a closed contract to implemented and nothing else', () => {
	validateStateTransition( 'closed', 'implemented' );
	assert.throws(
		() => validateStateTransition( 'closed', 'specified' ),
		/Illegal migration transition/
	);
	assert.throws(
		() => validateStateTransition( 'closed', 'planned' ),
		/Illegal migration transition/
	);
} );

for ( const [ previous, next ] of [
	[ 'planned', 'specified' ],
	[ 'specified', 'closed' ],
] ) {
	test( `accepts the ${ previous } -> ${ next } migration transition`, () => {
		assert.doesNotThrow( () => validateStateTransition( previous, next ) );
	} );
}

test( 'does not allow a known gap to be closed', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	close( row );
	row.native_support_state = 'known-gap';
	row.gap_or_decision_reference = 'issue:known-gap';

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Closed contract must be supported and reference evidence: ${ row.case_id }`
		)
	);
} );

for ( const nativeSupportState of [
	'not-assessed',
	'supported',
	'not-applicable-retired',
] ) {
	test( `rejects deferred with ${ nativeSupportState } native support`, () => {
		const map = cloneContractMap();
		const row = map.rows[ 0 ];

		row.migration_state = 'deferred';
		row.native_support_state = nativeSupportState;
		row.gap_or_decision_reference = 'issue:deferral';
		row.evidence_path = createEvidenceFile( row );

		assert.throws(
			() => validate( map ),
			new RegExp(
				`Deferred contract requires an expected gap state, reference, and evidence: ${ row.case_id }`
			)
		);
	} );
}

for ( const nativeSupportState of [
	'known-gap',
	'blocked-external',
	'blocked-environment',
	'ambiguous-decision',
] ) {
	test( `accepts deferred with ${ nativeSupportState } native support`, () => {
		const map = cloneContractMap();
		const row = map.rows[ 0 ];

		row.migration_state = 'deferred';
		row.native_support_state = nativeSupportState;
		row.gap_or_decision_reference = 'issue:deferral';
		row.evidence_path = createEvidenceFile( row );

		assert.doesNotThrow( () => validate( map ) );
	} );
}

for ( const [ description, mutate ] of [
	[
		'a none reference',
		( row ) => {
			row.gap_or_decision_reference = 'none';
		},
	],
	[
		'no evidence file',
		( row ) => {
			row.evidence_path = 'none';
		},
	],
] ) {
	test( `rejects deferred with ${ description }`, () => {
		const map = cloneContractMap();
		const row = map.rows[ 0 ];

		row.migration_state = 'deferred';
		row.native_support_state = 'known-gap';
		row.gap_or_decision_reference = 'issue:deferral';
		row.evidence_path = createEvidenceFile( row );
		mutate( row );

		assert.throws(
			() => validate( map ),
			new RegExp(
				`Deferred contract requires an expected gap state, reference, and evidence: ${ row.case_id }`
			)
		);
	} );
}

test( 'requires a gap reference for known-gap native support', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	specify( row );
	row.native_support_state = 'known-gap';

	assert.throws(
		() => validate( map ),
		new RegExp( `Known gap requires a reference: ${ row.case_id }` )
	);
} );

test( 'accepts retired closure with explicit human approval', () => {
	const map = cloneContractMap();
	const row = map.rows.find(
		( candidate ) =>
			candidate.planned_disposition ===
			'Retire because the test is stale, redundant, or guards only obsolete plugin structure'
	);

	close( row );
	row.native_support_state = 'not-applicable-retired';
	row.gap_or_decision_reference = 'human-approved:retirement-review';

	assert.doesNotThrow( () => validate( map ) );
} );

test( 'rejects retired closure without explicit human approval', () => {
	const map = cloneContractMap();
	const row = map.rows.find(
		( candidate ) =>
			candidate.planned_disposition ===
			'Retire because the test is stale, redundant, or guards only obsolete plugin structure'
	);

	close( row );
	row.native_support_state = 'not-applicable-retired';

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Closed contract must be supported and reference evidence: ${ row.case_id }`
		)
	);
} );

for ( const [ description, decisionReference ] of [
	[ 'an empty authority', 'human-approved:' ],
	[ 'whitespace-only authority', 'human-approved: ' ],
	[ 'leading authority whitespace', 'human-approved: review' ],
	[ 'trailing authority whitespace', 'human-approved:review ' ],
	[ 'a none placeholder', 'human-approved:none' ],
	[ 'a pending placeholder', 'human-approved:pending' ],
	[
		'an owner-decision placeholder',
		'human-approved:owner-decision-required',
	],
] ) {
	test( `rejects retired closure with ${ description }`, () => {
		const map = cloneContractMap();
		const row = map.rows.find(
			( candidate ) =>
				candidate.planned_disposition ===
				'Retire because the test is stale, redundant, or guards only obsolete plugin structure'
		);

		close( row );
		row.native_support_state = 'not-applicable-retired';
		row.gap_or_decision_reference = decisionReference;

		assert.throws(
			() => validate( map ),
			new RegExp(
				`Closed contract must be supported and reference evidence: ${ row.case_id }`
			)
		);
	} );
}

test( 'rejects a rewritten retirement contract with retirement-only targets', () => {
	const map = cloneContractMap();
	const row = map.rows.find(
		( candidate ) =>
			candidate.planned_disposition ===
			'Retire because the test is stale, redundant, or guards only obsolete plugin structure'
	);

	close( row );
	row.accepted_disposition =
		'Rewrite as a native-specific E2E test preserving the contract';
	row.gap_or_decision_reference = 'human-approved:rewrite-decision';

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Rewrite as a native-specific E2E test preserving the contract requires only approved future targets for ${ row.case_id }`
		)
	);
} );

test( 'accepts a rewritten retirement contract with a compatible exact target', () => {
	const map = cloneContractMap();
	const row = map.rows.find(
		( candidate ) =>
			candidate.planned_disposition ===
			'Retire because the test is stale, redundant, or guards only obsolete plugin structure'
	);
	const targetPath =
		'plugins/woocommerce/tests/e2e/tests/woopayments-native/pilots/merchant-transaction-navigation.spec.ts';

	assert.equal( existsSync( resolve( repositoryRoot, targetPath ) ), true );
	close( row );
	row.accepted_disposition =
		'Rewrite as a native-specific E2E test preserving the contract';
	row.gap_or_decision_reference = 'human-approved:rewrite-decision';
	row.target_path = targetPath;
	row.evidence_path = createEvidenceFile( row, {
		closures: [ createClosureEntry( row ) ],
	} );

	assert.doesNotThrow( () => validate( map ) );
} );

test( 'rejects changing a non-pilot-gated contract to a shared disposition', () => {
	const map = cloneContractMap();
	const row = map.rows.find(
		( candidate ) =>
			candidate.planned_disposition ===
			'Retire because the test is stale, redundant, or guards only obsolete plugin structure'
	);
	const targetPath =
		'plugins/woocommerce/tests/e2e/tests/woopayments-native/pilots/merchant-manual-capture.spec.ts';

	assert.notEqual( row.disposition_state, 'pilot-gated' );
	assert.equal( existsSync( resolve( repositoryRoot, targetPath ) ), true );
	close( row );
	row.accepted_disposition =
		'Extract a shared scenario with thin runtime adapters';
	row.gap_or_decision_reference = 'human-approved:shared-decision';
	row.target_path = targetPath;
	row.evidence_path = createEvidenceFile( row );

	assert.throws(
		() => validate( map ),
		new RegExp( `Unproven shared disposition for ${ row.case_id }` )
	);
} );

test( 'rejects repository path traversal', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	close( row );
	row.target_path = '../outside-repository.spec.ts';

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Invalid target_path for ${ row.case_id }: ../outside-repository.spec.ts`
		)
	);
} );

test( 'rejects a repository-contained symlink that escapes the repository', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];
	const repositoryDirectory = createRepositoryTemporaryDirectory();
	const externalFile = createExternalTemporaryFile();
	const symlinkPath = join( repositoryDirectory, 'escaped-evidence.txt' );

	symlinkSync( externalFile, symlinkPath );
	specify( row );
	row.migration_state = 'implemented';
	row.native_support_state = 'known-gap';
	row.gap_or_decision_reference = 'issue:known-gap';
	row.evidence_path = repositoryRelativePath( symlinkPath );

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Non-concrete or out-of-repository evidence_path for ${ row.case_id }`
		)
	);
} );

test( 'rejects malformed JSON evidence', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];
	const repositoryDirectory = createRepositoryTemporaryDirectory();
	const evidencePath = join( repositoryDirectory, 'malformed-evidence.json' );

	writeFileSync( evidencePath, '{not-json}\n' );
	specify( row );
	row.migration_state = 'implemented';
	row.native_support_state = 'known-gap';
	row.gap_or_decision_reference = 'issue:known-gap';
	row.evidence_path = repositoryRelativePath( evidencePath );

	assert.throws(
		() => validate( map ),
		new RegExp( `Invalid migration evidence JSON for ${ row.case_id }` )
	);
} );

test( 'rejects an existing directory as a target file', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];
	const repositoryDirectory = createRepositoryTemporaryDirectory();

	close( row );
	row.target_path = repositoryRelativePath( repositoryDirectory );

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Non-concrete or out-of-repository target_path for ${ row.case_id }`
		)
	);
} );

for ( const migrationState of [ 'verified', 'closed' ] ) {
	test( `${ migrationState } requires existing exact target files`, () => {
		const map = cloneContractMap();
		const row = map.rows[ 0 ];

		close( row );
		row.migration_state = migrationState;
		row.target_path =
			'plugins/woocommerce/tests/e2e/bin/does-not-exist.spec.ts';

		assert.equal(
			existsSync( resolve( repositoryRoot, row.target_path ) ),
			false
		);
		assert.throws(
			() => validate( map ),
			new RegExp(
				`Non-concrete target_path for ${ row.case_id }: ${ row.target_path }`
			)
		);
	} );
}

for ( const [ description, mutate ] of [
	[
		'an unresolved target contract',
		( row ) => {
			row.target_contract = 'pending';
		},
	],
	[
		'a placeholder owner',
		( row ) => {
			row.implementation_owner = 'owner-decision-required';
		},
	],
] ) {
	test( `rejects specified with ${ description }`, () => {
		const map = cloneContractMap();
		const row = map.rows[ 0 ];

		specify( row );
		mutate( row );

		assert.throws(
			() => validate( map ),
			new RegExp(
				`Specified contract requires an exact target and owner: ${ row.case_id }`
			)
		);
	} );
}

test( 'allows an implemented contract to remain a known gap', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	specify( row );
	row.migration_state = 'implemented';
	row.native_support_state = 'known-gap';
	row.gap_or_decision_reference = 'issue:known-gap';
	row.evidence_path = createEvidenceFile( row );

	assert.doesNotThrow( () => validate( map ) );
} );

test( 'requires implementation evidence', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	specify( row );
	row.migration_state = 'implemented';
	row.native_support_state = 'known-gap';
	row.gap_or_decision_reference = 'issue:known-gap';
	row.evidence_path = 'none';

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Implemented contract requires target evidence: ${ row.case_id }`
		)
	);
} );

test( 'requires an existing implemented target file', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	specify( row );
	row.migration_state = 'implemented';
	row.native_support_state = 'known-gap';
	row.gap_or_decision_reference = 'issue:known-gap';
	row.evidence_path = createEvidenceFile( row );
	row.target_path =
		'plugins/woocommerce/tests/e2e/bin/does-not-exist.spec.ts';

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Non-concrete target_path for ${ row.case_id }: ${ row.target_path }`
		)
	);
} );

test( 'requires verification evidence', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	close( row );
	row.migration_state = 'verified';
	row.evidence_path = 'none';

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Verified contract requires target evidence: ${ row.case_id }`
		)
	);
} );

test( 'accepts public-safe evidence bound to the referencing row', () => {
	const row = {
		...contractMap.rows[ 0 ],
		target_contract: 'Native fixture contract',
		migration_state: 'closed',
		native_support_state: 'supported',
	};
	const evidence = createValidEvidence( row, {
		closures: [ createClosureEntry( row ) ],
	} );

	assert.doesNotThrow( () =>
		validateMigrationEvidence( evidence, createEvidenceContext( row ) )
	);
} );

test( 'rejects closing a row that shares slice evidence without its own closure entry', () => {
	const map = cloneContractMap();
	const [ first, second ] = map.rows.slice( 0, 2 );
	specify( first );
	specify( second );
	first.migration_state = 'closed';
	first.native_support_state = 'supported';
	second.migration_state = 'closed';
	second.native_support_state = 'supported';
	const sharedEvidencePath = createEvidenceFile( first, {
		contract_ids: [ first.case_id, second.case_id ],
		targets: [
			{ path: first.target_path, contract: first.target_contract },
			{ path: second.target_path, contract: second.target_contract },
		],
		closures: [ createClosureEntry( first ) ],
	} );
	first.evidence_path = sharedEvidencePath;
	second.evidence_path = sharedEvidencePath;
	createMissingTargetFile( first.target_path );
	createMissingTargetFile( second.target_path );

	assert.throws(
		() => validate( map ),
		/a terminal row requires its own closure entry/
	);
} );

test( 'rejects a closure entry whose target is not the exact ledger target', () => {
	const row = cloneContractMap().rows[ 0 ];
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	const evidence = createValidEvidence( row, {
		closures: [
			createClosureEntry( row, {
				target: {
					path: row.target_path,
					contract: 'a different contract',
				},
			} ),
		],
	} );

	assert.throws(
		() =>
			validateMigrationEvidence( evidence, createEvidenceContext( row ) ),
		/closure target must match the exact ledger target/
	);
} );

test( 'rejects a closure missing a required review role', () => {
	const row = cloneContractMap().rows[ 0 ];
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	const evidence = createValidEvidence( row, {
		closures: [
			createClosureEntry( row, {
				reviews: [
					{
						role: 'code',
						verdict: 'APPROVE',
						source_test_sha256: DEFAULT_SOURCE_TEST_SHA256,
						summary: 'code closure review approved',
					},
				],
			} ),
		],
	} );

	assert.throws(
		() =>
			validateMigrationEvidence( evidence, createEvidenceContext( row ) ),
		/every required role must approve the current source bundle/
	);
} );

test( 'rejects a closure review that does not bind the evidence source bundle hash', () => {
	const row = cloneContractMap().rows[ 0 ];
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	const evidence = createValidEvidence( row, {
		closures: [
			createClosureEntry( row, {
				reviews: REQUIRED_CLOSURE_REVIEW_ROLES.map( ( role ) => ( {
					role,
					verdict: 'APPROVE',
					source_test_sha256: 'e'.repeat( 64 ),
					summary: `${ role } closure review approved`,
				} ) ),
			} ),
		],
	} );

	assert.throws(
		() =>
			validateMigrationEvidence( evidence, createEvidenceContext( row ) ),
		/every required role must approve the current source bundle/
	);
} );

test( 'rejects duplicate closure entries for the same contract', async ( t ) => {
	const row = cloneContractMap().rows[ 0 ];
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	const validClosure = createClosureEntry( row );
	const conflictingClosure = createClosureEntry( row, {
		target: {
			path: row.target_path,
			contract: 'a conflicting contract',
		},
		verification: [
			{
				command: 'pnpm test:e2e:woopayments:controller',
				exit_code: 1,
				summary: 'closure verification failed',
			},
		],
		reviews: [
			{
				role: 'code',
				verdict: 'APPROVE',
				source_test_sha256: DEFAULT_SOURCE_TEST_SHA256,
				summary: 'code closure review approved',
			},
		],
	} );

	for ( const [ label, closures ] of [
		[ 'valid entry first', [ validClosure, conflictingClosure ] ],
		[ 'conflicting entry first', [ conflictingClosure, validClosure ] ],
	] ) {
		await t.test( label, () => {
			const evidence = createValidEvidence( row, { closures } );

			assert.throws(
				() =>
					validateMigrationEvidence(
						evidence,
						createEvidenceContext( row )
					),
				/duplicate contract_id/
			);
		} );
	}
} );

test( 'terminal evidence must attest the current retained source bytes', () => {
	const closedRow = cloneContractMap().rows[ 0 ];
	specify( closedRow );
	closedRow.migration_state = 'closed';
	closedRow.native_support_state = 'supported';
	const sourceTestPaths = [
		'plugins/woocommerce/tests/e2e/bin/lib/woopayments-contract-map.mjs',
	];
	const lastTouchCommit = execFileSync(
		'git',
		[
			'-C',
			repositoryRoot,
			'log',
			'-n',
			'1',
			'--format=%H',
			'--',
			sourceTestPaths[ 0 ],
		],
		{ encoding: 'utf8' }
	).trim();
	const historicalCommit = execFileSync(
		'git',
		[ '-C', repositoryRoot, 'rev-parse', `${ lastTouchCommit }^` ],
		{ encoding: 'utf8' }
	).trim();
	const staleSha256 = calculateSourceBundleSha256(
		sourceTestPaths,
		historicalCommit
	);
	const currentSha256 = calculateSourceBundleSha256( sourceTestPaths );
	assert.notEqual( staleSha256, currentSha256 );

	const evidence = createValidEvidence( closedRow, {
		verified_at_commit: historicalCommit,
		source_test_paths: sourceTestPaths,
		source_test_sha256: staleSha256,
		closures: [
			createClosureEntry( closedRow, {
				reviews: REQUIRED_CLOSURE_REVIEW_ROLES.map( ( role ) => ( {
					role,
					verdict: 'APPROVE',
					source_test_sha256: staleSha256,
					summary: `${ role } closure review approved`,
				} ) ),
			} ),
		],
		reviews: [
			{
				role: 'code',
				verdict: 'APPROVE',
				source_test_sha256: staleSha256,
				summary: 'historical source bundle approved',
			},
		],
	} );

	assert.throws(
		() =>
			validateMigrationEvidence(
				evidence,
				createEvidenceContext( closedRow )
			),
		/terminal evidence must attest the current retained bytes/
	);
} );

test( 'terminal evidence rejects an untracked current source path', () => {
	const closedRow = cloneContractMap().rows[ 0 ];
	specify( closedRow );
	closedRow.migration_state = 'closed';
	closedRow.native_support_state = 'supported';
	const sourceRepositoryRoot = createTemporaryGitRepository();
	const sourceTestPaths = [ '.git/HEAD' ];
	const sourceTestSha256 = calculateCurrentSourceBundleSha256(
		sourceTestPaths,
		sourceRepositoryRoot
	);
	const evidence = createValidEvidence( closedRow, {
		source_test_paths: sourceTestPaths,
		source_test_sha256: sourceTestSha256,
		closures: [
			createClosureEntry( closedRow, {
				reviews: REQUIRED_CLOSURE_REVIEW_ROLES.map( ( role ) => ( {
					role,
					verdict: 'APPROVE',
					source_test_sha256: sourceTestSha256,
					summary: `${ role } closure review approved`,
				} ) ),
			} ),
		],
	} );

	assert.throws(
		() =>
			validateMigrationEvidence( evidence, {
				...createEvidenceContext( closedRow ),
				repositoryRoot: sourceRepositoryRoot,
			} ),
		/current source must be a tracked regular file within the repository/
	);
} );

test( 'terminal evidence rejects an escaping current source symlink', () => {
	const closedRow = cloneContractMap().rows[ 0 ];
	specify( closedRow );
	closedRow.migration_state = 'closed';
	closedRow.native_support_state = 'supported';
	const sourceRepositoryRoot = createTemporaryGitRepository();
	const externalFile = createExternalTemporaryFile();
	const sourceTestPaths = [ 'tracked-source.mjs' ];
	symlinkSync(
		externalFile,
		resolve( sourceRepositoryRoot, sourceTestPaths[ 0 ] )
	);
	execFileSync( 'git', [
		'-C',
		sourceRepositoryRoot,
		'add',
		'--',
		sourceTestPaths[ 0 ],
	] );
	const sourceTestSha256 = calculateCurrentSourceBundleSha256(
		sourceTestPaths,
		sourceRepositoryRoot
	);
	const evidence = createValidEvidence( closedRow, {
		source_test_paths: sourceTestPaths,
		source_test_sha256: sourceTestSha256,
		closures: [
			createClosureEntry( closedRow, {
				reviews: REQUIRED_CLOSURE_REVIEW_ROLES.map( ( role ) => ( {
					role,
					verdict: 'APPROVE',
					source_test_sha256: sourceTestSha256,
					summary: `${ role } closure review approved`,
				} ) ),
			} ),
		],
	} );

	assert.throws(
		() =>
			validateMigrationEvidence( evidence, {
				...createEvidenceContext( closedRow ),
				repositoryRoot: sourceRepositoryRoot,
			} ),
		/current source must be a tracked regular file within the repository/
	);
} );

test( 'terminal evidence rejects a tracked symlink to an untracked in-repository file', () => {
	const closedRow = cloneContractMap().rows[ 0 ];
	specify( closedRow );
	closedRow.migration_state = 'closed';
	closedRow.native_support_state = 'supported';
	const sourceRepositoryRoot = createTemporaryGitRepository();
	const sourceTestPaths = [ 'tracked-source.mjs' ];
	const untrackedTargetPath = resolve(
		sourceRepositoryRoot,
		'untracked-target.mjs'
	);
	writeFileSync( untrackedTargetPath, 'untracked in-repository source\n' );
	symlinkSync(
		untrackedTargetPath,
		resolve( sourceRepositoryRoot, sourceTestPaths[ 0 ] )
	);
	execFileSync( 'git', [
		'-C',
		sourceRepositoryRoot,
		'add',
		'--',
		sourceTestPaths[ 0 ],
	] );
	assert.equal(
		execFileSync( 'git', [ '-C', sourceRepositoryRoot, 'ls-files' ], {
			encoding: 'utf8',
		} ).trim(),
		sourceTestPaths[ 0 ]
	);
	const sourceTestSha256 = calculateCurrentSourceBundleSha256(
		sourceTestPaths,
		sourceRepositoryRoot
	);
	const evidence = createValidEvidence( closedRow, {
		source_test_paths: sourceTestPaths,
		source_test_sha256: sourceTestSha256,
		closures: [
			createClosureEntry( closedRow, {
				reviews: REQUIRED_CLOSURE_REVIEW_ROLES.map( ( role ) => ( {
					role,
					verdict: 'APPROVE',
					source_test_sha256: sourceTestSha256,
					summary: `${ role } closure review approved`,
				} ) ),
			} ),
		],
	} );

	assert.throws(
		() =>
			validateMigrationEvidence( evidence, {
				...createEvidenceContext( closedRow ),
				repositoryRoot: sourceRepositoryRoot,
			} ),
		/current source must be a tracked regular file within the repository/
	);
} );

test( 'terminal evidence attests modified tracked working-tree bytes', () => {
	const closedRow = cloneContractMap().rows[ 0 ];
	specify( closedRow );
	closedRow.migration_state = 'closed';
	closedRow.native_support_state = 'supported';
	const sourceRepositoryRoot = createTemporaryGitRepository();
	const sourceTestPaths = [ 'tracked-source.mjs' ];
	closedRow.target_path = sourceTestPaths[ 0 ];
	const sourcePath = resolve( sourceRepositoryRoot, sourceTestPaths[ 0 ] );
	writeFileSync( sourcePath, 'indexed source bytes\n' );
	execFileSync( 'git', [
		'-C',
		sourceRepositoryRoot,
		'add',
		'--',
		sourceTestPaths[ 0 ],
	] );
	writeFileSync( sourcePath, 'modified working-tree source bytes\n' );
	const sourceTestSha256 = calculateCurrentSourceBundleSha256(
		sourceTestPaths,
		sourceRepositoryRoot
	);
	const evidence = createValidEvidence( closedRow, {
		source_test_paths: sourceTestPaths,
		source_test_sha256: sourceTestSha256,
		closures: [
			createClosureEntry( closedRow, {
				reviews: REQUIRED_CLOSURE_REVIEW_ROLES.map( ( role ) => ( {
					role,
					verdict: 'APPROVE',
					source_test_sha256: sourceTestSha256,
					summary: `${ role } closure review approved`,
				} ) ),
			} ),
		],
	} );

	assert.doesNotThrow( () =>
		validateMigrationEvidence( evidence, {
			...createEvidenceContext( closedRow ),
			repositoryRoot: sourceRepositoryRoot,
		} )
	);
} );

test( 'implemented evidence still validates against the verified revision', () => {
	const implementedRow = cloneContractMap().rows[ 0 ];
	specify( implementedRow );
	implementedRow.migration_state = 'implemented';
	const sourceTestPaths = [
		'plugins/woocommerce/tests/e2e/bin/lib/woopayments-contract-map.mjs',
	];
	const lastTouchCommit = execFileSync(
		'git',
		[
			'-C',
			repositoryRoot,
			'log',
			'-n',
			'1',
			'--format=%H',
			'--',
			sourceTestPaths[ 0 ],
		],
		{ encoding: 'utf8' }
	).trim();
	const historicalCommit = execFileSync(
		'git',
		[ '-C', repositoryRoot, 'rev-parse', `${ lastTouchCommit }^` ],
		{ encoding: 'utf8' }
	).trim();
	const historicalSha256 = calculateSourceBundleSha256(
		sourceTestPaths,
		historicalCommit
	);
	const currentSha256 = calculateSourceBundleSha256( sourceTestPaths );
	assert.notEqual( historicalSha256, currentSha256 );

	const evidence = createValidEvidence( implementedRow, {
		verified_at_commit: historicalCommit,
		source_test_paths: sourceTestPaths,
		source_test_sha256: historicalSha256,
		reviews: [
			{
				role: 'code',
				verdict: 'APPROVE',
				source_test_sha256: historicalSha256,
				summary: 'verified revision source bundle approved',
			},
		],
	} );

	assert.doesNotThrow( () =>
		validateMigrationEvidence(
			evidence,
			createEvidenceContext( implementedRow )
		)
	);
} );

test( 'accepts public-safe Phase 3 proof through the approved evidence entries', () => {
	const artifactSha256 = 'b'.repeat( 64 );
	const providerCorrelationSha256 = 'c'.repeat( 64 );
	const row = {
		...contractMap.rows[ 0 ],
		target_contract:
			'Shopper card payment: pays with the selected saved method',
		migration_state: 'closed',
		native_support_state: 'supported',
	};
	const evidence = createValidEvidence( row, {
		verification: [
			{
				command:
					'pnpm exec playwright test tests/e2e/tests/woopayments-native/shopper/card-payment.spec.ts',
				exit_code: 0,
				summary: `Target/title passed; provider redacted:payment-intent:sha256:${ providerCorrelationSha256 }; cleanup restored; quarantine not required; artifact SHA-256 ${ artifactSha256 }`,
			},
		],
		reviews: [
			{
				role: 'spec',
				verdict: 'APPROVE',
				source_test_sha256: DEFAULT_SOURCE_TEST_SHA256,
				summary: `Specification provenance approved at artifact SHA-256 ${ artifactSha256 }`,
			},
			{
				role: 'code',
				verdict: 'APPROVE',
				source_test_sha256: DEFAULT_SOURCE_TEST_SHA256,
				summary:
					'The password and token safeguards were reviewed; no credential values were recorded',
			},
		],
		closures: [ createClosureEntry( row ) ],
	} );

	assert.doesNotThrow( () =>
		validateMigrationEvidence( evidence, createEvidenceContext( row ) )
	);
} );

for ( const [ description, mutate, expectedError ] of [
	[
		'an unsupported schema version',
		( evidence ) => {
			evidence.schema_version = 1;
		},
		/expected 2/,
	],
	[
		'an empty slice ID',
		( evidence ) => {
			evidence.slice_id = '';
		},
		/slice_id/,
	],
	[
		'an invalid WC base commit',
		( evidence ) => {
			evidence.wc_base_commit = 'A'.repeat( 40 );
		},
		/wc_base_commit/,
	],
	[
		'an invalid verified-at commit',
		( evidence ) => {
			evidence.verified_at_commit = 'not-a-commit';
		},
		/verified_at_commit/,
	],
	[
		'a reference commit that differs from frozen metadata',
		( evidence ) => {
			evidence.reference_contract_commit = '4'.repeat( 40 );
		},
		/reference_contract_commit/,
	],
	[
		'a missing referencing contract ID',
		( evidence ) => {
			evidence.contract_ids = [ 'another-contract-id' ];
		},
		/contract_ids/,
	],
	[
		'a missing exact target path',
		( evidence ) => {
			evidence.targets[ 0 ].path =
				'plugins/woocommerce/tests/e2e/tests/woopayments-native/other.spec.ts';
		},
		/targets/,
	],
	[
		'a missing exact target contract',
		( evidence ) => {
			evidence.targets[ 0 ].contract = 'A neighboring contract';
		},
		/targets/,
	],
] ) {
	test( `rejects evidence with ${ description }`, () => {
		const row = {
			...contractMap.rows[ 0 ],
			target_contract: 'Native fixture contract',
			migration_state: 'closed',
			native_support_state: 'supported',
		};
		const evidence = createValidEvidence( row );

		mutate( evidence );

		assert.throws(
			() =>
				validateMigrationEvidence(
					evidence,
					createEvidenceContext( row )
				),
			expectedError
		);
	} );
}

for ( const migrationState of [ 'implemented', 'verified', 'closed' ] ) {
	test( `rejects ${ migrationState } evidence without implementation commits`, () => {
		const row = {
			...contractMap.rows[ 0 ],
			target_contract: 'Native fixture contract',
			migration_state: migrationState,
			native_support_state:
				migrationState === 'implemented' ? 'known-gap' : 'supported',
			gap_or_decision_reference:
				migrationState === 'implemented' ? 'issue:12345' : 'none',
		};
		const evidence = createValidEvidence( row, {
			implementation_commits: [],
			known_gaps:
				migrationState === 'implemented'
					? [
							{
								id: 'WPNATIVE-GAP-0001',
								owner: 'payments',
								reference: 'issue:12345',
								fingerprint: {
									error_name: 'Error',
									message_pattern:
										'^Native payment is unavailable\\.$',
								},
							},
					  ]
					: [],
		} );

		assert.throws(
			() =>
				validateMigrationEvidence(
					evidence,
					createEvidenceContext( row )
				),
			/implementation_commits/
		);
	} );
}

for ( const migrationState of [ 'verified', 'closed' ] ) {
	for ( const [ description, verification ] of [
		[ 'no verification commands', [] ],
		[
			'a failing verification command',
			[
				{
					command: 'pnpm test:e2e:woopayments:controller',
					exit_code: 1,
					summary: 'controller checks failed',
				},
			],
		],
	] ) {
		test( `rejects ${ migrationState } evidence with ${ description }`, () => {
			const row = {
				...contractMap.rows[ 0 ],
				target_contract: 'Native fixture contract',
				migration_state: migrationState,
				native_support_state: 'supported',
			};
			const evidence = createValidEvidence( row, { verification } );

			assert.throws(
				() =>
					validateMigrationEvidence(
						evidence,
						createEvidenceContext( row )
					),
				/verification/
			);
		} );
	}
}

for ( const [ description, reviews ] of [
	[ 'no reviews', [] ],
	[
		'a non-approving review',
		[
			{
				role: 'spec',
				verdict: 'REVISE',
				source_test_sha256: DEFAULT_SOURCE_TEST_SHA256,
				summary: 'contract needs revision',
			},
		],
	],
] ) {
	test( `rejects evidence with ${ description }`, () => {
		const row = {
			...contractMap.rows[ 0 ],
			target_contract: 'Native fixture contract',
			migration_state: 'closed',
			native_support_state: 'supported',
		};
		const evidence = createValidEvidence( row, { reviews } );

		assert.throws(
			() =>
				validateMigrationEvidence(
					evidence,
					createEvidenceContext( row )
				),
			/reviews/
		);
	} );
}

test( 'accepts historical slice reviews that predate the current source bundle', () => {
	const row = {
		...contractMap.rows[ 0 ],
		target_contract: 'Native fixture contract',
		migration_state: 'closed',
		native_support_state: 'supported',
	};
	const evidence = createValidEvidence( row, {
		reviews: [
			{
				role: 'spec',
				verdict: 'APPROVE',
				source_test_sha256: 'b'.repeat( 64 ),
				summary: 'historical source review approved',
			},
		],
		closures: [ createClosureEntry( row ) ],
	} );

	assert.doesNotThrow( () =>
		validateMigrationEvidence( evidence, createEvidenceContext( row ) )
	);
} );

test( 'rejects deferred evidence without a decision-ready deferral', () => {
	const row = {
		...contractMap.rows[ 0 ],
		migration_state: 'deferred',
		native_support_state: 'blocked-external',
	};
	const evidence = createValidEvidence( row, {
		implementation_commits: [],
		verification: [],
	} );

	assert.throws(
		() =>
			validateMigrationEvidence( evidence, createEvidenceContext( row ) ),
		/deferral/
	);
} );

test( 'accepts a decision-ready deferral without invented runtime evidence', () => {
	const row = {
		...contractMap.rows[ 0 ],
		migration_state: 'deferred',
		native_support_state: 'blocked-external',
		gap_or_decision_reference: 'issue:12345',
	};
	const evidence = createValidEvidence( row, {
		implementation_commits: [],
		verification: [],
		deferral: {
			blocker: 'External account authority is unavailable',
			affected_scope: 'The selected saved-payment contract',
			no_allowlisted_action_reason:
				'No repository-local action can grant account authority',
			reference: 'issue:12345',
			unlock_decision: 'Grant the required account authority',
			quarantine_status: 'No shared resource was allocated',
		},
	} );

	assert.doesNotThrow( () =>
		validateMigrationEvidence( evidence, createEvidenceContext( row ) )
	);
} );

for ( const unresolvedPlaceholder of [
	'none',
	'PeNdInG',
	'unknown',
	'TBD',
	'N / A',
	'not-assessed',
	'Owner Decision Required',
] ) {
	test( `rejects a deferred blocker using the unresolved placeholder ${ unresolvedPlaceholder }`, () => {
		const row = {
			...contractMap.rows[ 0 ],
			migration_state: 'deferred',
			native_support_state: 'blocked-external',
			gap_or_decision_reference: 'issue:12345',
		};
		const evidence = createValidEvidence( row, {
			implementation_commits: [],
			verification: [],
			deferral: {
				...createDecisionReadyDeferral( 'issue:12345' ),
				blocker: unresolvedPlaceholder,
			},
		} );

		assert.throws(
			() =>
				validateMigrationEvidence(
					evidence,
					createEvidenceContext( row )
				),
			/deferral/
		);
	} );
}

for ( const deferralField of [
	'blocker',
	'affected_scope',
	'no_allowlisted_action_reason',
	'unlock_decision',
	'quarantine_status',
] ) {
	test( `rejects an unresolved ${ deferralField } deferral field`, () => {
		const row = {
			...contractMap.rows[ 0 ],
			migration_state: 'deferred',
			native_support_state: 'blocked-external',
			gap_or_decision_reference: 'issue:12345',
		};
		const evidence = createValidEvidence( row, {
			implementation_commits: [],
			verification: [],
			deferral: {
				...createDecisionReadyDeferral( 'issue:12345' ),
				[ deferralField ]: 'pending',
			},
		} );

		assert.throws(
			() =>
				validateMigrationEvidence(
					evidence,
					createEvidenceContext( row )
				),
			/deferral/
		);
	} );
}

for ( const [ description, overrides ] of [
	[
		'a mismatched decision reference',
		{
			deferral: createDecisionReadyDeferral( 'issue:another-decision' ),
		},
	],
	[
		'invented implementation commits',
		{
			implementation_commits: [ '3'.repeat( 40 ) ],
			deferral: createDecisionReadyDeferral( 'issue:12345' ),
		},
	],
	[
		'invented verification results',
		{
			verification: [
				{
					command: 'pnpm test:e2e:woopayments:controller',
					exit_code: 0,
					summary: 'controller checks passed',
				},
			],
			deferral: createDecisionReadyDeferral( 'issue:12345' ),
		},
	],
] ) {
	test( `rejects deferred evidence with ${ description }`, () => {
		const row = {
			...contractMap.rows[ 0 ],
			migration_state: 'deferred',
			native_support_state: 'blocked-external',
			gap_or_decision_reference: 'issue:12345',
		};
		const evidence = createValidEvidence( row, {
			implementation_commits: [],
			verification: [],
			...overrides,
		} );

		assert.throws(
			() =>
				validateMigrationEvidence(
					evidence,
					createEvidenceContext( row )
				),
			/deferral|implementation_commits|verification/
		);
	} );
}

for ( const [ description, mutate ] of [
	[ 'no known gaps', ( evidence ) => ( evidence.known_gaps = [] ) ],
	[
		'a gap without a stable local ID',
		( evidence ) => {
			evidence.known_gaps[ 0 ].id = 'provider-gap';
		},
	],
	[
		'a gap without an owner',
		( evidence ) => {
			evidence.known_gaps[ 0 ].owner = '';
		},
	],
	[
		'a gap without an issue reference',
		( evidence ) => {
			evidence.known_gaps[ 0 ].reference = '';
		},
	],
	[
		'a gap without an error-name fingerprint',
		( evidence ) => {
			evidence.known_gaps[ 0 ].fingerprint.error_name = '';
		},
	],
	[
		'a gap without a message-pattern fingerprint',
		( evidence ) => {
			evidence.known_gaps[ 0 ].fingerprint.message_pattern = '';
		},
	],
	[
		'a gap with a catch-all zero-or-more fingerprint',
		( evidence ) => {
			evidence.known_gaps[ 0 ].fingerprint.message_pattern = '^.*$';
		},
	],
	[
		'a gap with a catch-all one-or-more fingerprint',
		( evidence ) => {
			evidence.known_gaps[ 0 ].fingerprint.message_pattern = '^.+$';
		},
	],
	[
		'a gap with an alternative match-all fingerprint',
		( evidence ) => {
			evidence.known_gaps[ 0 ].fingerprint.message_pattern =
				'^[\\s\\S]*$';
		},
	],
	[
		'a gap with an invalid anchored fingerprint',
		( evidence ) => {
			evidence.known_gaps[ 0 ].fingerprint.message_pattern = '^(?$';
		},
	],
	[
		'a gap bound to another decision reference',
		( evidence ) => {
			evidence.known_gaps[ 0 ].reference = 'issue:another-gap';
		},
	],
] ) {
	test( `rejects known-gap evidence with ${ description }`, () => {
		const row = {
			...contractMap.rows[ 0 ],
			target_contract: 'Native fixture contract',
			migration_state: 'implemented',
			native_support_state: 'known-gap',
			gap_or_decision_reference: 'issue:12345',
		};
		const evidence = createValidEvidence( row, {
			known_gaps: [
				{
					id: 'WPNATIVE-GAP-0001',
					owner: 'payments',
					reference: 'issue:12345',
					fingerprint: {
						error_name: 'Error',
						message_pattern: '^Native payment is unavailable\\.$',
					},
				},
			],
		} );

		mutate( evidence );

		assert.throws(
			() =>
				validateMigrationEvidence(
					evidence,
					createEvidenceContext( row )
				),
			/known_gaps/
		);
	} );
}

for ( const [ description, mutate ] of [
	[
		'an absolute filesystem path',
		( evidence ) => {
			evidence.verification[ 0 ].summary =
				'/private/provider/evidence.json';
		},
	],
	[
		'a secret-bearing key',
		( evidence ) => {
			evidence.verification[ 0 ].authorization = 'Bearer redacted';
		},
	],
	[
		'a raw provider payload',
		( evidence ) => {
			evidence.verification[ 0 ].raw_payload = {
				provider_id: 'redacted:provider-account',
			};
		},
	],
	[
		'an unexpected object key',
		( evidence ) => {
			evidence.unexpected_field = 'not part of the evidence contract';
		},
	],
] ) {
	test( `rejects evidence containing ${ description }`, () => {
		const row = {
			...contractMap.rows[ 0 ],
			target_contract: 'Native fixture contract',
			migration_state: 'closed',
			native_support_state: 'supported',
		};
		const evidence = createValidEvidence( row );

		mutate( evidence );

		assert.throws(
			() =>
				validateMigrationEvidence(
					evidence,
					createEvidenceContext( row )
				),
			/public-safe|unsafe/i
		);
	} );
}

for ( const [ description, unsafeValue ] of [
	[ 'an email address', 'Merchant email merchant@example.com was removed' ],
	[
		'a bearer credential',
		'Provider response used Bearer sk_test_51N4secretvalue',
	],
	[ 'a key-value credential', 'api_key=sk_test_51N4secretvalue pnpm test' ],
	[ 'a serialized JSON object', '{"result":"passed"}' ],
	[
		'an unredacted provider ID',
		'Provider payment intent pi_3MtwBwLkdIwHu7ix28a3tqPa was verified',
	],
	[
		'a provider correlation without a one-way hash',
		'Provider correlation redacted:payment-intent',
	],
] ) {
	test( `rejects evidence containing ${ description } in an allowed string field`, () => {
		const row = {
			...contractMap.rows[ 0 ],
			target_contract: 'Native fixture contract',
			migration_state: 'closed',
			native_support_state: 'supported',
		};
		const evidence = createValidEvidence( row );

		evidence.verification[ 0 ].summary = unsafeValue;

		assert.throws(
			() =>
				validateMigrationEvidence(
					evidence,
					createEvidenceContext( row )
				),
			/public-safe|unsafe/i
		);
	} );
}

test( 'rejects a standalone provider secret token in evidence strings', () => {
	const row = contractMap.rows[ 0 ];
	const evidence = createValidEvidence( row, {
		verification: [
			{
				command: 'pnpm test:e2e:woopayments:controller',
				exit_code: 0,
				summary: 'verified with sk_live_1234567890abcdefTESTONLY',
			},
		],
	} );

	assert.throws(
		() =>
			validateMigrationEvidence( evidence, createEvidenceContext( row ) ),
		/credentials are forbidden/
	);
} );

test( 'rejects JSON payloads embedded after a prose prefix', () => {
	const row = contractMap.rows[ 0 ];
	const evidence = createValidEvidence( row, {
		verification: [
			{
				command: 'pnpm test:e2e:woopayments:controller',
				exit_code: 0,
				summary:
					'provider response: {"name":"Synthetic Person","phone":"555-0100"}',
			},
		],
	} );

	assert.throws(
		() =>
			validateMigrationEvidence( evidence, createEvidenceContext( row ) ),
		/serialized payloads are forbidden/
	);
} );

for ( const [ description, embeddedPayload ] of [
	[ 'a numeric scalar-leading array', '[1234567890123456]' ],
	[ 'a boolean and null scalar-leading array', '[true,false,null]' ],
	[ 'an object with an escaped key', '{"na\\"me":"Synthetic Person"}' ],
] ) {
	test( `rejects ${ description } embedded after a prose prefix`, () => {
		const row = contractMap.rows[ 0 ];
		const evidence = createValidEvidence( row, {
			verification: [
				{
					command: 'pnpm test:e2e:woopayments:controller',
					exit_code: 0,
					summary: `provider response: ${ embeddedPayload }`,
				},
			],
		} );

		assert.throws(
			() =>
				validateMigrationEvidence(
					evidence,
					createEvidenceContext( row )
				),
			/serialized payloads are forbidden/
		);
	} );
}

test( 'accepts incidental non-JSON brackets and braces in evidence strings', () => {
	const row = contractMap.rows[ 0 ];
	const evidence = createValidEvidence( row, {
		verification: [
			{
				command: 'pnpm test:e2e:woopayments:controller',
				exit_code: 0,
				summary:
					'Verified items[primary] with the expected {status} placeholder',
			},
		],
	} );

	assert.doesNotThrow( () =>
		validateMigrationEvidence( evidence, createEvidenceContext( row ) )
	);
} );

test( 'rejects opening-heavy evidence strings over 4096 characters', () => {
	const row = contractMap.rows[ 0 ];
	const evidence = createValidEvidence( row, {
		verification: [
			{
				command: 'pnpm test:e2e:woopayments:controller',
				exit_code: 0,
				summary: '['.repeat( 4097 ),
			},
		],
	} );

	assert.throws(
		() =>
			validateMigrationEvidence( evidence, createEvidenceContext( row ) ),
		/evidence strings must not exceed 4096 characters/
	);
} );

test( 'accepts safe evidence strings exactly 4096 characters long', () => {
	const row = contractMap.rows[ 0 ];
	const evidence = createValidEvidence( row, {
		verification: [
			{
				command: 'pnpm test:e2e:woopayments:controller',
				exit_code: 0,
				summary: 'x'.repeat( 4096 ),
			},
		],
	} );

	assert.doesNotThrow( () =>
		validateMigrationEvidence( evidence, createEvidenceContext( row ) )
	);
} );

test( 'rejects JWT-shaped tokens in evidence strings', () => {
	const row = contractMap.rows[ 0 ];
	const evidence = createValidEvidence( row, {
		verification: [
			{
				command: 'pnpm test:e2e:woopayments:controller',
				exit_code: 0,
				summary:
					'session eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl',
			},
		],
	} );

	assert.throws(
		() =>
			validateMigrationEvidence( evidence, createEvidenceContext( row ) ),
		/credentials are forbidden/
	);
} );

test( 'require-saturated rejects a planned contract', () => {
	assert.throws(
		() => validate( cloneContractMap(), { requireSaturated: true } ),
		/Unsaturated contract: /
	);
} );

test( 'require-saturated accepts only closed or deferred contracts', () => {
	const map = cloneContractMap();

	for ( const row of map.rows ) {
		row.migration_state = 'deferred';
		row.native_support_state = 'blocked-external';
		row.gap_or_decision_reference = 'issue:inventory-deferral';
		row.evidence_path = createEvidenceFile( row );
	}

	assert.doesNotThrow( () => validate( map, { requireSaturated: true } ) );
} );

test( 'require-migrated rejects any state other than closed', () => {
	assert.throws(
		() => validate( cloneContractMap(), { requireMigrated: true } ),
		/Unmigrated contract: /
	);
} );

test( 'require-closed remains an alias for migrated semantics', () => {
	assert.throws(
		() =>
			runCli( [ '--require-closed' ], {
				ledgerContent,
				metadata,
				repositoryRoot,
				log: () => {},
			} ),
		/Unmigrated contract: /
	);
} );

test( 'require-migrated rejects known gaps and deferrals', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	row.migration_state = 'deferred';
	row.native_support_state = 'known-gap';
	row.gap_or_decision_reference = 'issue:known-gap';
	row.evidence_path = createEvidenceFile( row );

	assert.throws(
		() => validate( map, { requireMigrated: true } ),
		new RegExp( `Unmigrated contract: ${ row.case_id }` )
	);
} );

test( 'require-migrated accepts a fully closed inventory with zero gaps', () => {
	const map = cloneContractMap();

	const sourceRepositoryRoot = closeInventory( map );

	assert.doesNotThrow( () =>
		validate( map, {
			repositoryRoot: sourceRepositoryRoot,
			requireMigrated: true,
		} )
	);
} );

test( 'keeps frozen columns bound to the metadata SHA-256', () => {
	const summary = validate( cloneContractMap() );
	const map = cloneContractMap();

	assert.equal( summary.frozenSourceSha256, metadata.frozen_source_sha256 );

	map.rows[ 0 ].contract = `${ map.rows[ 0 ].contract } changed`;
	assert.throws(
		() => validate( map ),
		new RegExp(
			`Frozen source content drifted; expected SHA-256 ${ metadata.frozen_source_sha256 }`
		)
	);
} );

for ( const [ field, invalidValue ] of [
	[ 'schema_version', 2 ],
	[ 'source_repository', 'example/other-repository' ],
	[ 'source_commit', '0000000000000000000000000000000000000000' ],
	[ 'frozen_contract_count', 180 ],
	[
		'frozen_source_sha256',
		'0000000000000000000000000000000000000000000000000000000000000000',
	],
] ) {
	test( `rejects metadata mismatch for ${ field }`, () => {
		assert.throws(
			() =>
				validate( cloneContractMap(), {
					metadata: {
						...metadata,
						[ field ]: invalidValue,
					},
				} ),
			new RegExp( `Invalid contract-map metadata ${ field }; expected` )
		);
	} );
}

test( 'rejects the wrong inventory row count', () => {
	const map = cloneContractMap();

	map.rows.pop();

	assert.throws( () => validate( map ), /Expected 181 cases; found 180/ );
} );

test( 'rejects duplicate case IDs', () => {
	const map = cloneContractMap();

	map.rows[ 1 ].case_id = map.rows[ 0 ].case_id;

	assert.throws(
		() => validate( map ),
		new RegExp( `Duplicate case_id: ${ map.rows[ 0 ].case_id }` )
	);
} );

test( 'summarizes migration and native-support states', () => {
	const summary = validate( cloneContractMap() );

	assert.equal(
		summary.migrationStateCounts.planned,
		contractMap.rows.filter( ( row ) => row.migration_state === 'planned' )
			.length
	);
	assert.equal(
		summary.migrationStateCounts.specified,
		contractMap.rows.filter(
			( row ) => row.migration_state === 'specified'
		).length
	);
	assert.equal(
		summary.nativeSupportStateCounts[ 'not-assessed' ],
		contractMap.rows.filter(
			( row ) => row.native_support_state === 'not-assessed'
		).length
	);
} );
