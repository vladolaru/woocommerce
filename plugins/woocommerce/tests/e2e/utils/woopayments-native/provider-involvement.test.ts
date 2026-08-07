import { expect, test } from '@playwright/test';

import { testInvolvesProvider } from '../../fixtures/woopayments-native';

test( 'provider involvement is keyed to the provider and transition tags', () => {
	expect( testInvolvesProvider( { tags: [ '@woopayments-native' ] } ) ).toBe(
		false
	);
	expect( testInvolvesProvider( { tags: [] } ) ).toBe( false );
	expect(
		testInvolvesProvider( {
			tags: [ '@woopayments-native', '@woopayments-provider' ],
		} )
	).toBe( true );
	expect(
		testInvolvesProvider( {
			tags: [ '@woopayments-native', '@woopayments-transition' ],
		} )
	).toBe( true );
	expect( testInvolvesProvider( { tags: [ '@woopayments-pr' ] } ) ).toBe(
		false
	);
} );
