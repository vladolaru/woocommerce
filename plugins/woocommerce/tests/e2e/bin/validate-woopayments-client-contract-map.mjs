import { createHash } from 'node:crypto';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { dirname, isAbsolute, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const frozenSourceHeaders = [
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
const closureHeaders = [
	'accepted_disposition',
	'target_path',
	'implementation_owner',
	'closure_state',
];
const expectedHeaders = [ ...frozenSourceHeaders, ...closureHeaders ];
const frozenSourceSha256 =
	'aa458aae69abbf938418776a7dd1c0b42e4450e625d71ac61ea341f5da2485e3';
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
const allowedDispositions = Object.values( dispositions );
const allowedDispositionSet = new Set( allowedDispositions );
const nativeTestsRoot =
	'plugins/woocommerce/tests/e2e/tests/woopayments-native';
const approvedFutureTargetDispositions = new Map( [
	[
		`${ nativeTestsRoot }/pilots/shopper-card-payment.spec.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ nativeTestsRoot }/pilots/saved-method-cutover.spec.ts`,
		new Set( [ dispositions.transition ] ),
	],
	[
		`${ nativeTestsRoot }/pilots/merchant-manual-capture.spec.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ nativeTestsRoot }/pilots/merchant-transaction-navigation.spec.ts`,
		new Set( [ dispositions.rewrite ] ),
	],
	[
		`${ nativeTestsRoot }/scenarios/card-payment.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ nativeTestsRoot }/scenarios/saved-method.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ nativeTestsRoot }/scenarios/manual-capture.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ nativeTestsRoot }/scenarios/transaction-navigation.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ nativeTestsRoot }/scenarios/checkout.ts`,
		new Set( [ dispositions.shared, dispositions.unchanged ] ),
	],
	[
		`${ nativeTestsRoot }/scenarios/decline.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ nativeTestsRoot }/scenarios/multi-currency.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ nativeTestsRoot }/scenarios/refund.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ nativeTestsRoot }/shopper/alternative-methods.spec.ts`,
		new Set( [ dispositions.rewrite ] ),
	],
	[
		`${ nativeTestsRoot }/shopper/declines.spec.ts`,
		new Set( [ dispositions.rewrite, dispositions.lowerLayer ] ),
	],
	[
		`${ nativeTestsRoot }/shopper/pay-for-order.spec.ts`,
		new Set( [ dispositions.rewrite ] ),
	],
	[
		`${ nativeTestsRoot }/shopper/saved-methods.spec.ts`,
		new Set( [ dispositions.unchanged, dispositions.lowerLayer ] ),
	],
	[
		`${ nativeTestsRoot }/shopper/multi-currency.spec.ts`,
		new Set( [ dispositions.rewrite, dispositions.lowerLayer ] ),
	],
	[
		`${ nativeTestsRoot }/shopper/theme-compatibility.spec.ts`,
		new Set( [ dispositions.clientOnly, dispositions.lowerLayer ] ),
	],
	[
		`${ nativeTestsRoot }/merchant/orders-refunds.spec.ts`,
		new Set( [ dispositions.lowerLayer ] ),
	],
	[
		`${ nativeTestsRoot }/merchant/settings-methods.spec.ts`,
		new Set( [ dispositions.shared, dispositions.rewrite ] ),
	],
	[
		`${ nativeTestsRoot }/merchant/overview-transactions.spec.ts`,
		new Set( [ dispositions.rewrite ] ),
	],
	[
		`${ nativeTestsRoot }/merchant/payouts-disputes-smoke.spec.ts`,
		new Set( [ dispositions.rewrite ] ),
	],
	[
		`${ nativeTestsRoot }/merchant/role-access.spec.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ nativeTestsRoot }/merchant/onboarding.spec.ts`,
		new Set( [ dispositions.rewrite ] ),
	],
	[
		`${ nativeTestsRoot }/merchant/dispute-lifecycle.spec.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ nativeTestsRoot }/subscriptions/purchase.spec.ts`,
		new Set( [ dispositions.shared, dispositions.unchanged ] ),
	],
	[
		`${ nativeTestsRoot }/subscriptions/renewal.spec.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ nativeTestsRoot }/subscriptions/payment-methods.spec.ts`,
		new Set( [ dispositions.shared ] ),
	],
	[
		`${ nativeTestsRoot }/performance/checkout-readiness.spec.ts`,
		new Set( [ dispositions.lowerLayer ] ),
	],
	[
		`${ nativeTestsRoot }/transitions/historical-money-records.spec.ts`,
		new Set( [ dispositions.transition ] ),
	],
	[
		`${ nativeTestsRoot }/transitions/historical-tokens.spec.ts`,
		new Set( [ dispositions.transition ] ),
	],
	[
		`${ nativeTestsRoot }/transitions/historical-subscriptions.spec.ts`,
		new Set( [ dispositions.transition ] ),
	],
] );
const requireClosed = process.argv.includes( '--require-closed' );
const showSummary = process.argv.includes( '--summary' );
const binDirectory = dirname( fileURLToPath( import.meta.url ) );
const repositoryRoot = resolve( binDirectory, '../../../../..' );
const ledgerPath = resolve(
	binDirectory,
	'../tests/woopayments-native/client-contract-map.tsv'
);

const lines = readFileSync( ledgerPath, 'utf8' )
	.replace( /\r?\n$/, '' )
	.split( /\r?\n/ );
const headers = lines.shift().split( '\t' );

if (
	headers.length !== expectedHeaders.length ||
	headers.some( ( header, index ) => header !== expectedHeaders[ index ] )
) {
	throw new Error(
		`Invalid contract-map schema; expected the exact ${ expectedHeaders.length } ordered columns`
	);
}

const rowValues = lines.map( ( line, index ) => {
	const values = line.split( '\t' );

	if ( values.length !== headers.length ) {
		throw new Error(
			`Row ${ index + 2 } has ${ values.length } columns; expected ${ headers.length }`
		);
	}

	return values;
} );
const rows = rowValues.map( ( values ) =>
	Object.fromEntries(
		headers.map( ( header, valueIndex ) => [ header, values[ valueIndex ] ] )
	)
);

if ( rows.length !== 181 ) {
	throw new Error( `Expected 181 cases; found ${ rows.length }` );
}

const frozenSourceContent =
	[
		frozenSourceHeaders,
		...rowValues.map( ( values ) =>
			values.slice( 0, frozenSourceHeaders.length )
		),
	]
		.map( ( values ) => values.join( '\t' ) )
		.join( '\n' ) + '\n';
const actualFrozenSourceSha256 = createHash( 'sha256' )
	.update( frozenSourceContent )
	.digest( 'hex' );

if ( actualFrozenSourceSha256 !== frozenSourceSha256 ) {
	throw new Error(
		`Frozen source content drifted; expected SHA-256 ${ frozenSourceSha256 }, found ${ actualFrozenSourceSha256 }`
	);
}

const assertRepositoryRelativeFilePath = ( row, column, filePath ) => {
	const pathParts = filePath.split( '/' );

	if (
		filePath !== filePath.trim() ||
		filePath.length === 0 ||
		/^[a-z][a-z\d+.-]*:/i.test( filePath ) ||
		isAbsolute( filePath ) ||
		filePath.includes( '\\' ) ||
		filePath.endsWith( '/' ) ||
		pathParts.includes( '.' ) ||
		pathParts.includes( '..' )
	) {
		throw new Error(
			`Invalid ${ column } for ${ row.case_id }: ${ filePath }`
		);
	}
};

const splitPaths = ( row, column ) => {
	const paths = row[ column ].split( ';' );

	for ( const filePath of paths ) {
		assertRepositoryRelativeFilePath( row, column, filePath );
	}

	return paths;
};

const assertConcreteExistingFiles = ( row, column ) => {
	for ( const filePath of splitPaths( row, column ) ) {
		const absolutePath = resolve( repositoryRoot, filePath );

		if ( ! existsSync( absolutePath ) || ! statSync( absolutePath ).isFile() ) {
			throw new Error(
				`Non-concrete ${ column } for ${ row.case_id }: ${ filePath }`
			);
		}
	}
};

const assertPlannedTargets = ( row ) => {
	const lowerLayerPaths = new Set(
		splitPaths( row, 'native_lower_layer_context' )
	);
	let retainedEvidenceCount = 0;
	let approvedFutureTargetCount = 0;

	for ( const targetPath of splitPaths( row, 'target_path' ) ) {
		const absolutePath = resolve( repositoryRoot, targetPath );
		const allowedTargetDispositions =
			approvedFutureTargetDispositions.get( targetPath );

		if ( allowedTargetDispositions ) {
			if ( ! allowedTargetDispositions.has( row.planned_disposition ) ) {
				throw new Error(
					`target_path is not approved for ${ row.planned_disposition } in ${ row.case_id }: ${ targetPath }`
				);
			}
			if (
				existsSync( absolutePath ) &&
				! statSync( absolutePath ).isFile()
			) {
				throw new Error(
					`Non-concrete target_path for ${ row.case_id }: ${ targetPath }`
				);
			}
			approvedFutureTargetCount++;
			continue;
		}

		if ( ! lowerLayerPaths.has( targetPath ) ) {
			throw new Error(
				`target_path is neither an approved future target nor source-named lower-layer evidence for ${ row.case_id }: ${ targetPath }`
			);
		}

		if ( ! existsSync( absolutePath ) || ! statSync( absolutePath ).isFile() ) {
			throw new Error(
				`Missing retained target_path for ${ row.case_id }: ${ targetPath }`
			);
		}
		retainedEvidenceCount++;
	}

	if (
		row.planned_disposition === dispositions.lowerLayer &&
		( retainedEvidenceCount === 0 || approvedFutureTargetCount === 0 )
	) {
		throw new Error(
			`Lower-layer disposition requires retained evidence and a future E2E smoke target for ${ row.case_id }`
		);
	}

	if (
		row.planned_disposition === dispositions.clientOnly &&
		( retainedEvidenceCount === 0 || approvedFutureTargetCount === 0 )
	) {
		throw new Error(
			`Client-only disposition requires retained evidence and a paired native target for ${ row.case_id }`
		);
	}

	if (
		row.planned_disposition === dispositions.retired &&
		( retainedEvidenceCount === 0 || approvedFutureTargetCount > 0 )
	) {
		throw new Error(
			`Retired disposition requires only retained lower-layer evidence for ${ row.case_id }`
		);
	}

	if (
		row.planned_disposition === dispositions.manual &&
		( retainedEvidenceCount === 0 || approvedFutureTargetCount > 0 )
	) {
		throw new Error(
			`Manual disposition requires existing retained or classified manual evidence for ${ row.case_id }`
		);
	}
};

const caseIds = new Set();

for ( const row of rows ) {
	if ( ! row.case_id ) {
		throw new Error( 'Found a contract without a case_id' );
	}

	if ( caseIds.has( row.case_id ) ) {
		throw new Error( `Duplicate case_id: ${ row.case_id }` );
	}
	caseIds.add( row.case_id );

	if ( ! allowedDispositionSet.has( row.planned_disposition ) ) {
		throw new Error(
			`Invalid planned_disposition for ${ row.case_id }: ${ row.planned_disposition }`
		);
	}

	if (
		row.planned_disposition === dispositions.shared &&
		row.disposition_state !== 'pilot-gated'
	) {
		throw new Error( `Unproven shared disposition for ${ row.case_id }` );
	}

	assertConcreteExistingFiles( row, 'native_owner_paths' );
	assertConcreteExistingFiles( row, 'native_lower_layer_context' );

	if (
		! row.accepted_disposition ||
		! row.target_path ||
		! row.implementation_owner ||
		! row.closure_state
	) {
		throw new Error( `Incomplete closure fields for ${ row.case_id }` );
	}

	assertPlannedTargets( row );

	if (
		row.accepted_disposition !== 'pending' &&
		! allowedDispositionSet.has( row.accepted_disposition )
	) {
		throw new Error(
			`Invalid accepted_disposition for ${ row.case_id }: ${ row.accepted_disposition }`
		);
	}

	if (
		requireClosed &&
		( row.accepted_disposition === 'pending' ||
			row.closure_state === 'planned' ||
			row.implementation_owner === 'owner-decision-required' )
	) {
		throw new Error( `Unclosed case: ${ row.case_id }` );
	}

	if ( row.closure_state === 'implemented' ) {
		assertConcreteExistingFiles( row, 'target_path' );
	}
}

console.log( `Validated ${ rows.length } WooPayments client contracts.` );

if ( showSummary ) {
	const dispositionCounts = new Map(
		allowedDispositions.map( ( disposition ) => [ disposition, 0 ] )
	);

	for ( const row of rows ) {
		dispositionCounts.set(
			row.planned_disposition,
			dispositionCounts.get( row.planned_disposition ) + 1
		);
	}

	for ( const [ disposition, count ] of dispositionCounts ) {
		if ( count > 0 ) {
			console.log( `${ count }\t${ disposition }` );
		}
	}
}
