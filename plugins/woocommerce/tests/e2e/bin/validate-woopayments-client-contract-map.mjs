import { existsSync, readFileSync, statSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

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
const requiredColumns = [
	'case_id',
	'planned_disposition',
	'disposition_state',
	'native_owner_paths',
	'native_lower_layer_context',
	'accepted_disposition',
	'target_path',
	'implementation_owner',
	'closure_state',
];
const requireClosed = process.argv.includes( '--require-closed' );
const showSummary = process.argv.includes( '--summary' );
const binDirectory = dirname( fileURLToPath( import.meta.url ) );
const repositoryRoot = resolve( binDirectory, '../../../../..' );
const ledgerPath = resolve(
	binDirectory,
	'../tests/woopayments-native/client-contract-map.tsv'
);

const lines = readFileSync( ledgerPath, 'utf8' ).trimEnd().split( /\r?\n/ );
const headers = lines.shift().split( '\t' );
const missingColumns = requiredColumns.filter(
	( column ) => ! headers.includes( column )
);

if ( missingColumns.length > 0 ) {
	throw new Error(
		`Missing required columns: ${ missingColumns.join( ', ' ) }`
	);
}

const rows = lines.map( ( line, index ) => {
	const values = line.split( '\t' );

	if ( values.length !== headers.length ) {
		throw new Error(
			`Row ${ index + 2 } has ${ values.length } columns; expected ${ headers.length }`
		);
	}

	return Object.fromEntries(
		headers.map( ( header, valueIndex ) => [
			header,
			values[ valueIndex ],
		] )
	);
} );

if ( rows.length !== 181 ) {
	throw new Error( `Expected 181 cases; found ${ rows.length }` );
}

const splitPaths = ( row, column ) => {
	const paths = row[ column ].split( ';' );

	if ( paths.some( ( filePath ) => filePath.length === 0 ) ) {
		throw new Error( `Empty ${ column } for ${ row.case_id }` );
	}

	return paths;
};

const assertConcreteExistingFiles = ( row, column ) => {
	for ( const filePath of splitPaths( row, column ) ) {
		const absolutePath = resolve( repositoryRoot, filePath );

		if (
			filePath.endsWith( '/' ) ||
			! existsSync( absolutePath ) ||
			! statSync( absolutePath ).isFile()
		) {
			throw new Error(
				`Non-concrete ${ column } for ${ row.case_id }: ${ filePath }`
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
