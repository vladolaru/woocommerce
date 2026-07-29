import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';

import {
	isAnchoredMessagePattern,
	LOCAL_GAP_ID_PATTERN,
} from '../../utils/woopayments-native/known-gap-format.mjs';

const SECRET_KEY_PATTERN =
	/(token|password|secret|authorization|cookie|raw_payload)/i;
const ABSOLUTE_PATH_PATTERN = /^(?:\/|[A-Za-z]:[\\/])/;
const OBVIOUS_EMBEDDED_ABSOLUTE_PATH_PATTERN =
	/(?:^|[\s"'(=])(?:\/(?:Users|home|private|tmp|var)\/\S+|[A-Za-z]:[\\/]\S+)/;
const EMAIL_ADDRESS_PATTERN = /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i;
const BEARER_CREDENTIAL_PATTERN = /\bbearer\s+[A-Za-z0-9._~+/=-]{8,}\b/i;
const KEY_VALUE_CREDENTIAL_PATTERN =
	/\b(?:api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|password|passwd|secret|authorization|cookie)\s*(?:=|:)\s*(?:"[^"]+"|'[^']+'|[^\s,;]+)/i;
const RAW_PROVIDER_ID_PATTERN = /\b(?:acct|ch|cus|pi|pm)_[A-Za-z0-9]{6,}\b/;
const REDACTED_PROVIDER_REFERENCE_PATTERN =
	/^redacted:[a-z][a-z0-9-]*:sha256:[0-9a-f]{64}$/;
const REDACTED_PROVIDER_REFERENCE_CANDIDATE_PATTERN = /\bredacted:[^\s;,]+/g;
const SHA1_PATTERN = /^[0-9a-f]{40}$/;
const SHA256_PATTERN = /^[0-9a-f]{64}$/;
const URI_SCHEME_PATTERN = /^[a-z][a-z\d+.-]*:/i;

/**
 * The one definition of a legal repository-relative path in the ledger. The
 * contract map and the evidence records describe the same files, so they must
 * agree on what a path may look like; each caller wraps this with its own
 * error message rather than restating the rules.
 */
export const isRepositoryRelativePath = ( value ) => {
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

const EVIDENCE_KEYS = [
	'schema_version',
	'slice_id',
	'wc_base_commit',
	'verified_at_commit',
	'reference_contract_commit',
	'source_test_paths',
	'source_test_sha256',
	'contract_ids',
	'targets',
	'implementation_commits',
	'verification',
	'reviews',
	'known_gaps',
	'deferral',
];
const TARGET_KEYS = [ 'path', 'contract' ];
const VERIFICATION_KEYS = [ 'command', 'exit_code', 'summary' ];
const REVIEW_KEYS = [ 'role', 'verdict', 'source_test_sha256', 'summary' ];
const KNOWN_GAP_KEYS = [ 'id', 'owner', 'reference', 'fingerprint' ];
const FINGERPRINT_KEYS = [ 'error_name', 'message_pattern' ];
const DEFERRAL_KEYS = [
	'blocker',
	'affected_scope',
	'no_allowlisted_action_reason',
	'reference',
	'unlock_decision',
	'quarantine_status',
];
const DECISION_READY_DEFERRAL_KEYS = [
	'blocker',
	'affected_scope',
	'no_allowlisted_action_reason',
	'unlock_decision',
	'quarantine_status',
];
const UNRESOLVED_DECISION_PLACEHOLDERS = new Set( [
	'none',
	'pending',
	'unknown',
	'tbd',
	'n/a',
	'na',
	'notassessed',
	'ownerdecisionrequired',
] );

const isPlainObject = ( value ) =>
	value !== null &&
	typeof value === 'object' &&
	! Array.isArray( value ) &&
	Object.getPrototypeOf( value ) === Object.prototype;

const assertExactKeys = ( value, expectedKeys, label ) => {
	if ( ! isPlainObject( value ) ) {
		throw new Error( `${ label } must be a JSON object` );
	}

	const actualKeys = Object.keys( value ).toSorted();
	const sortedExpectedKeys = [ ...expectedKeys ].toSorted();

	if (
		actualKeys.length !== sortedExpectedKeys.length ||
		actualKeys.some(
			( actualKey, index ) => actualKey !== sortedExpectedKeys[ index ]
		)
	) {
		throw new Error(
			`Unsafe ${ label } keys; expected exactly ${ expectedKeys.join(
				', '
			) }`
		);
	}
};

const isSerializedJsonPayload = ( value ) => {
	const trimmedValue = value.trim();

	if (
		! (
			( trimmedValue.startsWith( '{' ) &&
				trimmedValue.endsWith( '}' ) ) ||
			( trimmedValue.startsWith( '[' ) && trimmedValue.endsWith( ']' ) )
		)
	) {
		return false;
	}

	try {
		const parsedValue = JSON.parse( trimmedValue );

		return parsedValue !== null && typeof parsedValue === 'object';
	} catch {
		return false;
	}
};

const containsUnsafeProviderData = ( value ) => {
	if ( RAW_PROVIDER_ID_PATTERN.test( value ) ) {
		return true;
	}

	for ( const match of value.matchAll(
		REDACTED_PROVIDER_REFERENCE_CANDIDATE_PATTERN
	) ) {
		if ( ! REDACTED_PROVIDER_REFERENCE_PATTERN.test( match[ 0 ] ) ) {
			return true;
		}
	}

	return false;
};

const getUnsafeStringReason = ( value ) => {
	if ( EMAIL_ADDRESS_PATTERN.test( value ) ) {
		return 'personally identifiable information is forbidden';
	}
	if (
		BEARER_CREDENTIAL_PATTERN.test( value ) ||
		KEY_VALUE_CREDENTIAL_PATTERN.test( value )
	) {
		return 'credentials are forbidden';
	}
	if ( isSerializedJsonPayload( value ) ) {
		return 'serialized payloads are forbidden';
	}
	if ( containsUnsafeProviderData( value ) ) {
		return 'provider identifiers require one-way redaction';
	}
	if (
		ABSOLUTE_PATH_PATTERN.test( value ) ||
		OBVIOUS_EMBEDDED_ABSOLUTE_PATH_PATTERN.test( value )
	) {
		return 'absolute paths are forbidden';
	}

	return null;
};

const isInvalidOrMatchAllFingerprint = ( messagePattern ) => {
	let fingerprint;

	try {
		fingerprint = new RegExp( messagePattern );
	} catch {
		return true;
	}

	return [ 'x', 'Native payment failed.', '123 !@# unrelated failure' ].every(
		( probe ) => fingerprint.test( probe )
	);
};

const assertPublicSafeJson = ( value, label = 'evidence' ) => {
	if ( value === null ) {
		return;
	}

	if ( Array.isArray( value ) ) {
		for ( const [ index, item ] of value.entries() ) {
			assertPublicSafeJson( item, `${ label }[${ index }]` );
		}
		return;
	}

	if ( isPlainObject( value ) ) {
		for ( const [ key, item ] of Object.entries( value ) ) {
			if ( SECRET_KEY_PATTERN.test( key ) ) {
				throw new Error(
					`Unsafe migration evidence key at ${ label }: ${ key }`
				);
			}
			assertPublicSafeJson( item, `${ label }.${ key }` );
		}
		return;
	}

	if ( typeof value === 'string' ) {
		const unsafeReason = getUnsafeStringReason( value );

		if ( unsafeReason ) {
			throw new Error(
				`Migration evidence is not public-safe at ${ label }: ${ unsafeReason }`
			);
		}
		return;
	}

	if (
		typeof value === 'number' &&
		Number.isFinite( value ) &&
		Number.isSafeInteger( value )
	) {
		return;
	}

	throw new Error(
		`Migration evidence is not public-safe JSON at ${ label }`
	);
};

const assertExactString = ( value, label, pattern ) => {
	if (
		typeof value !== 'string' ||
		value === '' ||
		value !== value.trim() ||
		( pattern && ! pattern.test( value ) )
	) {
		throw new Error( `Invalid migration evidence ${ label }` );
	}
};

const assertArray = ( value, label ) => {
	if ( ! Array.isArray( value ) ) {
		throw new Error( `Invalid migration evidence ${ label }` );
	}
};

const assertRepositoryRelativePath = ( value, label ) => {
	assertExactString( value, label );

	for ( const repositoryPath of value.split( ';' ) ) {
		if ( ! isRepositoryRelativePath( repositoryPath ) ) {
			throw new Error( `Invalid migration evidence ${ label }` );
		}
	}
};

const sha256 = ( value ) =>
	createHash( 'sha256' ).update( value ).digest( 'hex' );

const assertSourceTestBundle = (
	sourceTestPaths,
	sourceTestSha256,
	repositoryRoot,
	verifiedAtCommit
) => {
	assertArray( sourceTestPaths, 'source_test_paths' );
	if ( sourceTestPaths.length === 0 ) {
		throw new Error(
			'Invalid migration evidence source_test_paths; expected at least one reviewed source'
		);
	}

	const uniquePaths = new Set();
	for ( const [ index, sourceTestPath ] of sourceTestPaths.entries() ) {
		assertRepositoryRelativePath(
			sourceTestPath,
			`source_test_paths[${ index }]`
		);
		if ( uniquePaths.has( sourceTestPath ) ) {
			throw new Error(
				`Invalid migration evidence source_test_paths; duplicate path ${ sourceTestPath }`
			);
		}
		uniquePaths.add( sourceTestPath );
	}

	const sourceEntries = [ ...uniquePaths ].toSorted().map( ( sourcePath ) => {
		let contents;
		try {
			contents = execFileSync(
				'git',
				[
					'-C',
					repositoryRoot,
					'show',
					`${ verifiedAtCommit }:${ sourcePath }`,
				],
				{ maxBuffer: 10 * 1024 * 1024 }
			);
		} catch ( error ) {
			throw new Error(
				`Invalid migration evidence source_test_paths; cannot read ${ sourcePath } at verified_at_commit`,
				{ cause: error }
			);
		}
		return [ sourcePath, sha256( contents ) ];
	} );
	const actualSha256 = sha256( JSON.stringify( sourceEntries ) );

	if ( actualSha256 !== sourceTestSha256 ) {
		throw new Error(
			'Invalid migration evidence source bundle SHA-256; reviewed source changed'
		);
	}
};

const assertTargets = ( targets, row ) => {
	assertArray( targets, 'targets' );

	for ( const [ index, target ] of targets.entries() ) {
		assertExactKeys( target, TARGET_KEYS, `evidence target ${ index }` );
		assertRepositoryRelativePath( target.path, `targets[${ index }].path` );
		assertExactString( target.contract, `targets[${ index }].contract` );
	}

	if (
		! targets.some(
			( target ) =>
				target.path === row.target_path &&
				target.contract === row.target_contract
		)
	) {
		throw new Error(
			`Invalid migration evidence targets for ${ row.case_id }; exact ledger target is missing`
		);
	}
};

const assertImplementationCommits = ( implementationCommits, row ) => {
	assertArray( implementationCommits, 'implementation_commits' );

	for ( const commit of implementationCommits ) {
		assertExactString(
			commit,
			'implementation_commits entry',
			SHA1_PATTERN
		);
	}

	if (
		[ 'implemented', 'verified', 'closed' ].includes(
			row.migration_state
		) &&
		implementationCommits.length === 0
	) {
		throw new Error(
			`Invalid migration evidence implementation_commits for ${ row.case_id }`
		);
	}
};

const assertVerification = ( verification, row ) => {
	assertArray( verification, 'verification' );

	for ( const [ index, result ] of verification.entries() ) {
		assertExactKeys(
			result,
			VERIFICATION_KEYS,
			`evidence verification ${ index }`
		);
		assertExactString( result.command, `verification[${ index }].command` );
		if (
			! Number.isSafeInteger( result.exit_code ) ||
			result.exit_code < 0
		) {
			throw new Error(
				`Invalid migration evidence verification[${ index }].exit_code`
			);
		}
		assertExactString( result.summary, `verification[${ index }].summary` );
	}

	if (
		[ 'verified', 'closed' ].includes( row.migration_state ) &&
		( verification.length === 0 ||
			verification.some( ( result ) => result.exit_code !== 0 ) )
	) {
		throw new Error(
			`Invalid migration evidence verification for ${ row.case_id }`
		);
	}
};

const assertReviews = ( reviews, sourceTestSha256 ) => {
	assertArray( reviews, 'reviews' );

	for ( const [ index, review ] of reviews.entries() ) {
		assertExactKeys( review, REVIEW_KEYS, `evidence review ${ index }` );
		assertExactString( review.role, `reviews[${ index }].role` );
		assertExactString( review.verdict, `reviews[${ index }].verdict` );
		assertExactString(
			review.source_test_sha256,
			`reviews[${ index }].source_test_sha256`,
			SHA256_PATTERN
		);
		assertExactString( review.summary, `reviews[${ index }].summary` );
	}

	if (
		reviews.length === 0 ||
		reviews.some( ( review ) => review.verdict !== 'APPROVE' ) ||
		! reviews.some(
			( review ) => review.source_test_sha256 === sourceTestSha256
		)
	) {
		throw new Error(
			'Invalid migration evidence reviews; every review must approve and at least one must bind the current source test SHA-256'
		);
	}
};

const assertKnownGaps = ( knownGaps, row ) => {
	assertArray( knownGaps, 'known_gaps' );

	for ( const [ index, knownGap ] of knownGaps.entries() ) {
		assertExactKeys(
			knownGap,
			KNOWN_GAP_KEYS,
			`evidence known gap ${ index }`
		);
		assertExactString(
			knownGap.id,
			`known_gaps[${ index }].id`,
			LOCAL_GAP_ID_PATTERN
		);
		assertExactString( knownGap.owner, `known_gaps[${ index }].owner` );
		assertExactString(
			knownGap.reference,
			`known_gaps[${ index }].reference`
		);
		assertExactKeys(
			knownGap.fingerprint,
			FINGERPRINT_KEYS,
			`evidence known gap ${ index } fingerprint`
		);
		assertExactString(
			knownGap.fingerprint.error_name,
			`known_gaps[${ index }].fingerprint.error_name`
		);
		assertExactString(
			knownGap.fingerprint.message_pattern,
			`known_gaps[${ index }].fingerprint.message_pattern`
		);

		if (
			! isAnchoredMessagePattern(
				knownGap.fingerprint.message_pattern
			) ||
			isInvalidOrMatchAllFingerprint(
				knownGap.fingerprint.message_pattern
			) ||
			knownGap.reference !== row.gap_or_decision_reference
		) {
			throw new Error(
				`Invalid migration evidence known_gaps[${ index }] row binding or exact fingerprint`
			);
		}
	}

	if (
		( row.native_support_state === 'known-gap' &&
			knownGaps.length === 0 ) ||
		( row.native_support_state !== 'known-gap' && knownGaps.length !== 0 )
	) {
		throw new Error(
			`Invalid migration evidence known_gaps for ${ row.case_id }`
		);
	}
};

const assertDeferral = ( deferral, row, evidence ) => {
	if ( row.migration_state !== 'deferred' ) {
		if ( deferral !== null ) {
			throw new Error(
				`Invalid migration evidence deferral for ${ row.case_id }`
			);
		}
		return;
	}

	assertExactKeys( deferral, DEFERRAL_KEYS, 'evidence deferral' );
	assertExactString( deferral.reference, 'deferral.reference' );
	for ( const key of DECISION_READY_DEFERRAL_KEYS ) {
		assertExactString( deferral[ key ], `deferral.${ key }` );

		const normalizedValue = deferral[ key ]
			.toLowerCase()
			.replaceAll( /[\s_-]/g, '' );

		if ( UNRESOLVED_DECISION_PLACEHOLDERS.has( normalizedValue ) ) {
			throw new Error(
				`Invalid migration evidence deferral.${ key }; unresolved placeholders are forbidden`
			);
		}
	}

	if (
		deferral.reference !== row.gap_or_decision_reference ||
		evidence.implementation_commits.length !== 0 ||
		evidence.verification.length !== 0
	) {
		throw new Error(
			`Invalid migration evidence deferral for ${ row.case_id }; reference must match and executed work must be empty`
		);
	}
};

export const validateMigrationEvidence = (
	evidence,
	{ row, metadata, repositoryRoot } = {}
) => {
	assertPublicSafeJson( evidence );
	assertExactKeys( evidence, EVIDENCE_KEYS, 'migration evidence' );

	if (
		! isPlainObject( row ) ||
		! isPlainObject( metadata ) ||
		typeof repositoryRoot !== 'string' ||
		repositoryRoot.length === 0
	) {
		throw new Error(
			'Migration evidence requires row, metadata, and repository root context'
		);
	}

	if ( evidence.schema_version !== 1 ) {
		throw new Error(
			'Invalid migration evidence schema_version; expected 1'
		);
	}
	assertExactString( evidence.slice_id, 'slice_id' );
	assertExactString(
		evidence.wc_base_commit,
		'wc_base_commit',
		SHA1_PATTERN
	);
	assertExactString(
		evidence.verified_at_commit,
		'verified_at_commit',
		SHA1_PATTERN
	);
	assertExactString(
		evidence.reference_contract_commit,
		'reference_contract_commit',
		SHA1_PATTERN
	);
	if ( evidence.reference_contract_commit !== metadata.source_commit ) {
		throw new Error(
			'Invalid migration evidence reference_contract_commit; expected frozen metadata source_commit'
		);
	}
	assertExactString(
		evidence.source_test_sha256,
		'source_test_sha256',
		SHA256_PATTERN
	);
	assertSourceTestBundle(
		evidence.source_test_paths,
		evidence.source_test_sha256,
		repositoryRoot,
		evidence.verified_at_commit
	);

	assertArray( evidence.contract_ids, 'contract_ids' );
	for ( const contractId of evidence.contract_ids ) {
		assertExactString( contractId, 'contract_ids entry' );
	}
	if ( ! evidence.contract_ids.includes( row.case_id ) ) {
		throw new Error(
			`Invalid migration evidence contract_ids for ${ row.case_id }`
		);
	}

	assertTargets( evidence.targets, row );
	assertImplementationCommits( evidence.implementation_commits, row );
	assertVerification( evidence.verification, row );
	assertReviews( evidence.reviews, evidence.source_test_sha256 );
	assertKnownGaps( evidence.known_gaps, row );
	assertDeferral( evidence.deferral, row, evidence );

	return evidence;
};
