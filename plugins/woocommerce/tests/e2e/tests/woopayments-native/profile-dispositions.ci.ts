import { test } from '@playwright/test';

const PROVIDER_PROFILE_DISPOSITIONS = [
	{
		title: 'Klarna Checkout › shows provider-hosted messaging in the product page @woopayments-provider',
		reason: 'RULE 5: provider-hosted Klarna iframe content requires the connected provider profile.',
	},
	{
		title: 'invalid card input yields accessible field-associated errors before any payment dispatch @woopayments-provider',
		reason: 'RULE 5: provider-owned card field validation and accessibility require the connected provider profile.',
	},
] as const;

test.describe( 'Unavailable external profiles', () => {
	for ( const disposition of PROVIDER_PROFILE_DISPOSITIONS ) {
		test(
			disposition.title,
			{
				annotation: {
					type: 'profile-unavailable',
					description: disposition.reason,
				},
			},
			async () => {
				test.skip( true, disposition.reason );
			}
		);
	}
} );
