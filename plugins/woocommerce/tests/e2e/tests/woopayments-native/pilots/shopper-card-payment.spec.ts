import { completeCardCheckout } from '../../../utils/woopayments-native/drivers/checkout';
import { registerCardPaymentScenario } from '../scenarios/card-payment';

registerCardPaymentScenario( { completeCheckout: completeCardCheckout } );
