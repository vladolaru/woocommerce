import { readProviderCardEvidence } from '../../../utils/woopayments-native/provider-card-evidence';
import {
	withCapturedCardTestingProtectionState,
	type CardTestingProtectionScope,
} from '../../../utils/woopayments-native/drivers/card-testing-protection';
import { completeClassicCardCheckout } from '../../../utils/woopayments-native/drivers/classic-card-checkout';
import {
	defineCardPaymentScenario,
	registerCardPaymentScenario,
	type CardPaymentRuntimeAdapter,
} from '../scenarios/card-payment';

const definition = defineCardPaymentScenario( {
	contractId:
		'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:53::Successful purchase › Carding protection true › using a basic card',
	title: 'Successful purchase › Carding protection true › using a basic card',
	protection: true,
	card: 'basic-card',
	price: '10.99',
	checkout: {
		kind: 'classic',
		path: 'classic-checkout/',
	},
} );

const adapter: CardPaymentRuntimeAdapter< CardTestingProtectionScope > = {
	withState: ( session, runId, callback ) =>
		withCapturedCardTestingProtectionState( session, runId, callback ),
	completeCheckout: async (
		session,
		page,
		product,
		runId,
		_definition,
		scope
	) => {
		await scope.registerFreshContext( page );
		return {
			kind: 'classic',
			evidence: await completeClassicCardCheckout(
				session,
				page,
				product,
				runId,
				scope
			),
		};
	},
	readCardEvidence: ( session, payment ) =>
		readProviderCardEvidence( session.adminApi, payment ),
};

registerCardPaymentScenario( definition, adapter );
