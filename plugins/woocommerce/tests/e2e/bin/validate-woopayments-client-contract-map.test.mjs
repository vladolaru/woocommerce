import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import {
	existsSync,
	mkdirSync,
	mkdtempSync,
	readFileSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { dirname, join, relative, resolve, sep as pathSeparator } from 'node:path';
import { tmpdir } from 'node:os';
import { afterEach, test } from 'node:test';
import { fileURLToPath } from 'node:url';

import {
	parseContractMap,
	validateContractMap,
} from './lib/woopayments-contract-map.mjs';
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
const fidelityPartitionRepositoryPath =
	'plugins/woocommerce/tests/e2e/tests/woopayments-native/fidelity-partition.tsv';
const fidelityClaimCaseId =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-manual-capture.spec.ts:39::Order › Manual Capture › should create an "On hold" order then capture the charge';
const fidelityPartitionHeaders = [
	'case_id',
	'treatment',
	'fidelity_family',
	'condition_2_verdict',
];
const metadata = JSON.parse( readFileSync( metadataPath, 'utf8' ) );
const ledgerContent = readFileSync( ledgerPath, 'utf8' );
const contractMap = parseContractMap( ledgerContent );
const retirementDisposition =
	'Retire because the test is stale, redundant, or guards only obsolete plugin structure';
// Real, tracked files in this repository; closures may point at them without
// creating fixtures.
const trackedSpecTarget =
	'plugins/woocommerce/tests/e2e/tests/woopayments-native/merchant/settings-methods.spec.ts';
const trackedPhpTarget =
	'plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsTokenServiceTest.php';
const trackedEvidencePointer =
	'plugins/woocommerce/tests/e2e/tests/woopayments-native/evidence/pilot-calibration.json';
const temporaryDirectories = [];
const externalTemporaryDirectories = [];

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
} );

const cloneContractMap = ( source = contractMap ) => ( {
	headers: [ ...source.headers ],
	rows: source.rows.map( ( row ) => ( { ...row } ) ),
} );

const serializeContractMap = ( map ) =>
	[
		map.headers,
		...map.rows.map( ( row ) =>
			map.headers.map( ( header ) => row[ header ] )
		),
	]
		.map( ( values ) => values.join( '\t' ) )
		.join( '\n' ) + '\n';

const serializeFidelityPartition = ( rows ) =>
	[
		fidelityPartitionHeaders,
		...rows.map( ( row ) =>
			fidelityPartitionHeaders.map( ( header ) => row[ header ] )
		),
	]
		.map( ( values ) => values.join( '\t' ) )
		.join( '\n' ) + '\n';

const repositoryRelativePath = ( absolutePath ) =>
	relative( repositoryRoot, absolutePath ).split( pathSeparator ).join( '/' );

// Fixtures for untracked-file checks live inside the repository working tree
// but never enter the Git index.
const createRepositoryTemporaryDirectory = () => {
	const temporaryDirectory = mkdtempSync(
		join( binDirectory, '.contract-map-test-' )
	);

	temporaryDirectories.push( temporaryDirectory );
	return temporaryDirectory;
};

const createTemporaryGitRepository = () => {
	const temporaryDirectory = mkdtempSync(
		join( tmpdir(), 'woocommerce-contract-map-' )
	);

	externalTemporaryDirectories.push( temporaryDirectory );
	execFileSync( 'git', [ '-C', temporaryDirectory, 'init', '--quiet' ] );
	return temporaryDirectory;
};

const createTrackedFileRepository = ( files ) => {
	const sourceRepositoryRoot = createTemporaryGitRepository();

	for ( const [ filePath, source ] of Object.entries( files ) ) {
		const absolutePath = resolve( sourceRepositoryRoot, filePath );

		mkdirSync( dirname( absolutePath ), { recursive: true } );
		writeFileSync( absolutePath, source );
	}

	execFileSync( 'git', [ '-C', sourceRepositoryRoot, 'add', '--all' ] );
	return sourceRepositoryRoot;
};

const validate = ( map, options = {} ) =>
	validateContractMap( map, {
		metadata,
		repositoryRoot,
		...options,
	} );

const closeSupported = ( row, targetPath = trackedSpecTarget ) => {
	row.accepted_disposition = row.planned_disposition;
	row.target_path = targetPath;
	row.target_contract = `Native contract for ${ row.case_id }`;
	row.implementation_owner = 'woocommerce-e2e';
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	row.gap_or_decision_reference = 'none';
	row.evidence_path = 'none';
};

const retireRow = ( row ) => {
	row.accepted_disposition = retirementDisposition;
	row.target_path = trackedPhpTarget;
	row.target_contract = `Retired as a duplicate of: a retained contract carrying ${ row.feature_family }`;
	row.implementation_owner = 'woocommerce-e2e';
	row.migration_state = 'closed';
	row.native_support_state = 'not-applicable-retired';
	row.gap_or_decision_reference = 'retirement-review-2026-08-10';
	row.evidence_path = 'none';
};

const deferRow = ( row, reference = 'blocked-environment:PILOT-FIXTURE' ) => {
	row.accepted_disposition = row.planned_disposition;
	row.target_contract = `Native contract for ${ row.case_id }`;
	row.implementation_owner = 'woocommerce-e2e';
	row.migration_state = 'deferred';
	row.native_support_state = 'blocked-environment';
	row.gap_or_decision_reference = reference;
	row.evidence_path = 'none';
};

// ---------------------------------------------------------------------------
// Parsing
// ---------------------------------------------------------------------------

test( 'rejects empty ledger content', () => {
	assert.throws(
		() => parseContractMap( '' ),
		/Contract map must be non-empty TSV content/
	);
} );

test( 'rejects a ledger without the exact ordered schema', () => {
	const map = cloneContractMap();
	const truncatedHeaders = map.headers.slice( 0, -1 );
	const content =
		[
			truncatedHeaders,
			...map.rows.map( ( row ) =>
				truncatedHeaders.map( ( header ) => row[ header ] )
			),
		]
			.map( ( values ) => values.join( '\t' ) )
			.join( '\n' ) + '\n';

	assert.throws(
		() => parseContractMap( content ),
		/Invalid contract-map schema; expected the exact 40 ordered columns/
	);
} );

test( 'rejects a row with the wrong column count', () => {
	const lines = serializeContractMap( cloneContractMap() ).split( '\n' );

	lines[ 1 ] += '\textra-column';

	assert.throws(
		() => parseContractMap( lines.join( '\n' ) ),
		/Row 2 has 41 columns; expected 40/
	);
} );

test( 'parses the current ledger into 181 contracts', () => {
	assert.equal( contractMap.rows.length, 181 );
} );

// ---------------------------------------------------------------------------
// Metadata pinning and the frozen inventory
// ---------------------------------------------------------------------------

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

test( 'rejects an unparsed contract map', () => {
	assert.throws( () => validate( null ), /Invalid parsed contract map/ );
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

test( 'rejects the wrong inventory row count', () => {
	const map = cloneContractMap();

	map.rows.pop();

	assert.throws( () => validate( map ), /Expected 181 cases; found 180/ );
} );

test( 'rejects a contract without a case_id', () => {
	const map = cloneContractMap();

	map.rows[ 0 ].case_id = '';

	assert.throws( () => validate( map ), /Found a contract without a case_id/ );
} );

test( 'rejects duplicate case IDs', () => {
	const map = cloneContractMap();

	map.rows[ 1 ].case_id = map.rows[ 0 ].case_id;

	assert.throws(
		() => validate( map ),
		new RegExp( `Duplicate case_id: ${ map.rows[ 0 ].case_id }` )
	);
} );

// ---------------------------------------------------------------------------
// Editable columns and legal values
// ---------------------------------------------------------------------------

test( 'rejects an empty editable column', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	row.evidence_path = '';

	assert.throws(
		() => validate( map ),
		new RegExp( `Incomplete closure fields for ${ row.case_id }` )
	);
} );

test( 'rejects an unknown migration_state', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	row.migration_state = 'complete';

	assert.throws(
		() => validate( map ),
		new RegExp( `Invalid migration_state for ${ row.case_id }: complete` )
	);
} );

test( 'rejects an unknown native_support_state', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	row.native_support_state = 'mostly-supported';

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Invalid native_support_state for ${ row.case_id }: mostly-supported`
		)
	);
} );

test( 'rejects an accepted_disposition outside the legal sentences', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	row.accepted_disposition = 'Just retire it';

	assert.throws(
		() => validate( map ),
		new RegExp( `Invalid accepted_disposition for ${ row.case_id }` )
	);
} );

test( 'accepts a disposition that departs from the plan without any approval token', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	closeSupported( row );
	row.accepted_disposition =
		row.planned_disposition === 'Run unchanged against both runtimes'
			? 'Rewrite as a native-specific E2E test preserving the contract'
			: 'Run unchanged against both runtimes';
	assert.notEqual( row.accepted_disposition, row.planned_disposition );

	assert.doesNotThrow( () => validate( map ) );
} );

// ---------------------------------------------------------------------------
// evidence_path: an optional pointer, never schema-validated content
// ---------------------------------------------------------------------------

test( 'accepts evidence_path none on a closed contract', () => {
	const map = cloneContractMap();

	closeSupported( map.rows[ 0 ] );

	assert.doesNotThrow( () => validate( map ) );
} );

test( 'accepts a tracked evidence pointer without validating its content', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	closeSupported( row );
	// A packet written for entirely different rows: only existence and
	// tracking matter now.
	row.evidence_path = trackedEvidencePointer;

	assert.doesNotThrow( () => validate( map ) );
} );

test( 'rejects more than one evidence file', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	deferRow( row );
	row.evidence_path = `${ trackedEvidencePointer };${ trackedEvidencePointer }`;

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Evidence must be none or one tracked JSON file for ${ row.case_id }`
		)
	);
} );

test( 'rejects a non-JSON evidence pointer', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	deferRow( row );
	row.evidence_path = trackedSpecTarget;

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Evidence must be none or one tracked JSON file for ${ row.case_id }`
		)
	);
} );

test( 'rejects a missing evidence file', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	deferRow( row );
	row.evidence_path =
		'plugins/woocommerce/tests/e2e/bin/does-not-exist-evidence.json';

	assert.throws(
		() => validate( map ),
		new RegExp( `Non-concrete evidence_path for ${ row.case_id }` )
	);
} );

test( 'rejects an untracked evidence file', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];
	const temporaryDirectory = createRepositoryTemporaryDirectory();
	const evidencePath = join( temporaryDirectory, 'evidence.json' );

	writeFileSync( evidencePath, '{}\n' );
	deferRow( row );
	row.evidence_path = repositoryRelativePath( evidencePath );

	assert.throws(
		() => validate( map ),
		new RegExp( `Untracked evidence_path for ${ row.case_id }` )
	);
} );

// ---------------------------------------------------------------------------
// Closed rows: existing tracked targets, supported or retired
// ---------------------------------------------------------------------------

test( 'rejects a closed contract that is neither supported nor retired', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	closeSupported( row );
	row.native_support_state = 'known-gap';
	row.gap_or_decision_reference = 'issue:known-gap';

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Closed contract must be supported or retired onto a retained contract: ${ row.case_id }`
		)
	);
} );

test( 'accepts a retired closure that names its retained contract', () => {
	const map = cloneContractMap();

	retireRow( map.rows[ 0 ] );

	assert.doesNotThrow( () => validate( map ) );
} );

test( 'rejects a retired closure without a retained-contract reference', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	retireRow( row );
	row.target_contract = 'pending';

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Closed contract must be supported or retired onto a retained contract: ${ row.case_id }`
		)
	);
} );

test( 'rejects a retired support state without the retirement disposition', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	retireRow( row );
	row.accepted_disposition = 'Run unchanged against both runtimes';

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Closed contract must be supported or retired onto a retained contract: ${ row.case_id }`
		)
	);
} );

test( 'rejects repository path traversal in a target', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	closeSupported( row );
	row.target_path = '../outside-repository.spec.ts';

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Invalid target_path for ${ row.case_id }: ../outside-repository.spec.ts`
		)
	);
} );

test( 'rejects a missing closed target file', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	closeSupported(
		row,
		'plugins/woocommerce/tests/e2e/bin/does-not-exist.spec.ts'
	);

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

test( 'rejects an existing directory as a closed target file', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];
	const temporaryDirectory = createRepositoryTemporaryDirectory();

	closeSupported( row, repositoryRelativePath( temporaryDirectory ) );

	assert.throws(
		() => validate( map ),
		new RegExp(
			`Non-concrete or out-of-repository target_path for ${ row.case_id }`
		)
	);
} );

test( 'rejects an untracked closed target file', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];
	const temporaryDirectory = createRepositoryTemporaryDirectory();
	const targetPath = join( temporaryDirectory, 'fixture.spec.ts' );

	writeFileSync( targetPath, '// Contract-map test fixture.\n' );
	closeSupported( row, repositoryRelativePath( targetPath ) );

	assert.throws(
		() => validate( map ),
		new RegExp( `Untracked target_path for ${ row.case_id }` )
	);
} );

test( 'checks every segment of a multi-target closed row', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	closeSupported( row, `${ trackedPhpTarget };${ trackedSpecTarget }` );
	assert.doesNotThrow( () => validate( map ) );

	closeSupported(
		row,
		`${ trackedPhpTarget };plugins/woocommerce/tests/e2e/bin/does-not-exist.spec.ts`
	);
	assert.throws(
		() => validate( map ),
		new RegExp( `Non-concrete target_path for ${ row.case_id }` )
	);
} );

test( 'a closed row may target only a lower-layer PHPUnit test', () => {
	const map = cloneContractMap();

	closeSupported( map.rows[ 0 ], trackedPhpTarget );

	assert.doesNotThrow( () => validate( map ) );
} );

test( 'non-closed rows may name targets that do not exist yet', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	deferRow( row );
	row.target_path =
		'plugins/woocommerce/tests/e2e/tests/woopayments-native/future/not-written-yet.spec.ts';

	assert.doesNotThrow( () => validate( map ) );
} );

// ---------------------------------------------------------------------------
// Deferred rows
// ---------------------------------------------------------------------------

test( 'accepts a deferred contract with a one-line reason', () => {
	const map = cloneContractMap();

	deferRow( map.rows[ 0 ] );

	assert.doesNotThrow( () => validate( map ) );
} );

for ( const placeholder of [ 'none', 'pending' ] ) {
	test( `rejects a deferred contract with reference ${ placeholder }`, () => {
		const map = cloneContractMap();
		const row = map.rows[ 0 ];

		deferRow( row, placeholder );

		assert.throws(
			() => validate( map ),
			new RegExp(
				`Deferred contract requires a gap or decision reference: ${ row.case_id }`
			)
		);
	} );
}

// ---------------------------------------------------------------------------
// Saturation and migration gates
// ---------------------------------------------------------------------------

test( 'require-saturated accepts the current closed-or-deferred inventory', () => {
	assert.doesNotThrow( () =>
		validate( cloneContractMap(), { requireSaturated: true } )
	);
} );

test( 'require-saturated rejects a contract that is still in flight', () => {
	const map = cloneContractMap();
	const row = map.rows[ 0 ];

	deferRow( row );
	row.migration_state = 'specified';

	assert.throws(
		() => validate( map, { requireSaturated: true } ),
		new RegExp( `Unsaturated contract: ${ row.case_id }` )
	);
} );

test( 'require-migrated rejects the current inventory while deferrals remain', () => {
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

test( 'require-migrated accepts a fully closed inventory with zero gaps', () => {
	const map = cloneContractMap();
	const files = {};

	for ( const row of map.rows ) {
		closeSupported( row, row.target_path );
		for ( const targetPath of row.target_path.split( ';' ) ) {
			files[ targetPath ] = '// Contract-map test fixture.\n';
		}
	}

	const sourceRepositoryRoot = createTrackedFileRepository( files );

	assert.doesNotThrow( () =>
		validate( map, {
			repositoryRoot: sourceRepositoryRoot,
			requireMigrated: true,
		} )
	);
} );

test( 'summarizes migration and native-support states', () => {
	const summary = validate( cloneContractMap() );

	assert.equal(
		summary.migrationStateCounts.closed,
		contractMap.rows.filter( ( row ) => row.migration_state === 'closed' )
			.length
	);
	assert.equal(
		summary.migrationStateCounts.deferred,
		contractMap.rows.filter(
			( row ) => row.migration_state === 'deferred'
		).length
	);
	assert.equal(
		summary.nativeSupportStateCounts.supported,
		contractMap.rows.filter(
			( row ) => row.native_support_state === 'supported'
		).length
	);
} );

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

test( 'CLI validates the real ledger and prints the summary', () => {
	const logLines = [];

	const summary = runCli( [ '--summary' ], {
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

	const summarizedNativeSupportStates = Object.entries(
		summary.nativeSupportStateCounts
	)
		.filter( ( [ , count ] ) => count > 0 )
		.map(
			( [ state, count ] ) =>
				`${ count }\tnative_support_state\t${ state }`
		);
	assert.equal(
		summarizedNativeSupportStates.every( ( line ) =>
			logLines.includes( line )
		),
		true
	);
} );

test( 'CLI stays quiet without the summary flag', () => {
	const logLines = [];

	runCli( [], {
		ledgerContent,
		metadata,
		repositoryRoot,
		log: ( line ) => logLines.push( line ),
	} );

	assert.deepEqual( logLines, [
		'Validated 181 WooPayments client contracts.',
	] );
} );

test( 'CLI rejects unknown arguments', () => {
	assert.throws(
		() =>
			runCli( [ '--nope' ], {
				ledgerContent,
				metadata,
				repositoryRoot,
				log: () => {},
			} ),
		/Unknown argument\(s\): --nope/
	);
} );

test( 'CLI no longer recognizes the retired history mode', () => {
	assert.throws(
		() =>
			runCli( [ '--from-git-ref', 'HEAD' ], {
				ledgerContent,
				metadata,
				repositoryRoot,
				log: () => {},
			} ),
		/Unknown argument\(s\): --from-git-ref, HEAD/
	);
} );

// ---------------------------------------------------------------------------
// Fidelity claim citations, read best-effort from evidence packets
// ---------------------------------------------------------------------------

const fidelityPacketPath = 'evidence/fidelity-citation.json';

const runFidelityCitation = ( packetContent, partitionRows ) => {
	const map = cloneContractMap();

	// Deferring every row detaches the ledger from this repository's files so
	// the run can validate against a minimal fixture repository.
	for ( const row of map.rows ) {
		deferRow( row );
	}

	const row = map.rows.find(
		( candidate ) => candidate.case_id === fidelityClaimCaseId
	);

	assert.notEqual( row, undefined );
	row.evidence_path = fidelityPacketPath;

	const sourceRepositoryRoot = createTrackedFileRepository( {
		[ fidelityPacketPath ]: packetContent,
	} );

	return runCli( [], {
		ledgerContent: serializeContractMap( map ),
		metadata,
		repositoryRoot: sourceRepositoryRoot,
		readFidelityPartition: ( repositoryPath ) => {
			assert.equal( repositoryPath, fidelityPartitionRepositoryPath );
			return serializeFidelityPartition( partitionRows );
		},
		log: () => {},
	} );
};

const createCitationPacket = (
	fidelityClaim = 'manual-authorization-capture'
) =>
	`${ JSON.stringify( {
		closures: [
			{
				contract_id: fidelityClaimCaseId,
				fidelity_claim: fidelityClaim,
			},
		],
	} ) }\n`;

const createFidelityPartitionRow = ( overrides = {} ) => ( {
	case_id: fidelityClaimCaseId,
	treatment: 'fidelity',
	fidelity_family: 'manual-authorization-capture',
	condition_2_verdict: 'dischargeable',
	...overrides,
} );

test( 'accepts a closure citing its dischargeable fidelity family', () => {
	assert.doesNotThrow( () =>
		runFidelityCitation( createCitationPacket(), [
			createFidelityPartitionRow(),
		] )
	);
} );

test( 'rejects a closure citing a different fidelity family', () => {
	assert.throws(
		() =>
			runFidelityCitation(
				createCitationPacket( 'card-decline-vocabulary' ),
				[ createFidelityPartitionRow() ]
			),
		/fidelity_family is manual-authorization-capture, not cited card-decline-vocabulary/
	);
} );

for ( const [ description, partitionOverrides, expectedError ] of [
	[
		'a conventional partition row',
		{
			treatment: 'conventional',
			fidelity_family: 'conventional',
			condition_2_verdict: 'not-applicable',
		},
		/requires treatment fidelity; found conventional/,
	],
	[
		'a not-dischargeable partition row',
		{ condition_2_verdict: 'not-dischargeable' },
		/requires condition_2_verdict dischargeable; found not-dischargeable/,
	],
] ) {
	test( `rejects a fidelity citation for ${ description }`, () => {
		assert.throws(
			() =>
				runFidelityCitation( createCitationPacket(), [
					createFidelityPartitionRow( partitionOverrides ),
				] ),
			expectedError
		);
	} );
}

test( 'rejects a fidelity citation missing from the partition', () => {
	assert.throws(
		() =>
			runFidelityCitation( createCitationPacket(), [
				createFidelityPartitionRow( {
					case_id: 'a-different-contract-id',
				} ),
			] ),
		/exactly one matching case_id; found 0/
	);
} );

test( 'rejects a fidelity citation duplicated in the partition', () => {
	const partitionRow = createFidelityPartitionRow();

	assert.throws(
		() =>
			runFidelityCitation( createCitationPacket(), [
				partitionRow,
				partitionRow,
			] ),
		/exactly one matching case_id; found 2/
	);
} );

for ( const [ description, packetContent ] of [
	[ 'a packet that is not JSON', 'not json at all\n' ],
	[ 'a packet without closures', '{}\n' ],
	[ 'a packet whose closures are not an array', '{"closures":{}}\n' ],
	[
		'a closure without a fidelity claim',
		'{"closures":[{"contract_id":"whatever"}]}\n',
	],
] ) {
	test( `tolerates ${ description }`, () => {
		assert.doesNotThrow( () =>
			runFidelityCitation( packetContent, [] )
		);
	} );
}

test( 'keeps closures without a fidelity citation backward compatible', () => {
	assert.doesNotThrow( () =>
		runCli( [], {
			ledgerContent,
			metadata,
			repositoryRoot,
			readFidelityPartition: () => {
				throw new Error(
					'The partition must not be read without a citation'
				);
			},
			log: () => {},
		} )
	);
} );
