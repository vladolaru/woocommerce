import { expect, test } from '@playwright/test';

import { THREE_DS_2_CARD } from '../test-cards';
import {
	getSavedVisaDisplayCopy,
	parseSavedMethodSetupIntentExchange,
} from './saved-method-authentication';

test( 'matches native saved-Visa copy in My Account and Classic checkout', () => {
	expect( getSavedVisaDisplayCopy( THREE_DS_2_CARD ) ).toEqual( {
		method: 'Visa ending in 3220',
		expires: '04/45',
		classicAccessibleName: 'Visa ending in 3220 (expires 04/45)',
	} );
} );

test( 'reduces a SetupIntent exchange to the exact authentication evidence', () => {
	expect(
		parseSavedMethodSetupIntentExchange( 200, {
			success: true,
			data: {
				id: 'seti_exact',
				status: 'requires_action',
				client_secret: 'seti_exact_secret_private',
			},
		} )
	).toEqual( {
		httpStatus: 200,
		setupIntentId: 'seti_exact',
		setupIntentStatus: 'requires_action',
		errorMessage: '',
	} );
} );

test( 'reads a SetupIntent failure message and ignores unrelated fields', () => {
	expect(
		parseSavedMethodSetupIntentExchange( 502, {
			success: false,
			data: {
				error: {
					message: 'Authentication failed.',
					code: 'private-provider-code',
				},
			},
		} )
	).toEqual( {
		httpStatus: 502,
		setupIntentId: '',
		setupIntentStatus: '',
		errorMessage: 'Authentication failed.',
	} );
} );
