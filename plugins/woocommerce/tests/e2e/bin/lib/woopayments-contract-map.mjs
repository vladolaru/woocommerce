import { createHash } from 'node:crypto';
import { existsSync, readFileSync, realpathSync, statSync } from 'node:fs';
import { isAbsolute, relative, resolve, sep as pathSeparator } from 'node:path';

import {
	isRepositoryRelativePath,
	validateMigrationEvidence,
} from './woopayments-migration-evidence.mjs';

export const MIGRATION_STATES = new Set( [
	'planned',
	'specified',
	'implemented',
	'verified',
	'closed',
	'deferred',
] );

// States in which the migration work itself has been carried out, so the row
// must name a real target file and a real owner.
export const EXECUTED_MIGRATION_STATES = new Set( [
	'implemented',
	'verified',
	'closed',
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

const DEFERRED_SUPPORT_STATES = new Set( [
	'known-gap',
	'blocked-external',
	'blocked-environment',
	'ambiguous-decision',
] );

const ALLOWED_MIGRATION_TRANSITIONS = new Map( [
	[ 'planned', new Set( [ 'planned', 'specified', 'deferred' ] ) ],
	[
		'specified',
		new Set( [
			'specified',
			'implemented',
			'verified',
			'closed',
			'deferred',
		] ),
	],
	[
		'implemented',
		new Set( [ 'implemented', 'verified', 'closed', 'deferred' ] ),
	],
	[ 'verified', new Set( [ 'verified', 'closed', 'deferred' ] ) ],
	[ 'closed', new Set( [ 'closed', 'implemented' ] ) ],
	[ 'deferred', new Set( [ 'deferred' ] ) ],
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
const LEGACY_EDITABLE_HEADERS = [
	'accepted_disposition',
	'target_path',
	'implementation_owner',
	'closure_state',
];
const LEGACY_HEADERS = [ ...FROZEN_SOURCE_HEADERS, ...LEGACY_EDITABLE_HEADERS ];

// Columns the legacy schema never carried; reading a legacy ledger backfills
// them with the same values a freshly planned row would hold.
const LEGACY_SCHEMA_DEFAULTS = {
	target_contract: 'pending',
	native_support_state: 'not-assessed',
	gap_or_decision_reference: 'none',
	evidence_path: 'none',
};
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
const getEffectiveDisposition = ( row ) =>
	row.accepted_disposition === 'pending'
		? row.planned_disposition
		: row.accepted_disposition;
const FUTURE_ONLY_DISPOSITIONS = new Set( [
	dispositions.shared,
	dispositions.rewrite,
	dispositions.transition,
] );
const NATIVE_TESTS_ROOT =
	'plugins/woocommerce/tests/e2e/tests/woopayments-native';
const CORRECTED_REFUND_LOWER_LAYER_TARGET =
	'plugins/woocommerce/tests/php/includes/class-wc-ajax-test.php';
const CORRECTED_LOWER_LAYER_TARGETS_BY_CASE_ID = new Map(
	[
		'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-refund-failures.spec.ts:100::Order › Refund Failure › Invalid quantity › should fail refund attempt when quantity is greater than maximum',
		'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-refund-failures.spec.ts:100::Order › Refund Failure › Invalid quantity › should fail refund attempt when quantity is negative',
		'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-refund-failures.spec.ts:100::Order › Refund Failure › Invalid refund amount in line item › should fail refund attempt when refund amount in line item is greater than maximum',
		'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-refund-failures.spec.ts:100::Order › Refund Failure › Invalid refund amount in line item › should fail refund attempt when refund amount in line item is negative',
		'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-refund-failures.spec.ts:100::Order › Refund Failure › Invalid total refund amount › should fail refund attempt when total refund amount is greater than maximum',
		'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-refund-failures.spec.ts:100::Order › Refund Failure › Invalid total refund amount › should fail refund attempt when total refund amount is negative',
	].map( ( caseId ) => [ caseId, CORRECTED_REFUND_LOWER_LAYER_TARGET ] )
);
const APPROVED_FUTURE_TARGET_DISPOSITIONS = new Map( [
	[
		`${ NATIVE_TESTS_ROOT }/pilots/shopper-card-payment.spec.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/pilots/saved-method-cutover.spec.ts`,
		new Set( [ dispositions.transition ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/pilots/merchant-manual-capture.spec.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/pilots/merchant-transaction-navigation.spec.ts`,
		new Set( [ dispositions.rewrite ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/scenarios/card-payment.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/scenarios/saved-method.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/scenarios/manual-capture.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/scenarios/transaction-navigation.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/scenarios/checkout.ts`,
		new Set( [ dispositions.shared, dispositions.unchanged ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/scenarios/decline.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/scenarios/multi-currency.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/scenarios/refund.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/shopper/alternative-methods.spec.ts`,
		new Set( [ dispositions.rewrite ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/shopper/declines.spec.ts`,
		new Set( [ dispositions.rewrite, dispositions.lowerLayer ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/shopper/pay-for-order.spec.ts`,
		new Set( [ dispositions.rewrite ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/shopper/saved-methods.spec.ts`,
		new Set( [ dispositions.unchanged, dispositions.lowerLayer ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/shopper/multi-currency.spec.ts`,
		new Set( [ dispositions.rewrite, dispositions.lowerLayer ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/shopper/theme-compatibility.spec.ts`,
		new Set( [ dispositions.clientOnly, dispositions.lowerLayer ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/merchant/orders-refunds.spec.ts`,
		new Set( [ dispositions.lowerLayer ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/merchant/settings-methods.spec.ts`,
		new Set( [ dispositions.shared, dispositions.rewrite ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/merchant/overview-transactions.spec.ts`,
		new Set( [ dispositions.rewrite ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/merchant/payouts-disputes-smoke.spec.ts`,
		new Set( [ dispositions.rewrite ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/merchant/role-access.spec.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/merchant/onboarding.spec.ts`,
		new Set( [ dispositions.rewrite ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/merchant/dispute-lifecycle.spec.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/subscriptions/purchase.spec.ts`,
		new Set( [ dispositions.shared, dispositions.unchanged ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/subscriptions/renewal.spec.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/subscriptions/payment-methods.spec.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/performance/checkout-readiness.spec.ts`,
		new Set( [ dispositions.lowerLayer ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/transitions/historical-money-records.spec.ts`,
		new Set( [ dispositions.transition ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/transitions/historical-tokens.spec.ts`,
		new Set( [ dispositions.transition ] ),
	],
	[
		`${ NATIVE_TESTS_ROOT }/transitions/historical-subscriptions.spec.ts`,
		new Set( [ dispositions.transition ] ),
	],
] );

const hasExactValue = ( value ) =>
	typeof value === 'string' &&
	value === value.trim() &&
	value !== '' &&
	value !== 'none' &&
	value !== 'pending';

const hasDecisionReference = ( row ) =>
	hasExactValue( row.gap_or_decision_reference );

const hasHumanApproval = ( row ) => {
	const approvalPrefix = 'human-approved:';
	const decisionReference = row.gap_or_decision_reference;

	if (
		typeof decisionReference !== 'string' ||
		! decisionReference.startsWith( approvalPrefix )
	) {
		return false;
	}

	const approvalReference = decisionReference.slice( approvalPrefix.length );

	return (
		hasExactValue( approvalReference ) &&
		approvalReference !== 'owner-decision-required'
	);
};

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
 * Filesystem checks dominate a validation run: a handful of shared owner and
 * lower-layer files are named by dozens of rows each, and every mention costs
 * an existsSync/realpathSync/statSync triple. These caches hold results for
 * exactly one validateContractMap() call — reset() at the top of it — so a
 * fixture file created or deleted between calls is never served stale.
 *
 * Only successes are cached; a failure aborts the whole run anyway.
 */
let verifiedFilePaths = new Set();
let parsedEvidenceByPath = new Map();

const resetValidationCaches = () => {
	verifiedFilePaths = new Set();
	parsedEvidenceByPath = new Map();
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

const assertConcreteExistingFiles = (
	row,
	column,
	repositoryRoot,
	realRepositoryRoot
) => {
	for ( const filePath of splitPaths( row, column ) ) {
		assertConcreteExistingFile(
			row,
			column,
			filePath,
			repositoryRoot,
			realRepositoryRoot
		);
	}
};

const assertCompatibleTargets = ( row, repositoryRoot, realRepositoryRoot ) => {
	const effectiveDisposition = getEffectiveDisposition( row );
	const lowerLayerPaths = new Set(
		splitPaths( row, 'native_lower_layer_context' )
	);
	const correctedLowerLayerTarget =
		CORRECTED_LOWER_LAYER_TARGETS_BY_CASE_ID.get( row.case_id );
	let retainedEvidenceCount = 0;
	let approvedFutureTargetCount = 0;

	for ( const targetPath of splitPaths( row, 'target_path' ) ) {
		const absolutePath = resolve( repositoryRoot, targetPath );
		const allowedTargetDispositions =
			APPROVED_FUTURE_TARGET_DISPOSITIONS.get( targetPath );

		if ( allowedTargetDispositions ) {
			if ( ! allowedTargetDispositions.has( effectiveDisposition ) ) {
				throw new Error(
					`target_path is not approved for ${ effectiveDisposition } in ${ row.case_id }: ${ targetPath }`
				);
			}
			if ( existsSync( absolutePath ) ) {
				assertConcreteExistingFile(
					row,
					'target_path',
					targetPath,
					repositoryRoot,
					realRepositoryRoot
				);
			}
			approvedFutureTargetCount++;
			continue;
		}

		if (
			! lowerLayerPaths.has( targetPath ) &&
			targetPath !== correctedLowerLayerTarget
		) {
			throw new Error(
				`target_path is neither an approved future target nor approved lower-layer evidence for ${ row.case_id }: ${ targetPath }`
			);
		}

		assertConcreteExistingFile(
			row,
			'target_path',
			targetPath,
			repositoryRoot,
			realRepositoryRoot
		);
		retainedEvidenceCount++;
	}

	if (
		FUTURE_ONLY_DISPOSITIONS.has( effectiveDisposition ) &&
		( approvedFutureTargetCount === 0 || retainedEvidenceCount > 0 )
	) {
		throw new Error(
			`${ effectiveDisposition } requires only approved future targets for ${ row.case_id }`
		);
	}

	if (
		effectiveDisposition === dispositions.lowerLayer &&
		( retainedEvidenceCount === 0 || approvedFutureTargetCount === 0 )
	) {
		throw new Error(
			`Lower-layer disposition requires retained evidence and a future E2E smoke target for ${ row.case_id }`
		);
	}

	if (
		effectiveDisposition === dispositions.clientOnly &&
		( retainedEvidenceCount === 0 || approvedFutureTargetCount === 0 )
	) {
		throw new Error(
			`Client-only disposition requires retained evidence and a paired native target for ${ row.case_id }`
		);
	}

	if (
		effectiveDisposition === dispositions.retired &&
		( retainedEvidenceCount === 0 || approvedFutureTargetCount > 0 )
	) {
		throw new Error(
			`Retired disposition requires only retained lower-layer evidence for ${ row.case_id }`
		);
	}

	if (
		effectiveDisposition === dispositions.manual &&
		( retainedEvidenceCount === 0 || approvedFutureTargetCount > 0 )
	) {
		throw new Error(
			`Manual disposition requires existing retained or classified manual evidence for ${ row.case_id }`
		);
	}
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

const assertExactTargetAndOwner = ( row ) => {
	if (
		! hasExactValue( row.target_contract ) ||
		! hasExactValue( row.implementation_owner ) ||
		row.implementation_owner === 'owner-decision-required'
	) {
		throw new Error(
			`Specified contract requires an exact target and owner: ${ row.case_id }`
		);
	}
};

const assertEvidence = ( row, repositoryRoot, realRepositoryRoot ) => {
	if ( ! hasExactValue( row.evidence_path ) ) {
		return false;
	}

	assertConcreteExistingFiles(
		row,
		'evidence_path',
		repositoryRoot,
		realRepositoryRoot
	);
	return true;
};

const assertMigrationEvidence = (
	row,
	metadata,
	repositoryRoot,
	realRepositoryRoot
) => {
	if ( ! hasExactValue( row.evidence_path ) ) {
		return;
	}

	if ( row.evidence_path.includes( ';' ) ) {
		throw new Error(
			`Migration evidence must use one JSON file for ${ row.case_id }`
		);
	}

	assertConcreteExistingFiles(
		row,
		'evidence_path',
		repositoryRoot,
		realRepositoryRoot
	);

	let evidence = parsedEvidenceByPath.get( row.evidence_path );

	if ( evidence === undefined ) {
		try {
			evidence = JSON.parse(
				readFileSync(
					resolve( repositoryRoot, row.evidence_path ),
					'utf8'
				)
			);
		} catch ( error ) {
			if ( error instanceof SyntaxError ) {
				throw new Error(
					`Invalid migration evidence JSON for ${ row.case_id }`,
					{ cause: error }
				);
			}
			throw error;
		}
		parsedEvidenceByPath.set( row.evidence_path, evidence );
	}

	validateMigrationEvidence( evidence, {
		row,
		metadata,
		repositoryRoot,
	} );
};

const assertRowState = ( row, repositoryRoot, realRepositoryRoot ) => {
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

	if (
		row.accepted_disposition !== 'pending' &&
		row.accepted_disposition !== row.planned_disposition &&
		! hasHumanApproval( row )
	) {
		throw new Error(
			`Disposition change requires an explicit decision reference for ${ row.case_id }`
		);
	}

	if ( row.migration_state === 'planned' ) {
		if (
			row.accepted_disposition !== 'pending' ||
			row.target_contract !== 'pending' ||
			row.implementation_owner !== 'owner-decision-required' ||
			row.native_support_state !== 'not-assessed' ||
			row.gap_or_decision_reference !== 'none' ||
			row.evidence_path !== 'none'
		) {
			throw new Error(
				`Planned contract has advanced closure fields: ${ row.case_id }`
			);
		}
		return;
	}

	if ( row.migration_state === 'specified' ) {
		assertExactTargetAndOwner( row );
	}

	if ( row.migration_state === 'deferred' ) {
		if (
			! DEFERRED_SUPPORT_STATES.has( row.native_support_state ) ||
			! hasDecisionReference( row ) ||
			! assertEvidence( row, repositoryRoot, realRepositoryRoot )
		) {
			throw new Error(
				`Deferred contract requires an expected gap state, reference, and evidence: ${ row.case_id }`
			);
		}
		return;
	}

	if ( row.native_support_state === 'known-gap' ) {
		if (
			row.migration_state === 'closed' ||
			! hasDecisionReference( row )
		) {
			if ( row.migration_state === 'closed' ) {
				throw new Error(
					`Closed contract must be supported and reference evidence: ${ row.case_id }`
				);
			}
			throw new Error(
				`Known gap requires a reference: ${ row.case_id }`
			);
		}
	}

	if ( EXECUTED_MIGRATION_STATES.has( row.migration_state ) ) {
		assertExactTargetAndOwner( row );
	}

	if (
		row.migration_state === 'implemented' ||
		row.migration_state === 'verified'
	) {
		if ( ! assertEvidence( row, repositoryRoot, realRepositoryRoot ) ) {
			const state =
				row.migration_state === 'implemented'
					? 'Implemented'
					: 'Verified';
			throw new Error(
				`${ state } contract requires target evidence: ${ row.case_id }`
			);
		}
	}

	if ( EXECUTED_MIGRATION_STATES.has( row.migration_state ) ) {
		assertConcreteExistingFiles(
			row,
			'target_path',
			repositoryRoot,
			realRepositoryRoot
		);
	}

	if ( row.migration_state === 'closed' ) {
		const retiredClosure =
			row.accepted_disposition === dispositions.retired &&
			row.native_support_state === 'not-applicable-retired' &&
			hasHumanApproval( row );
		const supportedClosure =
			row.accepted_disposition !== 'pending' &&
			row.accepted_disposition !== dispositions.retired &&
			row.native_support_state === 'supported';
		let evidenceIsValid = false;

		try {
			evidenceIsValid = assertEvidence(
				row,
				repositoryRoot,
				realRepositoryRoot
			);
		} catch {
			evidenceIsValid = false;
		}

		if ( ( ! supportedClosure && ! retiredClosure ) || ! evidenceIsValid ) {
			throw new Error(
				`Closed contract must be supported and reference evidence: ${ row.case_id }`
			);
		}
	}
};

const hasExactHeaders = ( actualHeaders, expectedHeaders ) =>
	actualHeaders.length === expectedHeaders.length &&
	actualHeaders.every(
		( header, index ) => header === expectedHeaders[ index ]
	);

export const parseContractMap = (
	content,
	{ allowLegacySchema = false } = {}
) => {
	if ( typeof content !== 'string' || content.length === 0 ) {
		throw new Error( 'Contract map must be non-empty TSV content' );
	}

	const lines = content.replace( /\r?\n$/, '' ).split( /\r?\n/ );
	const headers = lines.shift().split( '\t' );
	const usesCurrentSchema = hasExactHeaders( headers, EXPECTED_HEADERS );
	const usesLegacySchema =
		allowLegacySchema && hasExactHeaders( headers, LEGACY_HEADERS );

	if ( ! usesCurrentSchema && ! usesLegacySchema ) {
		throw new Error(
			`Invalid contract-map schema; expected the exact ${ EXPECTED_HEADERS.length } ordered columns`
		);
	}

	const parsedRows = lines.map( ( line, index ) => {
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

	if ( usesCurrentSchema ) {
		return { headers, rows: parsedRows };
	}

	const rows = parsedRows.map( ( legacyRow ) =>
		Object.fromEntries(
			EXPECTED_HEADERS.map( ( header ) => [
				header,
				header === 'migration_state'
					? legacyRow.closure_state
					: LEGACY_SCHEMA_DEFAULTS[ header ] ?? legacyRow[ header ],
			] )
		)
	);

	return { headers: [ ...EXPECTED_HEADERS ], rows };
};

export const validateStateTransition = ( previous, next ) => {
	if ( ! ALLOWED_MIGRATION_TRANSITIONS.get( previous )?.has( next ) ) {
		throw new Error(
			`Illegal migration transition: ${ previous } -> ${ next }`
		);
	}
};

export const validateDispositionTransition = ( previousRow, nextRow ) => {
	const previousDisposition = previousRow.accepted_disposition;
	const nextDisposition = nextRow.accepted_disposition;

	if ( previousDisposition === nextDisposition ) {
		return;
	}

	if ( previousDisposition === 'pending' ) {
		if (
			nextDisposition === 'pending' ||
			nextDisposition === nextRow.planned_disposition ||
			hasHumanApproval( nextRow )
		) {
			return;
		}
	} else if (
		nextDisposition !== 'pending' &&
		hasHumanApproval( nextRow ) &&
		nextRow.gap_or_decision_reference !==
			previousRow.gap_or_decision_reference
	) {
		return;
	}

	throw new Error(
		`Disposition transition requires fresh human approval: ${ nextRow.case_id }`
	);
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
	const dispositionCounts = Object.fromEntries(
		ALLOWED_DISPOSITIONS.map( ( disposition ) => [ disposition, 0 ] )
	);
	const migrationStateCounts = createStateCounts( MIGRATION_STATES );
	const nativeSupportStateCounts = createStateCounts( NATIVE_SUPPORT_STATES );

	for ( const row of contractMap.rows ) {
		if ( ! ALLOWED_DISPOSITION_SET.has( row.planned_disposition ) ) {
			throw new Error(
				`Invalid planned_disposition for ${ row.case_id }: ${ row.planned_disposition }`
			);
		}

		if (
			getEffectiveDisposition( row ) === dispositions.shared &&
			row.disposition_state !== 'pilot-gated'
		) {
			throw new Error(
				`Unproven shared disposition for ${ row.case_id }`
			);
		}

		for ( const column of EDITABLE_HEADERS ) {
			if ( ! row[ column ] ) {
				throw new Error(
					`Incomplete closure fields for ${ row.case_id }`
				);
			}
		}

		assertConcreteExistingFiles(
			row,
			'native_owner_paths',
			repositoryRoot,
			realRepositoryRoot
		);
		assertConcreteExistingFiles(
			row,
			'native_lower_layer_context',
			repositoryRoot,
			realRepositoryRoot
		);

		assertRowState( row, repositoryRoot, realRepositoryRoot );
		assertCompatibleTargets( row, repositoryRoot, realRepositoryRoot );
		assertMigrationEvidence(
			row,
			metadata,
			repositoryRoot,
			realRepositoryRoot
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
