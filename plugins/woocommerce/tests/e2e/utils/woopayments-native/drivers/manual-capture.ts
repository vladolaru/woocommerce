import { expect, type Page } from '@playwright/test';

import { waitForPaymentState } from '../provider-evidence';
import type { PaymentEvidence } from '../record-evidence';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';

function assertPaymentEvidenceField< Key extends keyof PaymentEvidence >(
	evidence: PaymentEvidence,
	field: Key,
	expected: PaymentEvidence[ Key ]
): void {
	if ( evidence[ field ] !== expected ) {
		throw new Error(
			`Manual capture evidence mismatch for ${ field }: expected ${ String(
				expected
			) }, received ${ String( evidence[ field ] ) }.`
		);
	}
}

function assertExactCapturedPaymentEvidence(
	original: PaymentEvidence,
	captured: PaymentEvidence
): void {
	for ( const field of [
		'runId',
		'orderId',
		'orderKey',
		'intentId',
		'chargeId',
		'paymentMethodId',
		'amountMinor',
		'currency',
	] as const ) {
		assertPaymentEvidenceField( captured, field, original[ field ] );
	}

	assertPaymentEvidenceField( captured, 'orderStatus', 'processing' );
	assertPaymentEvidenceField( captured, 'providerStatus', 'succeeded' );
	assertPaymentEvidenceField( captured, 'chargeStatus', 'succeeded' );
	assertPaymentEvidenceField( captured, 'chargeCaptured', true );
	assertPaymentEvidenceField( captured, 'occurrenceCount', 1 );
	assertPaymentEvidenceField( captured, 'captureOccurrenceCount', 1 );
}

async function getManualCaptureSetting(
	session: ProviderWriteSession
): Promise< boolean > {
	const response = await session.adminApi.get(
		'/wp-json/wc/v3/payments/settings'
	);
	if ( response.status() !== 200 ) {
		throw new Error(
			`Unable to read manual capture setting: HTTP ${ response.status() }.`
		);
	}
	const settings = ( await response.json() ) as {
		is_manual_capture_enabled?: unknown;
	};
	if ( typeof settings.is_manual_capture_enabled !== 'boolean' ) {
		throw new Error( 'Manual capture setting response is not a boolean.' );
	}
	return settings.is_manual_capture_enabled;
}

async function setManualCaptureSetting(
	session: ProviderWriteSession,
	value: boolean
): Promise< void > {
	const response = await session.performWrite( () =>
		session.adminApi.post( '/wp-json/wc/v3/payments/settings', {
			data: { is_manual_capture_enabled: value },
		} )
	);
	if ( response.status() !== 200 ) {
		throw new Error(
			`Unable to update manual capture setting: HTTP ${ response.status() }.`
		);
	}
}

export async function withCapturedManualCaptureSetting(
	session: ProviderWriteSession,
	callback: () => Promise< void >
): Promise< void > {
	await session.withProviderWriteLocks(
		{
			featureSetting: 'manual-capture',
			recordEvent: 'merchant-manual-capture',
		},
		async () => {
			session.requireApprovedProviderFixture( 'manual-capture-setting' );
			const settingLock = session.getActiveFeatureSettingLock();
			if ( ! settingLock ) {
				throw new Error(
					'Manual capture requires an owned feature-setting lock.'
				);
			}

			try {
				await settingLock.restoreFromJournalIfOwned(
					async ( originalValue ) => {
						await setManualCaptureSetting( session, originalValue );
					}
				);
			} catch ( error ) {
				throw new ResourceQuarantineRequiredError(
					'Manual capture stale-journal recovery failed.',
					'restoration-failed',
					error
				);
			}
			const original = await getManualCaptureSetting( session );
			await settingLock.writeRestorationJournal( original );
			let mutationMayHaveApplied = false;
			let primaryError: unknown;
			const teardownErrors: Error[] = [];

			try {
				mutationMayHaveApplied = true;
				await setManualCaptureSetting( session, true );
				await callback();
			} catch ( error ) {
				primaryError = error;
			}

			if ( mutationMayHaveApplied ) {
				try {
					const restored =
						await settingLock.restoreFromJournalIfOwned(
							async ( originalValue ) => {
								await setManualCaptureSetting(
									session,
									originalValue
								);
							}
						);
					if ( ! restored ) {
						teardownErrors.push(
							new Error(
								'Manual capture setting was not restored because the restoration journal or lock ownership was lost.'
							)
						);
					}
				} catch ( error ) {
					teardownErrors.push(
						error instanceof Error
							? error
							: new Error( String( error ) )
					);
				}
			}

			if ( primaryError !== undefined ) {
				for ( const teardownError of teardownErrors ) {
					console.error(
						'WooPayments pilot teardown failed after the primary test failure:',
						teardownError
					);
				}
				if ( teardownErrors.length > 0 ) {
					throw new ResourceQuarantineRequiredError(
						'Manual capture restoration failed after the primary pilot failure.',
						'restoration-failed',
						primaryError
					);
				}
				throw primaryError;
			}
			if ( teardownErrors.length > 0 ) {
				throw new ResourceQuarantineRequiredError(
					'Manual capture restoration failed.',
					'restoration-failed'
				);
			}
		}
	);
}

export async function captureExactOrder(
	session: ProviderWriteSession,
	page: Page,
	evidence: PaymentEvidence
): Promise< PaymentEvidence > {
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'manual-capture-action' );
	await session.logInAsAdmin( page );
	await page.goto( 'wp-admin/admin.php?page=wc-orders' );
	await page
		.getByRole( 'searchbox', { name: /search orders/i } )
		.fill( evidence.orderId.toString() );
	await page
		.getByRole( 'link', {
			name: new RegExp( evidence.orderId.toString() ),
		} )
		.click();
	await page.locator( 'select[name="wc_order_action"]' ).selectOption( {
		label: 'Capture charge',
		value: 'capture_charge',
	} );
	return session.withProviderSubmissionJournal(
		'manual-capture',
		async () => {
			await session.performWrite( () =>
				page
					.locator( '#actions' )
					.getByRole( 'button', { name: /^Apply\b/ } )
					.click()
			);
			const captured = await waitForPaymentState(
				session.adminApi,
				evidence,
				'succeeded',
				Date.now() + 30_000
			);
			assertExactCapturedPaymentEvidence( evidence, captured );
			return captured;
		}
	);
}

export async function expectCapturedOrderState(
	session: ProviderWriteSession,
	page: Page,
	evidence: PaymentEvidence
): Promise< void > {
	const providerReference =
		session.runtime === 'native'
			? page
					.getByRole( 'link', {
						name: evidence.intentId,
						exact: true,
					} )
					.first()
			: page.getByText( new RegExp( evidence.chargeId ) ).first();
	await expect( providerReference ).toBeVisible();
	await expect(
		page.getByText( /successfully captured.*WooPayments/i )
	).toBeVisible();
	await expect( page.locator( '#order_status' ) ).toHaveValue(
		'wc-processing'
	);
}
