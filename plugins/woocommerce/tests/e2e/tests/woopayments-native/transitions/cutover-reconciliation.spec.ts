import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { completeCardCheckout } from '../../../utils/woopayments-native/drivers/checkout';
import {
	readHighestOrderId,
	readOrderDeltaAfter,
} from '../../../utils/woopayments-native/drivers/classic-card-authentication';
import {
	advanceOldPluginCutover,
	publicCutoverStatus,
	readCutoverProfile,
	reconcileEphemeralStore,
} from '../../../utils/woopayments-native/drivers/store-transition';
import { readProviderCardEvidence } from '../../../utils/woopayments-native/provider-card-evidence';
import {
	defineCardPaymentScenario,
	publicCardPaymentEvidence,
	runCardPaymentScenario,
} from '../scenarios/card-payment';

const definition = defineCardPaymentScenario( {
	contractId:
		'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:53::Successful purchase › Carding protection false › using a basic card',
	title: 'Successful purchase › Carding protection false › using a basic card',
	protection: false,
	card: 'basic-card',
	price: '10.99',
	checkout: { kind: 'blocks', path: 'checkout/' },
} );

test(
	'one click proves the allocated cutover reconciliation profile contract',
	{
		tag: [
			tags.WOOPAYMENTS_NATIVE,
			tags.WOOPAYMENTS_PROVIDER,
			tags.WOOPAYMENTS_TRANSITION,
		],
	},
	async ( { adminApi, page, pilotRuntime, runId }, testInfo ) => {
		test.setTimeout( 25 * 60_000 );
		const profile = readCutoverProfile( pilotRuntime );
		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'cutover-reconciliation' },
			async () => {
				if ( profile.version === '10.4.0' ) {
					const cutover = await advanceOldPluginCutover(
						pilotRuntime,
						page,
						profile
					);
					await testInfo.attach( 'cutover-plugin-update', {
						body: JSON.stringify(
							{
								profile,
								before: publicCutoverStatus( cutover.before ),
								started: publicCutoverStatus( cutover.started ),
								advanced: publicCutoverStatus(
									cutover.advanced
								),
							},
							null,
							2
						),
						contentType: 'application/json',
					} );
					return;
				}
				const cutover = await reconcileEphemeralStore(
					pilotRuntime,
					page,
					profile
				);
				await testInfo.attach( 'cutover-reconciliation', {
					body: JSON.stringify(
						{
							profile,
							before: publicCutoverStatus( cutover.before ),
							started: publicCutoverStatus( cutover.started ),
							completed: publicCutoverStatus( cutover.completed ),
						},
						null,
						2
					),
					contentType: 'application/json',
				} );
				expect( cutover.before.cutover.plugin_version ).toBe(
					profile.version
				);
				expect( cutover.before.cutover.preflight_failures ).toContain(
					'unsupported_payment_methods_enabled'
				);
				expect( cutover.started.cutover.record?.current_step ).not.toBe(
					'awaiting_merchant_start'
				);
				expect( cutover.completed.cutover.record?.state ).toBe(
					'done'
				);
				expect( cutover.completed.runtime_owner ).toBe( 'native' );
				expect( cutover.completed.cutover.plugin_active ).toBe( false );
				expect( cutover.completed.cutover.network_active ).toBe(
					false
				);
				const expectedInitialMigratorStatus =
					profile.migratorActionId === undefined
						? undefined
						: 'pending';
				const expectedFinalMigratorStatus =
					profile.migratorActionId === undefined
						? undefined
						: 'canceled';
				expect( cutover.before.cutover.migrator_action?.status ).toBe(
					expectedInitialMigratorStatus
				);
				expect(
					cutover.completed.cutover.migrator_action?.status
				).toBe( expectedFinalMigratorStatus );
				await page.context().clearCookies();
				const baselineOrderId =
					await readHighestOrderId( pilotRuntime );
				const payment = await runCardPaymentScenario(
					definition,
					{
						withState: ( _session, _runId, callback ) =>
							callback( undefined ),
						completeCheckout: async (
							session,
							checkoutPage,
							product,
							paymentRunId
						) => ( {
							kind: 'blocks',
							orderId: await completeCardCheckout(
								session,
								checkoutPage,
								product,
								paymentRunId
							),
						} ),
						readCardEvidence: ( session, evidence ) =>
							readProviderCardEvidence(
								session.adminApi,
								evidence
							),
					},
					{ adminApi, page, pilotRuntime, runId }
				);
				const delta = await readOrderDeltaAfter(
					pilotRuntime,
					baselineOrderId
				);
				expect( delta.newOrderIds ).toEqual( [ payment.orderId ] );
				expect( delta.paidOrderIds ).toEqual( [ payment.orderId ] );
				expect( delta.providerLinkedOrderIds ).toEqual( [
					payment.orderId,
				] );
				await testInfo.attach( 'cutover-payment', {
					body: JSON.stringify(
						{
							payment: publicCardPaymentEvidence( payment ),
							orderGraph: {
								newOrderCount: delta.newOrderIds.length,
								paidOrderCount: delta.paidOrderIds.length,
								providerLinkedOrderCount:
									delta.providerLinkedOrderIds.length,
								exactSameOrder:
									delta.newOrderIds[ 0 ] ===
										payment.orderId &&
									delta.paidOrderIds[ 0 ] ===
										payment.orderId &&
									delta.providerLinkedOrderIds[ 0 ] ===
										payment.orderId,
							},
						},
						null,
						2
					),
					contentType: 'application/json',
				} );
			}
		);
	}
);
