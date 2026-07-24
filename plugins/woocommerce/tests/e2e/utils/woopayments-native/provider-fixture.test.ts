import { expect, test } from '@playwright/test';

import {
	assertApprovedProviderFixture,
	type ProviderFixtureContext,
} from './provider-fixture';

const context: ProviderFixtureContext = {
	runtime: 'native',
	storeId: 'native-blog-4',
	siteUrl: 'http://store8889.localhost:8889',
	wpcomBlogId: 4,
	accountId: 'acct_native',
	accountAlias: 'local-native-blog-4',
	isCI: false,
};

function approval( overrides: Record< string, unknown > = {} ): string {
	return JSON.stringify( {
		schema_version: 1,
		approval_id: 'local-native-pilots-2026-07-24',
		execution_scope: 'local',
		runtime: 'native',
		store_id: 'native-blog-4',
		site_url: 'http://store8889.localhost:8889',
		wpcom_blog_id: 4,
		account_id: 'acct_native',
		account_alias: 'local-native-blog-4',
		test_mode: true,
		capabilities: [ 'product/payment', 'basic-card', 'basic-card-entry' ],
		...overrides,
	} );
}

test( 'accepts an exact test-mode approval for an allowed capability', () => {
	expect( () =>
		assertApprovedProviderFixture( approval(), context, 'basic-card-entry' )
	).not.toThrow();
} );

test( 'rejects a missing or malformed approval before provider work', () => {
	expect( () =>
		assertApprovedProviderFixture( undefined, context, 'basic-card' )
	).toThrow( /E2E_WOOPAYMENTS_PROVIDER_FIXTURE/i );
	expect( () =>
		assertApprovedProviderFixture( '{', context, 'basic-card' )
	).toThrow( /valid JSON/i );
} );

test( 'rejects any runtime, store, blog, account, or scope mismatch', () => {
	const mismatches = [
		{ runtime: 'client' },
		{ store_id: 'other-store' },
		{ site_url: 'http://localhost:8082' },
		{ wpcom_blog_id: 2 },
		{ account_id: 'acct_other' },
		{ account_alias: 'other-alias' },
		{ execution_scope: 'ci' },
	];

	for ( const mismatch of mismatches ) {
		expect( () =>
			assertApprovedProviderFixture(
				approval( mismatch ),
				context,
				'basic-card'
			)
		).toThrow( /does not match/i );
	}
} );

test( 'rejects non-test-mode and unapproved capabilities', () => {
	expect( () =>
		assertApprovedProviderFixture(
			approval( { test_mode: false } ),
			context,
			'basic-card'
		)
	).toThrow( /test mode/i );
	expect( () =>
		assertApprovedProviderFixture(
			approval(),
			context,
			'saved-method-cutover'
		)
	).toThrow( /not approved/i );
} );

test( 'requires a unique, non-empty capability allowlist and approval identity', () => {
	expect( () =>
		assertApprovedProviderFixture(
			approval( { approval_id: '' } ),
			context,
			'basic-card'
		)
	).toThrow( /approval identity/i );
	expect( () =>
		assertApprovedProviderFixture(
			approval( { capabilities: [ 'basic-card', 'basic-card' ] } ),
			context,
			'basic-card'
		)
	).toThrow( /unique/i );
} );
