import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
	existsSync,
	lstatSync,
	readFileSync,
	realpathSync,
	statSync,
} from 'node:fs';
import {
	dirname,
	isAbsolute,
	relative,
	resolve,
	sep as pathSeparator,
} from 'node:path';
import ts from 'typescript';

import {
	isAnchoredMessagePattern,
	LOCAL_GAP_ID_PATTERN,
} from '../../utils/woopayments-native/known-gap-format.mjs';

const SECRET_KEY_PATTERN =
	/(token|password|secret|authorization|cookie|raw_payload)/i;
const MAX_EVIDENCE_STRING_LENGTH = 4096;
const ABSOLUTE_PATH_PATTERN = /^(?:\/|[A-Za-z]:[\\/])/;
const OBVIOUS_EMBEDDED_ABSOLUTE_PATH_PATTERN =
	/(?:^|[\s"'(=])(?:\/(?:Users|home|private|tmp|var)\/\S+|[A-Za-z]:[\\/]\S+)/;
const EMAIL_ADDRESS_PATTERN = /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i;
const BEARER_CREDENTIAL_PATTERN = /\bbearer\s+[A-Za-z0-9._~+/=-]{8,}\b/i;
const KEY_VALUE_CREDENTIAL_PATTERN =
	/\b(?:api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|password|passwd|secret|authorization|cookie)\s*(?:=|:)\s*(?:"[^"]+"|'[^']+'|[^\s,;]+)/i;
const PROVIDER_SECRET_TOKEN_PATTERN =
	/\b(?:(?:sk|pk|rk)_(?:live|test)|whsec)_[A-Za-z0-9]{8,}\b/;
const JWT_LIKE_PATTERN =
	/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}\b/;
const RAW_PROVIDER_ID_PATTERN = /\b(?:acct|ch|cus|pi|pm)_[A-Za-z0-9]{6,}\b/;
const REDACTED_PROVIDER_REFERENCE_PATTERN =
	/^redacted:[a-z][a-z0-9-]*:sha256:[0-9a-f]{64}$/;
const REDACTED_PROVIDER_REFERENCE_CANDIDATE_PATTERN = /\bredacted:[^\s;,]+/g;
const ISO_CALENDAR_DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
const CALIBRATION_NOTE_REFERENCE_PATTERN =
	/^calibration-notes:(\d{4}-\d{2}-\d{2}):([a-z0-9]+(?:-[a-z0-9]+)*)$/;
const PUBLIC_WOOCOMMERCE_REFERENCE_PATTERN =
	/^https:\/\/github\.com\/woocommerce\/woocommerce\/(?:issues\/[1-9]\d*|pull\/[1-9]\d*|commit\/[0-9a-f]{40})$/;
export const CALIBRATION_NOTES_REPOSITORY_PATH =
	'plugins/woocommerce/tests/e2e/tests/woopayments-native/evidence/calibration-notes.md';
const SHA1_PATTERN = /^[0-9a-f]{40}$/;
const SHA256_PATTERN = /^[0-9a-f]{64}$/;
const URI_SCHEME_PATTERN = /^[a-z][a-z\d+.-]*:/i;
const REGULAR_GIT_INDEX_MODES = new Set( [ '100644', '100755' ] );
const INFRASTRUCTURE_MODULE_PATTERNS = [
	/^plugins\/woocommerce\/tests\/e2e\/fixtures\//,
	/^plugins\/woocommerce\/tests\/e2e\/reporters\//,
	/^plugins\/woocommerce\/tests\/e2e\/test-data\//,
	/^plugins\/woocommerce\/tests\/e2e\/utils\/woopayments-native\/(?:resource-locks|resource-quarantine|provider-write-journal|durable-fs|runtime-readiness|transition-allocation|provider-fixture|known-gap|lock-test-helpers)\.ts$/,
	/^plugins\/woocommerce\/tests\/e2e\/utils\/woopayments-native\/known-gap-format\.mjs$/,
];

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
	'closures',
	'known_gaps',
	'deferral',
];
const TARGET_KEYS = [ 'path', 'contract' ];
const VERIFICATION_KEYS = [ 'command', 'exit_code', 'summary' ];
const REVIEW_KEYS = [ 'role', 'verdict', 'source_test_sha256', 'summary' ];
const CLOSURE_KEYS = [ 'contract_id', 'target', 'verification', 'reviews' ];
export const REQUIRED_CLOSURE_REVIEW_ROLES = [
	'code',
	'e2e-tests',
	'reliability',
];
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
const DEFERRAL_KEYS_WITH_UNLOCK_SATISFACTIONS = [
	...DEFERRAL_KEYS,
	'unlock_satisfactions',
];
const UNLOCK_SATISFACTION_KEYS = [
	'contract_id',
	'unlock_decision',
	'satisfied_on',
	'reference',
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

const findStructuredPayloadEnd = ( value, startIndex ) => {
	const expectedClosers = [ value[ startIndex ] === '{' ? '}' : ']' ];
	let isInsideString = false;
	let isEscaped = false;

	for ( let index = startIndex + 1; index < value.length; index++ ) {
		const character = value[ index ];

		if ( isInsideString ) {
			if ( isEscaped ) {
				isEscaped = false;
			} else if ( character === '\\' ) {
				isEscaped = true;
			} else if ( character === '"' ) {
				isInsideString = false;
			}
			continue;
		}

		if ( character === '"' ) {
			isInsideString = true;
			continue;
		}
		if ( character === '{' || character === '[' ) {
			expectedClosers.push( character === '{' ? '}' : ']' );
			continue;
		}
		if ( character !== '}' && character !== ']' ) {
			continue;
		}
		if ( character !== expectedClosers.at( -1 ) ) {
			return -1;
		}

		expectedClosers.pop();
		if ( expectedClosers.length === 0 ) {
			return index;
		}
	}

	return -1;
};

const containsEmbeddedSerializedJsonPayload = ( value ) => {
	for ( let startIndex = 0; startIndex < value.length; startIndex++ ) {
		if ( value[ startIndex ] !== '{' && value[ startIndex ] !== '[' ) {
			continue;
		}

		const endIndex = findStructuredPayloadEnd( value, startIndex );

		if ( endIndex === -1 ) {
			continue;
		}

		try {
			const parsedValue = JSON.parse(
				value.slice( startIndex, endIndex + 1 )
			);

			if ( parsedValue !== null && typeof parsedValue === 'object' ) {
				return true;
			}
		} catch {
			// Keep scanning: incidental balanced punctuation is allowed.
		}
	}

	return false;
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
	if ( value.length > MAX_EVIDENCE_STRING_LENGTH ) {
		return `evidence strings must not exceed ${ MAX_EVIDENCE_STRING_LENGTH } characters`;
	}
	if ( EMAIL_ADDRESS_PATTERN.test( value ) ) {
		return 'personally identifiable information is forbidden';
	}
	if (
		BEARER_CREDENTIAL_PATTERN.test( value ) ||
		KEY_VALUE_CREDENTIAL_PATTERN.test( value ) ||
		PROVIDER_SECRET_TOKEN_PATTERN.test( value ) ||
		JWT_LIKE_PATTERN.test( value )
	) {
		return 'credentials are forbidden';
	}
	if (
		isSerializedJsonPayload( value ) ||
		containsEmbeddedSerializedJsonPayload( value )
	) {
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

const resolveCurrentSource = (
	repositoryRoot,
	sourcePath,
	invalidSourceMessage
) => {
	try {
		if ( ! isRepositoryRelativePath( sourcePath ) ) {
			throw new Error( invalidSourceMessage );
		}

		const indexEntries = execFileSync(
			'git',
			[
				'-C',
				repositoryRoot,
				'ls-files',
				'--error-unmatch',
				'--stage',
				'--',
				`:(literal)${ sourcePath }`,
			],
			{
				encoding: 'utf8',
				stdio: [ 'ignore', 'pipe', 'ignore' ],
			}
		)
			.trimEnd()
			.split( '\n' );
		const [ indexMode, , indexStage ] = indexEntries[ 0 ]
			.split( '\t', 1 )[ 0 ]
			.split( ' ' );

		const realRepositoryRoot = realpathSync( repositoryRoot );
		const absoluteSourcePath = resolve( realRepositoryRoot, sourcePath );
		const realSourcePath = realpathSync( absoluteSourcePath );
		const repositoryRelativeRealPath = relative(
			realRepositoryRoot,
			realSourcePath
		);

		if (
			indexEntries.length !== 1 ||
			! REGULAR_GIT_INDEX_MODES.has( indexMode ) ||
			indexStage !== '0' ||
			realSourcePath !== absoluteSourcePath ||
			repositoryRelativeRealPath === '..' ||
			repositoryRelativeRealPath.startsWith( `..${ pathSeparator }` ) ||
			isAbsolute( repositoryRelativeRealPath ) ||
			! lstatSync( absoluteSourcePath ).isFile() ||
			! statSync( realSourcePath ).isFile()
		) {
			throw new Error( invalidSourceMessage );
		}

		return realSourcePath;
	} catch ( error ) {
		if ( error.message === invalidSourceMessage ) {
			throw error;
		}

		throw new Error( invalidSourceMessage, { cause: error } );
	}
};

export const readCurrentTrackedFile = (
	repositoryRoot,
	repositoryPath,
	invalidFileMessage
) =>
	readFileSync(
		resolveCurrentSource(
			repositoryRoot,
			repositoryPath,
			invalidFileMessage
		)
	);

const readCurrentSource = ( repositoryRoot, sourcePath ) => {
	const invalidSourceMessage = `Invalid migration evidence source_test_paths; current source must be a tracked regular file within the repository: ${ sourcePath }`;

	return readCurrentTrackedFile(
		repositoryRoot,
		sourcePath,
		invalidSourceMessage
	);
};

const assertSourceTestBundle = (
	sourceTestPaths,
	sourceTestSha256,
	repositoryRoot,
	verifiedAtCommit,
	bindCurrentBytes
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
		if ( bindCurrentBytes ) {
			contents = readCurrentSource( repositoryRoot, sourcePath );
		} else {
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
		}
		return [ sourcePath, sha256( contents ) ];
	} );
	const actualSha256 = sha256( JSON.stringify( sourceEntries ) );

	if ( actualSha256 !== sourceTestSha256 ) {
		throw new Error(
			bindCurrentBytes
				? 'Invalid migration evidence source bundle SHA-256; terminal evidence must attest the current retained bytes'
				: 'Invalid migration evidence source bundle SHA-256; reviewed source changed'
		);
	}
};

const isInfrastructureModule = ( repositoryPath ) =>
	INFRASTRUCTURE_MODULE_PATTERNS.some( ( pattern ) =>
		pattern.test( repositoryPath )
	);

const scriptKindForPath = ( sourcePath ) => {
	if ( sourcePath.endsWith( '.tsx' ) ) {
		return ts.ScriptKind.TSX;
	}
	if ( sourcePath.endsWith( '.jsx' ) ) {
		return ts.ScriptKind.JSX;
	}
	if ( sourcePath.endsWith( '.js' ) || sourcePath.endsWith( '.mjs' ) ) {
		return ts.ScriptKind.JS;
	}

	return ts.ScriptKind.TS;
};

const collectRelativeModuleSpecifiers = ( sourcePath, source ) => {
	const sourceFile = ts.createSourceFile(
		sourcePath,
		source,
		ts.ScriptTarget.Latest,
		false,
		scriptKindForPath( sourcePath )
	);
	const specifiers = [];

	const visit = ( node ) => {
		let moduleSpecifier;

		if (
			( ts.isImportDeclaration( node ) ||
				ts.isExportDeclaration( node ) ) &&
			node.moduleSpecifier &&
			ts.isStringLiteral( node.moduleSpecifier )
		) {
			moduleSpecifier = node.moduleSpecifier;
		} else if ( ts.isCallExpression( node ) ) {
			const callee = ts.skipOuterExpressions( node.expression );
			const isDynamicImport = callee.kind === ts.SyntaxKind.ImportKeyword;
			const isCommonJsRequire =
				ts.isIdentifier( callee ) && callee.text === 'require';

			if ( isDynamicImport || isCommonJsRequire ) {
				[ moduleSpecifier ] = node.arguments;

				if (
					! moduleSpecifier ||
					! ts.isStringLiteralLike( moduleSpecifier )
				) {
					throw new Error(
						`Closure bundle cannot attest a non-literal dynamic dependency in ${ sourcePath }`
					);
				}
			}
		} else if (
			ts.isImportEqualsDeclaration( node ) &&
			ts.isExternalModuleReference( node.moduleReference )
		) {
			moduleSpecifier = node.moduleReference.expression;

			if (
				! moduleSpecifier ||
				! ts.isStringLiteralLike( moduleSpecifier )
			) {
				throw new Error(
					`Closure bundle cannot attest a non-literal dynamic dependency in ${ sourcePath }`
				);
			}
		}

		if ( moduleSpecifier?.text.startsWith( '.' ) ) {
			specifiers.push( moduleSpecifier.text );
		}

		ts.forEachChild( node, visit );
	};

	visit( sourceFile );

	return specifiers;
};

const closureBoundaryMessage = ( row, fromPath, specifier ) =>
	`Invalid migration evidence for ${ row.case_id }; bundle must attest every behavior module the target imports: ${ specifier } from ${ fromPath } must resolve to a Git stage-0 tracked regular repository file within the repository without symlinks`;

const resolveRelativeImport = (
	row,
	repositoryRoot,
	fromPath,
	fromFile,
	specifier
) => {
	const base = resolve( dirname( fromFile ), specifier );
	const realRepositoryRoot = realpathSync( repositoryRoot );
	const candidates = [
		base,
		`${ base }.js`,
		`${ base }.mjs`,
		`${ base }.jsx`,
		`${ base }.ts`,
		`${ base }.tsx`,
		resolve( base, 'index.js' ),
		resolve( base, 'index.mjs' ),
		resolve( base, 'index.jsx' ),
		resolve( base, 'index.ts' ),
		resolve( base, 'index.tsx' ),
	];

	for ( const candidate of candidates ) {
		if ( ! existsSync( candidate ) ) {
			continue;
		}

		if ( candidate === base && lstatSync( candidate ).isDirectory() ) {
			continue;
		}

		const repositoryPath = relative(
			realRepositoryRoot,
			candidate
		).replaceAll( '\\', '/' );
		const absolutePath = resolveCurrentSource(
			repositoryRoot,
			repositoryPath,
			closureBoundaryMessage( row, fromPath, specifier )
		);

		return { absolutePath, repositoryPath };
	}

	throw new Error(
		`Invalid migration evidence for ${ row.case_id }; bundle must attest every behavior module the target imports: unresolved relative import ${ specifier } from ${ fromPath }`
	);
};

export const collectClosureBundlePaths = ( row, repositoryRoot ) => {
	const targetPaths = row.target_path
		.split( ';' )
		.map( ( targetPath ) => targetPath.trim() );
	const visited = new Set();
	const required = new Set( targetPaths );
	const queue = targetPaths.map( ( targetPath ) => ( {
		absolutePath: resolveCurrentSource(
			repositoryRoot,
			targetPath,
			closureBoundaryMessage( row, targetPath, targetPath )
		),
		repositoryPath: targetPath,
	} ) );

	while ( queue.length > 0 ) {
		const current = queue.pop();

		if ( visited.has( current.repositoryPath ) ) {
			continue;
		}

		visited.add( current.repositoryPath );
		const source = readFileSync( current.absolutePath, 'utf8' );

		for ( const specifier of collectRelativeModuleSpecifiers(
			current.repositoryPath,
			source
		) ) {
			const resolved = resolveRelativeImport(
				row,
				repositoryRoot,
				current.repositoryPath,
				current.absolutePath,
				specifier
			);

			if ( isInfrastructureModule( resolved.repositoryPath ) ) {
				continue;
			}

			required.add( resolved.repositoryPath );
			queue.push( resolved );
		}
	}

	return [ ...required ];
};

export const assertClosureBundleCoverage = (
	row,
	evidence,
	repositoryRoot
) => {
	const required = collectClosureBundlePaths( row, repositoryRoot );

	const attested = new Set( evidence.source_test_paths );
	const missing = required.filter( ( path ) => ! attested.has( path ) );

	if ( missing.length > 0 ) {
		throw new Error(
			`Invalid migration evidence for ${
				row.case_id
			}; bundle must attest every behavior module the target imports: missing ${ missing.join(
				', '
			) }`
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

const assertReviews = ( reviews ) => {
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
		reviews.some( ( review ) => review.verdict !== 'APPROVE' )
	) {
		throw new Error(
			'Invalid migration evidence reviews; every review must approve'
		);
	}
};

const assertClosureReviewList = ( reviews, label ) => {
	assertArray( reviews, label );

	const roles = new Set();
	for ( const [ index, review ] of reviews.entries() ) {
		assertExactKeys( review, REVIEW_KEYS, `${ label } review ${ index }` );
		assertExactString( review.role, `${ label }[${ index }].role` );
		assertExactString( review.verdict, `${ label }[${ index }].verdict` );
		assertExactString(
			review.source_test_sha256,
			`${ label }[${ index }].source_test_sha256`,
			SHA256_PATTERN
		);
		assertExactString( review.summary, `${ label }[${ index }].summary` );

		if ( roles.has( review.role ) ) {
			throw new Error(
				`Invalid migration evidence ${ label }; duplicate review role`
			);
		}
		roles.add( review.role );
	}
};

const assertClosures = ( closures, row, evidence ) => {
	assertArray( closures, 'closures' );

	const contractIds = new Set();
	for ( const [ index, closure ] of closures.entries() ) {
		assertExactKeys( closure, CLOSURE_KEYS, `evidence closure ${ index }` );
		assertExactString(
			closure.contract_id,
			`closures[${ index }].contract_id`
		);
		if ( contractIds.has( closure.contract_id ) ) {
			throw new Error(
				`Invalid migration evidence closures[${ index }].contract_id; duplicate contract_id`
			);
		}
		contractIds.add( closure.contract_id );

		if ( ! evidence.contract_ids.includes( closure.contract_id ) ) {
			throw new Error(
				`Invalid migration evidence closures[${ index }].contract_id; contract must occur in contract_ids`
			);
		}

		assertExactKeys(
			closure.target,
			TARGET_KEYS,
			`evidence closure ${ index } target`
		);
		assertRepositoryRelativePath(
			closure.target.path,
			`closures[${ index }].target.path`
		);
		assertExactString(
			closure.target.contract,
			`closures[${ index }].target.contract`
		);

		assertArray(
			closure.verification,
			`closures[${ index }].verification`
		);
		for ( const [
			resultIndex,
			result,
		] of closure.verification.entries() ) {
			assertExactKeys(
				result,
				VERIFICATION_KEYS,
				`evidence closure ${ index } verification ${ resultIndex }`
			);
			assertExactString(
				result.command,
				`closures[${ index }].verification[${ resultIndex }].command`
			);
			if (
				! Number.isSafeInteger( result.exit_code ) ||
				result.exit_code < 0
			) {
				throw new Error(
					`Invalid migration evidence closures[${ index }].verification[${ resultIndex }].exit_code`
				);
			}
			assertExactString(
				result.summary,
				`closures[${ index }].verification[${ resultIndex }].summary`
			);
		}

		assertClosureReviewList(
			closure.reviews,
			`closures[${ index }].reviews`
		);
	}

	if ( ! [ 'verified', 'closed' ].includes( row.migration_state ) ) {
		return;
	}

	const rowClosure = closures.find(
		( closure ) => closure.contract_id === row.case_id
	);
	if ( ! rowClosure ) {
		throw new Error(
			`Invalid migration evidence closures for ${ row.case_id }; a terminal row requires its own closure entry`
		);
	}
	if (
		rowClosure.target.path !== row.target_path ||
		rowClosure.target.contract !== row.target_contract
	) {
		throw new Error(
			`Invalid migration evidence closures for ${ row.case_id }; closure target must match the exact ledger target`
		);
	}
	if (
		rowClosure.verification.length === 0 ||
		rowClosure.verification.some( ( result ) => result.exit_code !== 0 )
	) {
		throw new Error(
			`Invalid migration evidence closures for ${ row.case_id }; closure verification must exist and pass`
		);
	}

	const rowClosureReviewRoles = new Set(
		rowClosure.reviews.map( ( review ) => review.role )
	);
	if (
		REQUIRED_CLOSURE_REVIEW_ROLES.some(
			( role ) => ! rowClosureReviewRoles.has( role )
		) ||
		rowClosure.reviews.some(
			( review ) =>
				review.verdict !== 'APPROVE' ||
				review.source_test_sha256 !== evidence.source_test_sha256
		)
	) {
		throw new Error(
			`Invalid migration evidence closures for ${ row.case_id }; every required role must approve the current source bundle`
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

const isRealIsoCalendarDate = ( value ) => {
	if ( ! ISO_CALENDAR_DATE_PATTERN.test( value ) ) {
		return false;
	}

	const [ year, month, day ] = value.split( '-' ).map( Number );
	const date = new Date( Date.UTC( year, month - 1, day ) );

	return (
		year >= 1 &&
		date.getUTCFullYear() === year &&
		date.getUTCMonth() === month - 1 &&
		date.getUTCDate() === day
	);
};

const headingSlug = ( title ) =>
	title
		.normalize( 'NFKD' )
		.replaceAll( /[\u0300-\u036f]/g, '' )
		.toLowerCase()
		.replaceAll( /[^a-z0-9]+/g, '-' )
		.replaceAll( /^-|-$/g, '' );

const hasMatchingCalibrationHeading = ( content, date, expectedSlug ) =>
	content.split( /\r?\n/ ).some( ( line ) => {
		const headingMatch = line.match(
			/^## (\d{4}-\d{2}-\d{2}) — (\S(?:.*\S)?)$/
		);

		return (
			headingMatch?.[ 1 ] === date &&
			headingSlug( headingMatch[ 2 ] ) === expectedSlug
		);
	} );

const assertUnlockSatisfactions = (
	deferral,
	evidence,
	repositoryRoot,
	calibrationNotesContent
) => {
	if ( ! Object.hasOwn( deferral, 'unlock_satisfactions' ) ) {
		return;
	}

	assertArray(
		deferral.unlock_satisfactions,
		'deferral.unlock_satisfactions'
	);
	const contractIds = new Set();
	let loadedCalibrationNotes = calibrationNotesContent;

	for ( const [
		index,
		satisfaction,
	] of deferral.unlock_satisfactions.entries() ) {
		const label = `unlock satisfaction ${ index }`;

		assertExactKeys( satisfaction, UNLOCK_SATISFACTION_KEYS, label );
		assertExactString(
			satisfaction.contract_id,
			`deferral.unlock_satisfactions[${ index }].contract_id`
		);
		assertExactString(
			satisfaction.unlock_decision,
			`deferral.unlock_satisfactions[${ index }].unlock_decision`
		);
		assertExactString(
			satisfaction.satisfied_on,
			`deferral.unlock_satisfactions[${ index }].satisfied_on`
		);
		assertExactString(
			satisfaction.reference,
			`deferral.unlock_satisfactions[${ index }].reference`
		);

		if ( contractIds.has( satisfaction.contract_id ) ) {
			throw new Error(
				`Invalid migration evidence unlock satisfaction ${ index }; duplicate contract_id`
			);
		}
		contractIds.add( satisfaction.contract_id );

		if ( ! evidence.contract_ids.includes( satisfaction.contract_id ) ) {
			throw new Error(
				`Invalid migration evidence unlock satisfaction ${ index }; contract must occur in contract_ids`
			);
		}

		if ( ! isRealIsoCalendarDate( satisfaction.satisfied_on ) ) {
			throw new Error(
				`Invalid migration evidence unlock satisfaction ${ index }; satisfied_on must be a real YYYY-MM-DD calendar date`
			);
		}

		const calibrationMatch = satisfaction.reference.match(
			CALIBRATION_NOTE_REFERENCE_PATTERN
		);
		if ( calibrationMatch ) {
			if ( calibrationMatch[ 1 ] !== satisfaction.satisfied_on ) {
				throw new Error(
					`Invalid migration evidence unlock satisfaction ${ index }; calibration reference date must match satisfied_on`
				);
			}

			loadedCalibrationNotes ??= readCurrentSource(
				repositoryRoot,
				CALIBRATION_NOTES_REPOSITORY_PATH
			).toString( 'utf8' );
			if (
				! hasMatchingCalibrationHeading(
					loadedCalibrationNotes,
					calibrationMatch[ 1 ],
					calibrationMatch[ 2 ]
				)
			) {
				throw new Error(
					`Invalid migration evidence unlock satisfaction ${ index }; calibration reference requires a matching dated heading`
				);
			}
			continue;
		}

		if (
			! REDACTED_PROVIDER_REFERENCE_PATTERN.test(
				satisfaction.reference
			) &&
			! PUBLIC_WOOCOMMERCE_REFERENCE_PATTERN.test(
				satisfaction.reference
			)
		) {
			throw new Error(
				`Invalid migration evidence unlock satisfaction ${ index }; reference must be a redacted SHA-256, calibration-notes reference, or approved public WooCommerce reference`
			);
		}
	}
};

const assertDeferral = (
	deferral,
	row,
	evidence,
	repositoryRoot,
	calibrationNotesContent
) => {
	if ( row.migration_state !== 'deferred' ) {
		if ( deferral !== null ) {
			throw new Error(
				`Invalid migration evidence deferral for ${ row.case_id }`
			);
		}
		return;
	}

	assertExactKeys(
		deferral,
		isPlainObject( deferral ) &&
			Object.hasOwn( deferral, 'unlock_satisfactions' )
			? DEFERRAL_KEYS_WITH_UNLOCK_SATISFACTIONS
			: DEFERRAL_KEYS,
		'evidence deferral'
	);
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
	assertUnlockSatisfactions(
		deferral,
		evidence,
		repositoryRoot,
		calibrationNotesContent
	);

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
	{ row, metadata, repositoryRoot, calibrationNotesContent } = {}
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

	if ( evidence.schema_version !== 2 ) {
		throw new Error(
			'Invalid migration evidence schema_version; expected 2'
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
		evidence.verified_at_commit,
		[ 'verified', 'closed' ].includes( row.migration_state )
	);
	if ( [ 'verified', 'closed' ].includes( row.migration_state ) ) {
		assertClosureBundleCoverage( row, evidence, repositoryRoot );
	}

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
	assertReviews( evidence.reviews );
	assertClosures( evidence.closures, row, evidence );
	assertKnownGaps( evidence.known_gaps, row );
	assertDeferral(
		evidence.deferral,
		row,
		evidence,
		repositoryRoot,
		calibrationNotesContent
	);

	return evidence;
};

const parseHistoricalEvidence = ( content, label ) => {
	try {
		return JSON.parse( content );
	} catch ( error ) {
		throw new Error( `Invalid migration evidence JSON at ${ label }`, {
			cause: error,
		} );
	}
};

const evidenceWithoutUnlockSatisfactions = ( evidence ) => {
	const comparableEvidence = structuredClone( evidence );

	if ( isPlainObject( comparableEvidence.deferral ) ) {
		delete comparableEvidence.deferral.unlock_satisfactions;
	}

	return comparableEvidence;
};

const unlockSatisfactions = ( evidence ) =>
	evidence.deferral.unlock_satisfactions ?? [];

export const validateDeferredContractReopens = (
	previousDeferredRows,
	transitions,
	{
		metadata,
		repositoryRoot,
		currentDeferredRows = [],
		loadPreviousEvidence,
		loadCurrentEvidence,
		loadPreviousCalibrationNotes,
		calibrationNotesContent,
	} = {}
) => {
	if (
		previousDeferredRows.length === 0 &&
		currentDeferredRows.length === 0
	) {
		return;
	}

	const currentDeferredRowsByContractId = new Map(
		currentDeferredRows.map( ( row ) => [ row.case_id, row ] )
	);
	const previousRowsByEvidencePath = new Map();
	const legacyUpgradeEvidencePaths = new Set();
	for ( const previousRow of previousDeferredRows ) {
		const currentDeferredRow = currentDeferredRowsByContractId.get(
			previousRow.case_id
		);

		if ( previousRow.evidence_path === 'none' ) {
			if ( ! currentDeferredRow ) {
				throw new Error(
					`Deferred contract reopening rejected for ${ previousRow.case_id }; an exact previous evidence_path is required`
				);
			}

			if ( currentDeferredRow.evidence_path === 'none' ) {
				continue;
			}

			legacyUpgradeEvidencePaths.add(
				currentDeferredRow.evidence_path
			);
			continue;
		}

		if (
			currentDeferredRow &&
			currentDeferredRow.evidence_path !== previousRow.evidence_path
		) {
			throw new Error(
				`Deferred contract history rejected for ${ previousRow.case_id }; deferred evidence_path must remain unchanged`
			);
		}

		const evidencePath = previousRow.evidence_path;
		const groupedRows =
			previousRowsByEvidencePath.get( evidencePath ) ?? [];

		groupedRows.push( previousRow );
		previousRowsByEvidencePath.set( evidencePath, groupedRows );
	}

	const currentRowsByEvidencePath = new Map();
	for ( const currentRow of currentDeferredRows ) {
		const groupedRows =
			currentRowsByEvidencePath.get( currentRow.evidence_path ) ?? [];

		groupedRows.push( currentRow );
		currentRowsByEvidencePath.set( currentRow.evidence_path, groupedRows );
	}

	for ( const [ evidencePath, groupedRows ] of currentRowsByEvidencePath ) {
		if ( previousRowsByEvidencePath.has( evidencePath ) ) {
			continue;
		}

		const currentEvidence = parseHistoricalEvidence(
			loadCurrentEvidence( evidencePath ),
			`the current tree:${ evidencePath }`
		);
		for ( const currentRow of groupedRows ) {
			validateMigrationEvidence( currentEvidence, {
				row: currentRow,
				metadata,
				repositoryRoot,
				calibrationNotesContent,
			} );
		}
		if ( unlockSatisfactions( currentEvidence ).length > 0 ) {
			const reason = legacyUpgradeEvidencePaths.has( evidencePath )
				? 'a legacy deferred evidence packet cannot introduce unlock satisfactions'
				: 'a newly introduced deferred evidence packet cannot contain unlock satisfactions';

			throw new Error(
				`Deferred contract history rejected at ${ evidencePath }; ${ reason }`
			);
		}
	}

	let previousCalibrationNotesContent;
	for ( const [ evidencePath, groupedRows ] of previousRowsByEvidencePath ) {
		if ( ! isRepositoryRelativePath( evidencePath ) ) {
			throw new Error(
				`Deferred contract reopening requires an exact previous evidence_path: ${ evidencePath }`
			);
		}

		const previousEvidence = parseHistoricalEvidence(
			loadPreviousEvidence( evidencePath ),
			`the comparison commit:${ evidencePath }`
		);
		const currentEvidence = parseHistoricalEvidence(
			loadCurrentEvidence( evidencePath ),
			`the current tree:${ evidencePath }`
		);
		const groupedTransitions = transitions.filter(
			( { previousRow } ) => previousRow.evidence_path === evidencePath
		);

		if ( groupedTransitions.length > 0 ) {
			previousCalibrationNotesContent ??= loadPreviousCalibrationNotes();
			for ( const previousRow of groupedRows ) {
				validateMigrationEvidence( previousEvidence, {
					row: previousRow,
					metadata,
					repositoryRoot,
					calibrationNotesContent: previousCalibrationNotesContent,
				} );
			}
			validateMigrationEvidence( currentEvidence, {
				row: groupedRows[ 0 ],
				metadata,
				repositoryRoot,
				calibrationNotesContent,
			} );
		}

		if (
			groupedTransitions.length > 0 &&
			JSON.stringify(
				evidenceWithoutUnlockSatisfactions( currentEvidence )
			) !==
				JSON.stringify(
					evidenceWithoutUnlockSatisfactions( previousEvidence )
				)
		) {
			throw new Error(
				`Deferred contract reopening rejected at ${ evidencePath }; previous deferral packet content must remain unchanged`
			);
		}

		const previousSatisfactions = unlockSatisfactions( previousEvidence );
		const currentSatisfactions = unlockSatisfactions( currentEvidence );
		if (
			currentSatisfactions.length < previousSatisfactions.length ||
			previousSatisfactions.some(
				( satisfaction, index ) =>
					JSON.stringify( satisfaction ) !==
					JSON.stringify( currentSatisfactions[ index ] )
			)
		) {
			throw new Error(
				`Deferred contract reopening rejected at ${ evidencePath }; prior unlock satisfactions must remain unchanged`
			);
		}

		const addedSatisfactions = currentSatisfactions.slice(
			previousSatisfactions.length
		);
		const reopenedContractIds = new Set(
			groupedTransitions.map( ( { previousRow } ) => previousRow.case_id )
		);
		const extraSatisfaction = addedSatisfactions.find(
			( satisfaction ) =>
				! reopenedContractIds.has( satisfaction.contract_id )
		);
		if ( extraSatisfaction ) {
			throw new Error(
				`Deferred contract reopening rejected at ${ evidencePath }; unlock satisfaction added for row not reopened in this ledger change: ${ extraSatisfaction.contract_id }`
			);
		}

		if ( addedSatisfactions.length !== reopenedContractIds.size ) {
			throw new Error(
				`Deferred contract reopening rejected at ${ evidencePath }; exactly one new unlock satisfaction is required for each reopened row`
			);
		}

		for ( const { previousRow } of groupedTransitions ) {
			const matchingSatisfactions = addedSatisfactions.filter(
				( satisfaction ) =>
					satisfaction.contract_id === previousRow.case_id
			);
			if ( matchingSatisfactions.length !== 1 ) {
				throw new Error(
					`Deferred contract reopening rejected for ${ previousRow.case_id }; exactly one new unlock satisfaction is required`
				);
			}
			if (
				matchingSatisfactions[ 0 ].unlock_decision !==
				previousEvidence.deferral.unlock_decision
			) {
				throw new Error(
					`Deferred contract reopening rejected for ${ previousRow.case_id }; satisfaction must reproduce the exact prior unlock_decision`
				);
			}
		}
	}
};
