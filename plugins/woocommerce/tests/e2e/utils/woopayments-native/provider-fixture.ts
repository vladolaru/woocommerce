import type { WooPaymentsRuntime } from './runtime-readiness';

export interface ProviderFixtureContext {
	runtime: WooPaymentsRuntime;
	storeId: string;
	siteUrl: string;
	wpcomBlogId: number;
	accountId: string;
	accountAlias: string;
	isCI: boolean;
}

interface ProviderFixtureApproval {
	schema_version: 1;
	approval_id: string;
	execution_scope: 'local' | 'ci';
	runtime: WooPaymentsRuntime;
	store_id: string;
	site_url: string;
	wpcom_blog_id: number;
	account_id: string;
	account_alias: string;
	test_mode: true;
	capabilities: string[];
}

/**
 * The exact approval fields. An unrecognized key is rejected rather than
 * ignored: a misspelled capability list would otherwise parse cleanly and fail
 * only once a provider run reached the capability it was meant to approve.
 */
const APPROVAL_KEYS = new Set( [
	'schema_version',
	'approval_id',
	'execution_scope',
	'runtime',
	'store_id',
	'site_url',
	'wpcom_blog_id',
	'account_id',
	'account_alias',
	'test_mode',
	'capabilities',
] );

function normalizedUrl( value: string ): string {
	return value.replace( /\/+$/, '' );
}

function parseApproval( raw: string | undefined ): ProviderFixtureApproval {
	if ( ! raw ) {
		throw new Error(
			'E2E_WOOPAYMENTS_PROVIDER_FIXTURE is required before provider work.'
		);
	}

	let parsed: unknown;
	try {
		parsed = JSON.parse( raw );
	} catch ( error ) {
		throw new Error(
			'E2E_WOOPAYMENTS_PROVIDER_FIXTURE must contain valid JSON.',
			{ cause: error }
		);
	}

	if ( ! parsed || typeof parsed !== 'object' || Array.isArray( parsed ) ) {
		throw new Error(
			'E2E_WOOPAYMENTS_PROVIDER_FIXTURE must contain a JSON object.'
		);
	}

	const approval = parsed as Record< string, unknown >;
	if ( approval.schema_version !== 1 ) {
		throw new Error(
			'Provider fixture approval requires schema version 1.'
		);
	}
	if (
		typeof approval.approval_id !== 'string' ||
		! approval.approval_id.trim() ||
		approval.approval_id.length > 128
	) {
		throw new Error(
			'Provider fixture approval requires a bounded approval identity.'
		);
	}
	if ( approval.test_mode !== true ) {
		throw new Error(
			'Provider fixture approval is valid only for test mode.'
		);
	}
	if (
		! Array.isArray( approval.capabilities ) ||
		approval.capabilities.length === 0 ||
		approval.capabilities.some(
			( capability ) =>
				typeof capability !== 'string' || ! capability.trim()
		)
	) {
		throw new Error(
			'Provider fixture approval requires a non-empty capability allowlist.'
		);
	}
	if (
		new Set( approval.capabilities ).size !== approval.capabilities.length
	) {
		throw new Error(
			'Provider fixture approval capabilities must be unique.'
		);
	}

	const unknownKeys = Object.keys( approval ).filter(
		( key ) => ! APPROVAL_KEYS.has( key )
	);
	if ( unknownKeys.length > 0 ) {
		throw new Error(
			`Provider fixture approval has unknown field(s): ${ unknownKeys
				.toSorted()
				.join( ', ' ) }.`
		);
	}

	return approval as unknown as ProviderFixtureApproval;
}

export function assertApprovedProviderFixture(
	raw: string | undefined,
	expected: ProviderFixtureContext,
	capability: string
): void {
	const approval = parseApproval( raw );
	const expectedScope = expected.isCI ? 'ci' : 'local';
	const matches =
		approval.execution_scope === expectedScope &&
		approval.runtime === expected.runtime &&
		approval.store_id === expected.storeId &&
		normalizedUrl( approval.site_url ) ===
			normalizedUrl( expected.siteUrl ) &&
		approval.wpcom_blog_id === expected.wpcomBlogId &&
		approval.account_id === expected.accountId &&
		approval.account_alias === expected.accountAlias;

	if ( ! matches ) {
		throw new Error(
			'Provider fixture approval does not match the exact runtime, store, blog, account, alias, or execution scope.'
		);
	}
	if ( ! approval.capabilities.includes( capability ) ) {
		throw new Error(
			`WooPayments provider capability ${ capability } is not approved by fixture ${ approval.approval_id }.`
		);
	}
}
