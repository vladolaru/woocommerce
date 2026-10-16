import { readFileSync, realpathSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
	parseContractMap,
	validateContractMap,
} from './lib/woopayments-contract-map.mjs';

const binDirectory = dirname( fileURLToPath( import.meta.url ) );
const repositoryRoot = realpathSync(
	resolve( binDirectory, '../../../../..' )
);
const ledgerRepositoryPath =
	'plugins/woocommerce/tests/e2e/tests/woopayments-native/client-contract-map.tsv';
const metadataRepositoryPath =
	'plugins/woocommerce/tests/e2e/tests/woopayments-native/client-contract-map.meta.json';
// A fidelity_claim slug names the corresponding section in sibling FIDELITY-CLAIMS.md; the partition
// supplies only the contract-to-family assignment and its Condition 2 verdict.
const fidelityPartitionRepositoryPath =
	'plugins/woocommerce/tests/e2e/tests/woopayments-native/fidelity-partition.tsv';
const fidelityPartitionHeaders = [
	'case_id',
	'treatment',
	'fidelity_family',
	'condition_2_verdict',
];

// Evidence packets are optional pointers whose content carries no schema, so a
// citation is collected best-effort: a packet that is not JSON, or that has no
// closures array, simply cites nothing.
const collectFidelityClaimCitations = ( contractMap, activeRepositoryRoot ) => {
	const readEvidencePaths = new Set();
	const citations = [];

	for ( const row of contractMap.rows ) {
		if (
			row.evidence_path === 'none' ||
			readEvidencePaths.has( row.evidence_path )
		) {
			continue;
		}
		readEvidencePaths.add( row.evidence_path );

		let evidence;

		try {
			evidence = JSON.parse(
				readFileSync(
					resolve( activeRepositoryRoot, row.evidence_path ),
					'utf8'
				)
			);
		} catch {
			continue;
		}

		if ( ! Array.isArray( evidence?.closures ) ) {
			continue;
		}

		for ( const closure of evidence.closures ) {
			if (
				closure !== null &&
				typeof closure === 'object' &&
				! Array.isArray( closure ) &&
				Object.hasOwn( closure, 'fidelity_claim' )
			) {
				citations.push( {
					caseId: closure.contract_id,
					fidelityClaim: closure.fidelity_claim,
				} );
			}
		}
	}

	return citations;
};

const parseFidelityPartition = ( content ) => {
	const [ headerLine, ...rowLines ] = content.split( /\r?\n/ );
	const headers = headerLine.split( '\t' );
	const missingHeaders = fidelityPartitionHeaders.filter(
		( header ) => ! headers.includes( header )
	);

	if ( missingHeaders.length > 0 ) {
		throw new Error(
			`Invalid fidelity partition; missing required header(s): ${ missingHeaders.join(
				', '
			) }`
		);
	}

	return rowLines
		.filter( ( line ) => line !== '' )
		.map( ( line ) => {
			const values = line.split( '\t' );

			return Object.fromEntries(
				fidelityPartitionHeaders.map( ( header ) => [
					header,
					values[ headers.indexOf( header ) ],
				] )
			);
		} );
};

const validateFidelityClaimCitations = (
	contractMap,
	activeRepositoryRoot,
	readFidelityPartition
) => {
	const citations = collectFidelityClaimCitations(
		contractMap,
		activeRepositoryRoot
	);

	if ( citations.length === 0 ) {
		return;
	}

	const partitionRows = parseFidelityPartition(
		readFidelityPartition( fidelityPartitionRepositoryPath )
	);

	for ( const { caseId, fidelityClaim } of citations ) {
		const matchingRows = partitionRows.filter(
			( row ) => row.case_id === caseId
		);

		if ( matchingRows.length !== 1 ) {
			throw new Error(
				`Invalid fidelity claim citation for ${ caseId }; fidelity partition must contain exactly one matching case_id; found ${ matchingRows.length }`
			);
		}

		const [ partitionRow ] = matchingRows;

		if ( partitionRow.treatment !== 'fidelity' ) {
			throw new Error(
				`Invalid fidelity claim citation for ${ caseId }; requires treatment fidelity; found ${ partitionRow.treatment }`
			);
		}
		if ( partitionRow.fidelity_family !== fidelityClaim ) {
			throw new Error(
				`Invalid fidelity claim citation for ${ caseId }; fidelity_family is ${ partitionRow.fidelity_family }, not cited ${ fidelityClaim }`
			);
		}
		if ( partitionRow.condition_2_verdict !== 'dischargeable' ) {
			throw new Error(
				`Invalid fidelity claim citation for ${ caseId }; requires condition_2_verdict dischargeable; found ${ partitionRow.condition_2_verdict }`
			);
		}
	}
};

const parseArguments = ( cliArguments ) => {
	const options = {
		requireMigrated: false,
		requireSaturated: false,
		showSummary: false,
	};
	const unknownArguments = [];

	for ( const argument of cliArguments ) {
		if ( argument === '--' ) {
			continue;
		}
		if ( argument === '--summary' ) {
			options.showSummary = true;
			continue;
		}
		if ( argument === '--require-saturated' ) {
			options.requireSaturated = true;
			continue;
		}
		if (
			argument === '--require-migrated' ||
			argument === '--require-closed'
		) {
			options.requireMigrated = true;
			continue;
		}

		unknownArguments.push( argument );
	}

	if ( unknownArguments.length > 0 ) {
		throw new Error(
			`Unknown argument(s): ${ unknownArguments.join( ', ' ) }`
		);
	}

	return options;
};

export const runCli = ( cliArguments, overrides = {} ) => {
	const activeRepositoryRoot = overrides.repositoryRoot ?? repositoryRoot;
	const ledgerContent =
		overrides.ledgerContent ??
		readFileSync(
			resolve( activeRepositoryRoot, ledgerRepositoryPath ),
			'utf8'
		);
	const metadata =
		overrides.metadata ??
		JSON.parse(
			readFileSync(
				resolve( activeRepositoryRoot, metadataRepositoryPath ),
				'utf8'
			)
		);
	const readFidelityPartition =
		overrides.readFidelityPartition ??
		( ( repositoryPath ) =>
			readFileSync(
				resolve( activeRepositoryRoot, repositoryPath ),
				'utf8'
			) );
	const log = overrides.log ?? console.log;
	const options = parseArguments( cliArguments );
	const contractMap = parseContractMap( ledgerContent );
	const summary = validateContractMap( contractMap, {
		metadata,
		repositoryRoot: activeRepositoryRoot,
		requireMigrated: options.requireMigrated,
		requireSaturated: options.requireSaturated,
	} );
	validateFidelityClaimCitations(
		contractMap,
		activeRepositoryRoot,
		readFidelityPartition
	);

	log( `Validated ${ summary.rowCount } WooPayments client contracts.` );

	if ( options.showSummary ) {
		const countGroups = [
			[ summary.dispositionCounts, '' ],
			[ summary.migrationStateCounts, 'migration_state\t' ],
			[ summary.nativeSupportStateCounts, 'native_support_state\t' ],
		];

		for ( const [ counts, labelPrefix ] of countGroups ) {
			for ( const [ name, count ] of Object.entries( counts ) ) {
				if ( count > 0 ) {
					log( `${ count }\t${ labelPrefix }${ name }` );
				}
			}
		}
	}

	return summary;
};

if (
	process.argv[ 1 ] &&
	resolve( process.argv[ 1 ] ) === fileURLToPath( import.meta.url )
) {
	runCli( process.argv.slice( 2 ) );
}
