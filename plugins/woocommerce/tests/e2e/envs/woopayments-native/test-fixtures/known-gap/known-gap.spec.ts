import { test } from '@playwright/test';

import { expectKnownWooPaymentsGap } from '../../../../utils/woopayments-native/known-gap';

const fingerprint = {
	errorName: 'Error',
	messagePattern:
		/^Native refunds endpoint returned code native_refund_not_supported\.$/,
};

test.afterEach( () => {
	if ( process.env.KNOWN_GAP_CASE === 'cleanup' ) {
		throw new Error( 'Exact cleanup failed.' );
	}
} );

test( 'known gap fixture', async () => {
	const testInfo = test.info();

	await expectKnownWooPaymentsGap(
		testInfo,
		{
			id: 'WPNATIVE-GAP-0001',
			owner: 'refunds',
			reference: 'local:fixture-gap',
			fingerprint,
		},
		async () => {
			if ( process.env.KNOWN_GAP_CASE === 'pass' ) {
				return;
			}
			if (
				process.env.KNOWN_GAP_CASE === 'retry' &&
				testInfo.retry === 0
			) {
				throw new Error( 'Locator timed out.' );
			}
			if ( process.env.KNOWN_GAP_CASE === 'wrong' ) {
				throw new Error( 'Locator timed out.' );
			}
			throw new Error(
				'Native refunds endpoint returned code native_refund_not_supported.'
			);
		}
	);
} );
