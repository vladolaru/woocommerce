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
import {
	CALIBRATION_NOTES_REPOSITORY_PATH,
	readCurrentTrackedFile,
	validateDeferredContractReopens,
} from './lib/woopayments-migration-evidence.mjs';

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
	const resolveGitRef =
		overrides.resolveGitRef ??
		( ( gitRef ) =>
			execFileSync(
				'git',
				[ 'rev-parse', '--verify', `${ gitRef }^{commit}` ],
				{
					cwd: activeRepositoryRoot,
					encoding: 'utf8',
				}
			).trim() );
	const readCurrentFile =
		overrides.readCurrentFile ??
		( ( repositoryPath ) => {
			const invalidEvidenceMessage = `Deferred contract reopening requires a tracked regular current-tree evidence file: ${ repositoryPath }`;

			return readCurrentTrackedFile(
				activeRepositoryRoot,
				repositoryPath,
				invalidEvidenceMessage
			).toString( 'utf8' );
		} );
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
		const comparisonCommit = resolveGitRef( options.fromGitRef );

		if ( ! /^[0-9a-f]{40}$/.test( comparisonCommit ) ) {
			throw new Error(
				`Could not resolve ${ options.fromGitRef } to an immutable commit`
			);
		}

		const previousContent = loadFromGitRef(
			comparisonCommit,
			ledgerRepositoryPath
		);
		const previousContractMap = parseContractMap( previousContent, {
			allowLegacySchema: true,
		} );
		const previousRows = new Map(
			previousContractMap.rows.map( ( row ) => [ row.case_id, row ] )
		);
		const deferredReopens = [];

		for ( const row of contractMap.rows ) {
			const previousRow = previousRows.get( row.case_id );

			if ( ! previousRow ) {
				throw new Error(
					`Contract is missing from ${ options.fromGitRef }: ${ row.case_id }`
				);
			}

			if ( JSON.stringify( previousRow ) !== JSON.stringify( row ) ) {
				if (
					previousRow.migration_state === 'deferred' &&
					row.migration_state === 'specified'
				) {
					deferredReopens.push( {
						previousRow,
						nextRow: row,
					} );
				} else {
					validateStateTransition(
						previousRow.migration_state,
						row.migration_state
					);
				}
				validateDispositionTransition( previousRow, row );
			}
		}

		validateDeferredContractReopens(
			previousContractMap.rows.filter(
				( row ) => row.migration_state === 'deferred'
			),
			deferredReopens,
			{
				metadata,
				repositoryRoot: activeRepositoryRoot,
				currentDeferredRows: contractMap.rows.filter(
					( row ) => row.migration_state === 'deferred'
				),
				loadPreviousEvidence: ( repositoryPath ) =>
					loadFromGitRef( comparisonCommit, repositoryPath ),
				loadCurrentEvidence: readCurrentFile,
				loadPreviousCalibrationNotes: () =>
					loadFromGitRef(
						comparisonCommit,
						CALIBRATION_NOTES_REPOSITORY_PATH
					),
				calibrationNotesContent: overrides.calibrationNotesContent,
			}
		);
	}

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
