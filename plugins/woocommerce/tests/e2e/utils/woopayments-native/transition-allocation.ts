import { readFileSync } from 'node:fs';
import { join, resolve as resolvePath } from 'node:path';

export interface TransitionAllocation {
	base_url: string;
	store_id: string;
	seed_hash: string;
	plugin_version: string;
	teardown_token: string;
	run_id: string;
	workspace: string;
	allocation_path: string;
}

const TRANSITION_ALLOCATION_FIELDS: ( keyof TransitionAllocation )[] = [
	'base_url',
	'store_id',
	'seed_hash',
	'plugin_version',
	'teardown_token',
	'run_id',
	'workspace',
	'allocation_path',
];

function parseTransitionAllocation( value: string ): TransitionAllocation {
	let parsed: unknown;
	try {
		parsed = JSON.parse( value );
	} catch ( error ) {
		throw new Error(
			'E2E_TRANSITION_ALLOCATION must contain the emitted allocation JSON.',
			{ cause: error }
		);
	}
	if ( typeof parsed !== 'object' || parsed === null ) {
		throw new Error(
			'E2E_TRANSITION_ALLOCATION must contain a JSON object.'
		);
	}

	const allocation = parsed as Record< string, unknown >;
	for ( const field of TRANSITION_ALLOCATION_FIELDS ) {
		if (
			typeof allocation[ field ] !== 'string' ||
			! allocation[ field ].trim()
		) {
			throw new Error(
				`Transition allocation requires a non-empty ${ field }.`
			);
		}
	}
	return allocation as unknown as TransitionAllocation;
}

export function assertTransitionAllocation(
	value: string,
	expected: {
		baseUrl: string;
		storeId: string;
		tempRoot: string;
		runId: string;
	}
): TransitionAllocation {
	const emitted = parseTransitionAllocation( value );
	if (
		! /^[A-Za-z0-9][A-Za-z0-9._-]*$/.test( emitted.run_id ) ||
		emitted.run_id !== expected.runId
	) {
		throw new Error( 'Transition allocation contains an invalid run ID.' );
	}

	const expectedWorkspace = resolvePath(
		expected.tempRoot,
		`woopayments-native-transition-${ expected.runId }`
	);
	if (
		resolvePath( emitted.workspace ) !== expectedWorkspace ||
		resolvePath( emitted.allocation_path ) !==
			join( expectedWorkspace, 'allocation.json' )
	) {
		throw new Error(
			'Transition allocation does not identify its exact run-scoped TMPDIR workspace.'
		);
	}
	if (
		emitted.base_url.replace( /\/+$/, '' ) !==
			expected.baseUrl.replace( /\/+$/, '' ) ||
		emitted.store_id !== expected.storeId
	) {
		throw new Error(
			'Transition allocation does not match the exact Playwright base URL and store ID.'
		);
	}

	let transitionUrl: URL;
	try {
		transitionUrl = new URL( emitted.base_url );
	} catch ( error ) {
		throw new Error( 'Transition allocation has an invalid base URL.', {
			cause: error,
		} );
	}
	if (
		! [ 'http:', 'https:' ].includes( transitionUrl.protocol ) ||
		[ '8082', '8889' ].includes( transitionUrl.port )
	) {
		throw new Error(
			'Transition allocation cannot use an invalid URL or either standing developer store.'
		);
	}
	if (
		! /^[a-f0-9]{64}$/.test( emitted.seed_hash ) ||
		! /^[a-f0-9]{64}$/.test( emitted.teardown_token )
	) {
		throw new Error(
			'Transition allocation requires exact seed and teardown identities.'
		);
	}

	let saved: TransitionAllocation;
	try {
		saved = parseTransitionAllocation(
			readFileSync( emitted.allocation_path, 'utf8' )
		);
	} catch ( error ) {
		throw new Error(
			'Transition allocation cannot be verified against its saved allocation file.',
			{ cause: error }
		);
	}
	for ( const field of TRANSITION_ALLOCATION_FIELDS ) {
		if ( emitted[ field ] !== saved[ field ] ) {
			throw new Error(
				`Transition allocation ${ field } does not match its saved identity.`
			);
		}
	}

	return emitted;
}
