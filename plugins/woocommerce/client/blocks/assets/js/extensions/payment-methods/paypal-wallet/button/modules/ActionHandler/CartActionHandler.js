import onApprove from '../OnApproveHandler/onApproveForContinue.js';
import { payerData } from '../Helper/PayerData';
import { PaymentMethods } from '../Helper/CheckoutMethodState';
import ResumeFlowHelper from '../Helper/ResumeFlowHelper';

class CartActionHandler {
	constructor( config, errorHandler ) {
		this.config = config;
		this.errorHandler = errorHandler;
	}

	configuration() {
		const errorHandler = this.errorHandler;
		const createOrder = () => {
			const payer = payerData();
			const bnCode =
				typeof this.config.bn_codes[ this.config.context ] !==
				'undefined'
					? this.config.bn_codes[ this.config.context ]
					: '';
			return fetch( this.config.ajax.create_order.endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
				credentials: 'same-origin',
				body: JSON.stringify( {
					nonce: this.config.ajax.create_order.nonce,
					purchase_units: [],
					payment_method: PaymentMethods.PAYPAL,
					funding_source: window.ppcpFundingSource,
					bn_code: bnCode,
					payer,
					context: this.config.context,
				} ),
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( data ) {
					if ( ! data.success ) {
						errorHandler.clear();
						errorHandler.message( data.data.message );
						throw { type: 'create-order-error' };
					}
					return data.data.id;
				} );
		};

		return {
			createOrder,
			onApprove: onApprove( this, this.errorHandler ),
			onCancel: () => {
				ResumeFlowHelper.reloadButtonsIfRequired(
					this.config.button.wrapper
				);
			},
			onError: ( err ) => {
				if ( ! err || err.type !== 'create-order-error' ) {
					this.errorHandler.genericError();
				}

				ResumeFlowHelper.reloadButtonsIfRequired(
					this.config.button.wrapper
				);
			},
		};
	}
}

export default CartActionHandler;
