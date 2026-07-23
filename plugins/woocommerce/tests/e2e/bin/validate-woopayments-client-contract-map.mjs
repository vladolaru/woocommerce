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
const allowedDispositions = [
	'Run unchanged against both runtimes',
	'Extract a shared scenario with thin runtime adapters',
	'Rewrite as a native-specific E2E test preserving the contract',
	'Keep client-only with a paired native contract test elsewhere',
	'Convert to a coexistence, cutover, rollback, or historical backward-compatibility test',
	'Keep manual or external because automation is unsafe or impractical',
	'Cover at a lower layer plus a smaller E2E smoke test',
	'Retire because the test is stale, redundant, or guards only obsolete plugin structure',
];
const allowedDispositionSet = new Set( allowedDispositions );
const nativeTestsRoot =
	'plugins/woocommerce/tests/e2e/tests/woopayments-native';
const approvedFutureTargets = new Set( [
	`${ nativeTestsRoot }/pilots/shopper-card-payment.spec.ts`,
	`${ nativeTestsRoot }/pilots/saved-method-cutover.spec.ts`,
	`${ nativeTestsRoot }/pilots/merchant-manual-capture.spec.ts`,
	`${ nativeTestsRoot }/pilots/merchant-transaction-navigation.spec.ts`,
	`${ nativeTestsRoot }/scenarios/card-payment.ts`,
	`${ nativeTestsRoot }/scenarios/saved-method.ts`,
	`${ nativeTestsRoot }/scenarios/manual-capture.ts`,
	`${ nativeTestsRoot }/scenarios/transaction-navigation.ts`,
	`${ nativeTestsRoot }/scenarios/checkout.ts`,
	`${ nativeTestsRoot }/scenarios/decline.ts`,
	`${ nativeTestsRoot }/scenarios/multi-currency.ts`,
	`${ nativeTestsRoot }/scenarios/refund.ts`,
	`${ nativeTestsRoot }/scenarios/authorization.ts`,
	`${ nativeTestsRoot }/scenarios/merchant-record-navigation.ts`,
	`${ nativeTestsRoot }/shopper/classic-card.spec.ts`,
	`${ nativeTestsRoot }/shopper/blocks-card.spec.ts`,
	`${ nativeTestsRoot }/shopper/alternative-methods.spec.ts`,
	`${ nativeTestsRoot }/shopper/declines.spec.ts`,
	`${ nativeTestsRoot }/shopper/pay-for-order.spec.ts`,
	`${ nativeTestsRoot }/shopper/saved-methods.spec.ts`,
	`${ nativeTestsRoot }/shopper/multi-currency.spec.ts`,
	`${ nativeTestsRoot }/shopper/woopay.spec.ts`,
	`${ nativeTestsRoot }/shopper/theme-compatibility.spec.ts`,
	`${ nativeTestsRoot }/merchant/orders-refunds.spec.ts`,
	`${ nativeTestsRoot }/merchant/authorizations.spec.ts`,
	`${ nativeTestsRoot }/merchant/status-actions.spec.ts`,
	`${ nativeTestsRoot }/merchant/settings-methods.spec.ts`,
	`${ nativeTestsRoot }/merchant/overview-transactions.spec.ts`,
	`${ nativeTestsRoot }/merchant/payouts-disputes-smoke.spec.ts`,
	`${ nativeTestsRoot }/merchant/role-access.spec.ts`,
	`${ nativeTestsRoot }/merchant/onboarding.spec.ts`,
	`${ nativeTestsRoot }/subscriptions/purchase.spec.ts`,
	`${ nativeTestsRoot }/subscriptions/renewal.spec.ts`,
	`${ nativeTestsRoot }/subscriptions/payment-methods.spec.ts`,
	`${ nativeTestsRoot }/merchant/dispute-lifecycle.spec.ts`,
	`${ nativeTestsRoot }/merchant/payout-record.spec.ts`,
	`${ nativeTestsRoot }/merchant/event-recovery.spec.ts`,
	`${ nativeTestsRoot }/performance/checkout-readiness.spec.ts`,
	`${ nativeTestsRoot }/transitions/coexistence-cutover.spec.ts`,
	`${ nativeTestsRoot }/transitions/historical-settings.spec.ts`,
	`${ nativeTestsRoot }/transitions/historical-money-records.spec.ts`,
	`${ nativeTestsRoot }/transitions/historical-tokens.spec.ts`,
	`${ nativeTestsRoot }/transitions/historical-subscriptions.spec.ts`,
	`${ nativeTestsRoot }/transitions/event-ownership.spec.ts`,
	`${ nativeTestsRoot }/transitions/rollback-reactivation.spec.ts`,
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
	const evidencePaths = new Set( [
		...splitPaths( row, 'native_owner_paths' ),
		...splitPaths( row, 'native_lower_layer_context' ),
	] );

	for ( const targetPath of splitPaths( row, 'target_path' ) ) {
		const absolutePath = resolve( repositoryRoot, targetPath );

		if ( approvedFutureTargets.has( targetPath ) ) {
			if (
				existsSync( absolutePath ) &&
				! statSync( absolutePath ).isFile()
			) {
				throw new Error(
					`Non-concrete target_path for ${ row.case_id }: ${ targetPath }`
				);
			}
			continue;
		}

		if ( ! evidencePaths.has( targetPath ) ) {
			throw new Error(
				`Unapproved future target_path for ${ row.case_id }: ${ targetPath }`
			);
		}

		if ( ! existsSync( absolutePath ) || ! statSync( absolutePath ).isFile() ) {
			throw new Error(
				`Missing retained target_path for ${ row.case_id }: ${ targetPath }`
			);
		}
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
		row.planned_disposition ===
			'Extract a shared scenario with thin runtime adapters' &&
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
