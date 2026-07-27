import { execFileSync } from 'node:child_process';
import { readFileSync, realpathSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
	parseContractMap,
	validateContractMap,
	validateDispositionTransition,
	validateStateTransition,
} from './lib/woopayments-contract-map.mjs';

const binDirectory = dirname( fileURLToPath( import.meta.url ) );
const repositoryRoot = realpathSync(
	resolve( binDirectory, '../../../../..' )
);
const ledgerRepositoryPath =
	'plugins/woocommerce/tests/e2e/tests/woopayments-native/client-contract-map.tsv';
const metadataRepositoryPath =
	'plugins/woocommerce/tests/e2e/tests/woopayments-native/client-contract-map.meta.json';

const parseArguments = ( cliArguments ) => {
	const options = {
		fromGitRef: undefined,
		requireMigrated: false,
		requireSaturated: false,
		showSummary: false,
	};
	const unknownArguments = [];

	for ( let index = 0; index < cliArguments.length; index++ ) {
		const argument = cliArguments[ index ];

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
		if ( argument === '--from-git-ref' ) {
			const gitRef = cliArguments[ index + 1 ];

			if ( ! gitRef || gitRef.startsWith( '--' ) || options.fromGitRef ) {
				throw new Error(
					'--from-git-ref requires exactly one Git reference'
				);
			}
			options.fromGitRef = gitRef;
			index++;
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
	const activeLedgerRepositoryPath =
		overrides.ledgerRepositoryPath ?? ledgerRepositoryPath;
	const ledgerContent =
		overrides.ledgerContent ??
		readFileSync(
			resolve( activeRepositoryRoot, activeLedgerRepositoryPath ),
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
	const loadFromGitRef =
		overrides.loadFromGitRef ??
		( ( gitRef, repositoryPath ) =>
			execFileSync(
				'git',
				[ 'show', `${ gitRef }:${ repositoryPath }` ],
				{
					cwd: activeRepositoryRoot,
					encoding: 'utf8',
				}
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

	if ( options.fromGitRef ) {
		const previousContent = loadFromGitRef(
			options.fromGitRef,
			activeLedgerRepositoryPath
		);
		const previousContractMap = parseContractMap( previousContent, {
			allowLegacySchema: true,
		} );
		const previousRows = new Map(
			previousContractMap.rows.map( ( row ) => [ row.case_id, row ] )
		);

		for ( const row of contractMap.rows ) {
			const previousRow = previousRows.get( row.case_id );

			if ( ! previousRow ) {
				throw new Error(
					`Contract is missing from ${ options.fromGitRef }: ${ row.case_id }`
				);
			}

			if ( JSON.stringify( previousRow ) !== JSON.stringify( row ) ) {
				validateStateTransition(
					previousRow.migration_state,
					row.migration_state
				);
				validateDispositionTransition( previousRow, row );
			}
		}
	}

	log( `Validated ${ summary.rowCount } WooPayments client contracts.` );

	if ( options.showSummary ) {
		for ( const [ disposition, count ] of Object.entries(
			summary.dispositionCounts
		) ) {
			if ( count > 0 ) {
				log( `${ count }\t${ disposition }` );
			}
		}

		for ( const [ state, count ] of Object.entries(
			summary.migrationStateCounts
		) ) {
			if ( count > 0 ) {
				log( `${ count }\tmigration_state\t${ state }` );
			}
		}

		for ( const [ state, count ] of Object.entries(
			summary.nativeSupportStateCounts
		) ) {
			if ( count > 0 ) {
				log( `${ count }\tnative_support_state\t${ state }` );
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
