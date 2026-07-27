import assert from 'node:assert/strict';
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
const temporaryDirectories = [];
const externalTemporaryDirectories = [];
const temporaryFiles = [];
const temporaryTargetDirectories = new Set();

const cloneContractMap = ( source = contractMap ) => ( {
	headers: [ ...source.headers ],
	rows: source.rows.map( ( row ) => ( { ...row } ) ),
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

const createRepositoryTemporaryDirectory = () => {
	const temporaryDirectory = mkdtempSync(
		join( binDirectory, '.contract-map-test-' )
	);

	temporaryDirectories.push( temporaryDirectory );
	return temporaryDirectory;
};

const createEvidenceFile = () => {
	const temporaryDirectory = createRepositoryTemporaryDirectory();
	const evidencePath = join( temporaryDirectory, 'evidence.txt' );

	writeFileSync( evidencePath, 'contract evidence\n' );

	return repositoryRelativePath( evidencePath );
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

const close = ( row ) => {
	specify( row );
	row.migration_state = 'closed';
	row.native_support_state = 'supported';
	row.gap_or_decision_reference = 'none';
	row.evidence_path = row.target_path;
};

const closeInventory = ( map ) => {
	for ( const row of map.rows ) {
		for ( const targetPath of row.target_path.split( ';' ) ) {
			createMissingTargetFile( targetPath );
		}

		close( row );
		if (
			row.accepted_disposition ===
			'Retire because the test is stale, redundant, or guards only obsolete plugin structure'
		) {
			row.native_support_state = 'not-applicable-retired';
			row.gap_or_decision_reference =
				'human-approved:inventory-retirement-review';
		}
	}
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
	assert.equal( logLines.includes( '181\tmigration_state\tplanned' ), true );
	assert.equal(
		logLines.includes( '181\tnative_support_state\tnot-assessed' ),
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

for ( const [ previous, next ] of [
	[ 'planned', 'closed' ],
	[ 'closed', 'implemented' ],
] ) {
	test( `rejects the ${ previous } -> ${ next } migration transition`, () => {
		assert.throws(
			() => validateStateTransition( previous, next ),
			new RegExp(
				`Illegal migration transition: ${ previous } -> ${ next }`
			)
		);
	} );
}

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
		row.evidence_path = createEvidenceFile();

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
		row.evidence_path = createEvidenceFile();

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
		row.evidence_path = createEvidenceFile();
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
	row.evidence_path = targetPath;

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
	row.evidence_path = targetPath;

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
	row.evidence_path = createEvidenceFile();

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
	row.evidence_path = createEvidenceFile();
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

test( 'require-saturated rejects a planned contract', () => {
	assert.throws(
		() => validate( cloneContractMap(), { requireSaturated: true } ),
		/Unsaturated contract: /
	);
} );

test( 'require-saturated accepts only closed or deferred contracts', () => {
	const map = cloneContractMap();
	const evidencePath = createEvidenceFile();

	for ( const row of map.rows ) {
		row.migration_state = 'deferred';
		row.native_support_state = 'blocked-external';
		row.gap_or_decision_reference = 'issue:inventory-deferral';
		row.evidence_path = evidencePath;
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
	row.evidence_path = createEvidenceFile();

	assert.throws(
		() => validate( map, { requireMigrated: true } ),
		new RegExp( `Unmigrated contract: ${ row.case_id }` )
	);
} );

test( 'require-migrated accepts a fully closed inventory with zero gaps', () => {
	const map = cloneContractMap();

	closeInventory( map );

	assert.doesNotThrow( () => validate( map, { requireMigrated: true } ) );
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
		metadata.frozen_contract_count
	);
	assert.equal(
		summary.nativeSupportStateCounts[ 'not-assessed' ],
		metadata.frozen_contract_count
	);
} );
