import { completeCardCheckout } from '../../../utils/woopayments-native/drivers/checkout';
import {
	defineCardPaymentScenario,
	registerCardPaymentScenario,
	type CardPaymentRuntimeAdapter,
} from '../scenarios/card-payment';

const definition = defineCardPaymentScenario( {
	contractId:
		'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:53::Successful purchase › Carding protection false › using a basic card',
	title: 'Successful purchase › Carding protection false › using a basic card',
	protection: false,
	card: 'basic-card',
	price: '10.99',
	checkout: {
		kind: 'blocks',
		path: 'checkout/',
	},
} );

const adapter: CardPaymentRuntimeAdapter = {
	withState: ( session, _runId, callback ) =>
		session.withProviderWriteLocks(
			{ recordEvent: 'shopper-card-payment' },
			() => callback( undefined )
		),
	completeCheckout: async ( session, page, product, runId ) => ( {
		kind: 'blocks',
		orderId: await completeCardCheckout( session, page, product, runId ),
	} ),
};

registerCardPaymentScenario( definition, adapter );
