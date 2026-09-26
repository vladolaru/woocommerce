import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, realpathSync, statSync } from 'node:fs';
import { isAbsolute, relative, resolve, sep as pathSeparator } from 'node:path';

export const MIGRATION_STATES = new Set( [
	'planned',
	'specified',
	'implemented',
	'verified',
	'closed',
	'deferred',
] );

// States that count as terminal for a saturation check: nothing further is
// expected of the row.
const SATURATED_MIGRATION_STATES = new Set( [ 'closed', 'deferred' ] );

export const NATIVE_SUPPORT_STATES = new Set( [
	'not-assessed',
	'supported',
	'known-gap',
	'blocked-external',
	'blocked-environment',
	'ambiguous-decision',
	'not-applicable-retired',
] );

// Support states that keep a row unmigrated even when its state is closed.
const DEFERRED_SUPPORT_STATES = new Set( [
	'known-gap',
	'blocked-external',
	'blocked-environment',
	'ambiguous-decision',
] );

const FROZEN_SOURCE_HEADERS = [
	'suite_config',
	'case_id',
	'project',
	'source_file',
	'line',
	'title_path',
	'role',
	'feature_family',
	'current_tags',
	'fixtures_helpers',
	'seeded_data_mocks_provider_dependencies',
	'setup_state_mutations',
	'teardown_cleanup',
	'user_observable_outcome',
	'financial_provider_assertions',
	'plugin_specific_coupling',
	'proof_layer',
	'contract',
	'contract_classification',
	'sequential_shared_state_retries_flake',
	'preliminary_disposition',
	'preliminary_disposition_evidence',
	'residual_risk',
	'native_owner_paths',
	'native_lower_layer_context',
	'native_user_surface_runtime_role',
	'required_native_fixtures_runtime_adapters',
	'smallest_honest_adaptation',
	'planned_disposition',
	'planned_disposition_evidence',
	'disposition_state',
	'ci_lane',
];

const EDITABLE_HEADERS = [
	'accepted_disposition',
	'target_path',
	'target_contract',
	'implementation_owner',
	'migration_state',
	'native_support_state',
	'gap_or_decision_reference',
	'evidence_path',
];

const EXPECTED_HEADERS = [ ...FROZEN_SOURCE_HEADERS, ...EDITABLE_HEADERS ];

const EXPECTED_METADATA = {
	schema_version: 1,
	source_repository: 'woocommerce/woocommerce-payments',
	source_branch: 'develop',
	source_commit: '6dda1d4eb101f05c22f60882d67a22f75281ae45',
	frozen_contract_count: 181,
	frozen_source_sha256:
		'aa458aae69abbf938418776a7dd1c0b42e4450e625d71ac61ea341f5da2485e3',
};

const dispositions = {
	unchanged: 'Run unchanged against both runtimes',
	shared: 'Extract a shared scenario with thin runtime adapters',
	rewrite: 'Rewrite as a native-specific E2E test preserving the contract',
	clientOnly: 'Keep client-only with a paired native contract test elsewhere',
	transition:
		'Convert to a coexistence, cutover, rollback, or historical backward-compatibility test',
	manual: 'Keep manual or external because automation is unsafe or impractical',
	lowerLayer: 'Cover at a lower layer plus a smaller E2E smoke test',
	retired:
		'Retire because the test is stale, redundant, or guards only obsolete plugin structure',
};

const ALLOWED_DISPOSITIONS = Object.values( dispositions );
const ALLOWED_DISPOSITION_SET = new Set( ALLOWED_DISPOSITIONS );

const ABSOLUTE_PATH_PATTERN = /^(?:\/|[A-Za-z]:[\\/])/;
const URI_SCHEME_PATTERN = /^[a-z][a-z\d+.-]*:/i;
const REGULAR_GIT_INDEX_MODES = new Set( [ '100644', '100755' ] );

/**
 * The one definition of a legal repository-relative path in the ledger.
 */
const isRepositoryRelativePath = ( value ) => {
	if (
		typeof value !== 'string' ||
		value.length === 0 ||
		value !== value.trim()
	) {
		return false;
	}

	const pathParts = value.split( '/' );

	return ! (
		ABSOLUTE_PATH_PATTERN.test( value ) ||
		URI_SCHEME_PATTERN.test( value ) ||
		value.includes( '\\' ) ||
		value.endsWith( '/' ) ||
		pathParts.includes( '' ) ||
		pathParts.includes( '.' ) ||
		pathParts.includes( '..' )
	);
};

const hasExactValue = ( value ) =>
	typeof value === 'string' &&
	value === value.trim() &&
	value !== '' &&
	value !== 'none' &&
	value !== 'pending';

const assertMetadata = ( metadata ) => {
	for ( const [ key, expectedValue ] of Object.entries(
		EXPECTED_METADATA
	) ) {
		if ( metadata?.[ key ] !== expectedValue ) {
			throw new Error(
				`Invalid contract-map metadata ${ key }; expected ${ expectedValue }`
			);
		}
	}
};

const splitPaths = ( row, column ) => {
	const paths = row[ column ].split( ';' );

	for ( const filePath of paths ) {
		if ( ! isRepositoryRelativePath( filePath ) ) {
			throw new Error(
				`Invalid ${ column } for ${ row.case_id }: ${ filePath }`
			);
		}
	}

	return paths;
};

/**
 * Filesystem checks dominate a validation run: a handful of shared target and
 * evidence files are named by dozens of rows each, and every mention costs an
 * existsSync/realpathSync/statSync triple. This cache holds results for
 * exactly one validateContractMap() call — reset() at the top of it — so a
 * fixture file created or deleted between calls is never served stale.
 *
 * Only successes are cached; a failure aborts the whole run anyway.
 */
let verifiedFilePaths = new Set();

const resetValidationCaches = () => {
	verifiedFilePaths = new Set();
};

const assertConcreteExistingFile = (
	row,
	column,
	filePath,
	repositoryRoot,
	realRepositoryRoot
) => {
	if ( verifiedFilePaths.has( filePath ) ) {
		return;
	}

	const absolutePath = resolve( repositoryRoot, filePath );

	if ( ! existsSync( absolutePath ) ) {
		throw new Error(
			`Non-concrete ${ column } for ${ row.case_id }: ${ filePath }`
		);
	}

	const realFilePath = realpathSync( absolutePath );
	const repositoryRelativeRealPath = relative(
		realRepositoryRoot,
		realFilePath
	);

	if (
		repositoryRelativeRealPath === '..' ||
		repositoryRelativeRealPath.startsWith( `..${ pathSeparator }` ) ||
		isAbsolute( repositoryRelativeRealPath ) ||
		! statSync( realFilePath ).isFile()
	) {
		throw new Error(
			`Non-concrete or out-of-repository ${ column } for ${ row.case_id }: ${ filePath }`
		);
	}

	verifiedFilePaths.add( filePath );
};

const assertTrackedFile = ( row, column, filePath, trackedFilePaths ) => {
	if ( ! trackedFilePaths.has( filePath ) ) {
		throw new Error(
			`Untracked ${ column } for ${ row.case_id }: ${ filePath }`
		);
	}
};

// Every path the ledger requires to be Git-tracked: each row's evidence
// pointer and every closed row's target files.
const collectTrackedPathCandidates = ( rows ) => {
	const candidatePaths = new Set();

	for ( const row of rows ) {
		if (
			row.evidence_path !== 'none' &&
			isRepositoryRelativePath( row.evidence_path )
		) {
			candidatePaths.add( row.evidence_path );
		}

		if (
			row.migration_state === 'closed' &&
			typeof row.target_path === 'string'
		) {
			for ( const targetPath of row.target_path.split( ';' ) ) {
				if ( isRepositoryRelativePath( targetPath ) ) {
					candidatePaths.add( targetPath );
				}
			}
		}
	}

	return [ ...candidatePaths ];
};

// One git call resolves every candidate at once. A path is tracked when the
// index holds exactly one clean stage-0 regular-file entry for it; symlinks,
// merge conflicts, and untracked working-tree files all fail the check.
const loadTrackedFilePaths = ( repositoryRoot, candidatePaths ) => {
	const trackedFilePaths = new Set();

	if ( candidatePaths.length === 0 ) {
		return trackedFilePaths;
	}

	const output = execFileSync(
		'git',
		[
			'-C',
			repositoryRoot,
			'ls-files',
			'--stage',
			'-z',
			'--',
			...candidatePaths.map(
				( candidatePath ) => `:(literal)${ candidatePath }`
			),
		],
		{ encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 }
	);
	const seenPaths = new Set();

	for ( const entry of output.split( '\0' ) ) {
		if ( entry === '' ) {
			continue;
		}

		const [ indexPart, entryPath ] = entry.split( '\t' );
		const [ indexMode, , indexStage ] = indexPart.split( ' ' );

		if ( seenPaths.has( entryPath ) ) {
			trackedFilePaths.delete( entryPath );
			continue;
		}
		seenPaths.add( entryPath );

		if (
			REGULAR_GIT_INDEX_MODES.has( indexMode ) &&
			indexStage === '0'
		) {
			trackedFilePaths.add( entryPath );
		}
	}

	return trackedFilePaths;
};

const createStateCounts = ( states ) =>
	Object.fromEntries( [ ...states ].map( ( state ) => [ state, 0 ] ) );

const calculateFrozenSourceSha256 = ( rows ) => {
	const frozenSourceContent =
		[
			FROZEN_SOURCE_HEADERS,
			...rows.map( ( row ) =>
				FROZEN_SOURCE_HEADERS.map( ( header ) => row[ header ] )
			),
		]
			.map( ( values ) => values.join( '\t' ) )
			.join( '\n' ) + '\n';

	return createHash( 'sha256' ).update( frozenSourceContent ).digest( 'hex' );
};

// evidence_path is an optional pointer: 'none', or a single tracked JSON file
// that exists in the repository. Its content is not validated.
const assertEvidencePath = (
	row,
	repositoryRoot,
	realRepositoryRoot,
	trackedFilePaths
) => {
	if ( row.evidence_path === 'none' ) {
		return;
	}

	if (
		row.evidence_path.includes( ';' ) ||
		! row.evidence_path.endsWith( '.json' ) ||
		! isRepositoryRelativePath( row.evidence_path )
	) {
		throw new Error(
			`Evidence must be none or one tracked JSON file for ${ row.case_id }: ${ row.evidence_path }`
		);
	}

	assertConcreteExistingFile(
		row,
		'evidence_path',
		row.evidence_path,
		repositoryRoot,
		realRepositoryRoot
	);
	assertTrackedFile(
		row,
		'evidence_path',
		row.evidence_path,
		trackedFilePaths
	);
};

const assertRowState = (
	row,
	repositoryRoot,
	realRepositoryRoot,
	trackedFilePaths
) => {
	if ( ! MIGRATION_STATES.has( row.migration_state ) ) {
		throw new Error(
			`Invalid migration_state for ${ row.case_id }: ${ row.migration_state }`
		);
	}

	if ( ! NATIVE_SUPPORT_STATES.has( row.native_support_state ) ) {
		throw new Error(
			`Invalid native_support_state for ${ row.case_id }: ${ row.native_support_state }`
		);
	}

	if (
		row.accepted_disposition !== 'pending' &&
		! ALLOWED_DISPOSITION_SET.has( row.accepted_disposition )
	) {
		throw new Error(
			`Invalid accepted_disposition for ${ row.case_id }: ${ row.accepted_disposition }`
		);
	}

	assertEvidencePath(
		row,
		repositoryRoot,
		realRepositoryRoot,
		trackedFilePaths
	);

	if (
		row.migration_state === 'deferred' &&
		! hasExactValue( row.gap_or_decision_reference )
	) {
		throw new Error(
			`Deferred contract requires a gap or decision reference: ${ row.case_id }`
		);
	}

	const targetPaths = splitPaths( row, 'target_path' );

	if ( row.migration_state !== 'closed' ) {
		return;
	}

	const supportedClosure = row.native_support_state === 'supported';
	const retiredClosure =
		row.accepted_disposition === dispositions.retired &&
		row.native_support_state === 'not-applicable-retired' &&
		hasExactValue( row.target_contract );

	if ( ! supportedClosure && ! retiredClosure ) {
		throw new Error(
			`Closed contract must be supported or retired onto a retained contract: ${ row.case_id }`
		);
	}

	for ( const targetPath of targetPaths ) {
		assertConcreteExistingFile(
			row,
			'target_path',
			targetPath,
			repositoryRoot,
			realRepositoryRoot
		);
		assertTrackedFile( row, 'target_path', targetPath, trackedFilePaths );
	}
};

const hasExactHeaders = ( actualHeaders, expectedHeaders ) =>
	actualHeaders.length === expectedHeaders.length &&
	actualHeaders.every(
		( header, index ) => header === expectedHeaders[ index ]
	);

export const parseContractMap = ( content ) => {
	if ( typeof content !== 'string' || content.length === 0 ) {
		throw new Error( 'Contract map must be non-empty TSV content' );
	}

	const lines = content.replace( /\r?\n$/, '' ).split( /\r?\n/ );
	const headers = lines.shift().split( '\t' );

	if ( ! hasExactHeaders( headers, EXPECTED_HEADERS ) ) {
		throw new Error(
			`Invalid contract-map schema; expected the exact ${ EXPECTED_HEADERS.length } ordered columns`
		);
	}

	const rows = lines.map( ( line, index ) => {
		const values = line.split( '\t' );

		if ( values.length !== headers.length ) {
			throw new Error(
				`Row ${ index + 2 } has ${ values.length } columns; expected ${
					headers.length
				}`
			);
		}

		return Object.fromEntries(
			headers.map( ( header, valueIndex ) => [
				header,
				values[ valueIndex ],
			] )
		);
	} );

	return { headers, rows };
};

export const validateContractMap = (
	contractMap,
	{
		metadata,
		repositoryRoot,
		requireSaturated = false,
		requireMigrated = false,
	} = {}
) => {
	resetValidationCaches();
	assertMetadata( metadata );

	if (
		! contractMap ||
		! Array.isArray( contractMap.headers ) ||
		! Array.isArray( contractMap.rows )
	) {
		throw new Error( 'Invalid parsed contract map' );
	}

	if ( ! hasExactHeaders( contractMap.headers, EXPECTED_HEADERS ) ) {
		throw new Error(
			`Invalid contract-map schema; expected the exact ${ EXPECTED_HEADERS.length } ordered columns`
		);
	}

	if ( contractMap.rows.length !== metadata.frozen_contract_count ) {
		throw new Error(
			`Expected ${ metadata.frozen_contract_count } cases; found ${ contractMap.rows.length }`
		);
	}

	const caseIds = new Set();

	for ( const row of contractMap.rows ) {
		if ( ! row.case_id ) {
			throw new Error( 'Found a contract without a case_id' );
		}

		if ( caseIds.has( row.case_id ) ) {
			throw new Error( `Duplicate case_id: ${ row.case_id }` );
		}
		caseIds.add( row.case_id );
	}

	const actualFrozenSourceSha256 = calculateFrozenSourceSha256(
		contractMap.rows
	);

	if ( actualFrozenSourceSha256 !== metadata.frozen_source_sha256 ) {
		throw new Error(
			`Frozen source content drifted; expected SHA-256 ${ metadata.frozen_source_sha256 }, found ${ actualFrozenSourceSha256 }`
		);
	}

	const realRepositoryRoot = realpathSync( repositoryRoot );
	const trackedFilePaths = loadTrackedFilePaths(
		repositoryRoot,
		collectTrackedPathCandidates( contractMap.rows )
	);
	const dispositionCounts = Object.fromEntries(
		ALLOWED_DISPOSITIONS.map( ( disposition ) => [ disposition, 0 ] )
	);
	const migrationStateCounts = createStateCounts( MIGRATION_STATES );
	const nativeSupportStateCounts = createStateCounts( NATIVE_SUPPORT_STATES );

	for ( const row of contractMap.rows ) {
		for ( const column of EDITABLE_HEADERS ) {
			if ( ! row[ column ] ) {
				throw new Error(
					`Incomplete closure fields for ${ row.case_id }`
				);
			}
		}

		assertRowState(
			row,
			repositoryRoot,
			realRepositoryRoot,
			trackedFilePaths
		);

		if (
			requireSaturated &&
			! SATURATED_MIGRATION_STATES.has( row.migration_state )
		) {
			throw new Error( `Unsaturated contract: ${ row.case_id }` );
		}

		if (
			requireMigrated &&
			( row.migration_state !== 'closed' ||
				row.native_support_state === 'known-gap' ||
				DEFERRED_SUPPORT_STATES.has( row.native_support_state ) )
		) {
			throw new Error( `Unmigrated contract: ${ row.case_id }` );
		}

		dispositionCounts[ row.planned_disposition ]++;
		migrationStateCounts[ row.migration_state ]++;
		nativeSupportStateCounts[ row.native_support_state ]++;
	}

	return {
		rowCount: contractMap.rows.length,
		frozenSourceSha256: actualFrozenSourceSha256,
		dispositionCounts,
		migrationStateCounts,
		nativeSupportStateCounts,
	};
};
