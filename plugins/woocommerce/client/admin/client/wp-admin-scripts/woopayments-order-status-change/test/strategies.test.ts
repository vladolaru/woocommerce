/**
 * Internal dependencies
 */
import {
	getOrderStatusChangeDecision,
	ORDER_STATUS_CANCELLED,
	ORDER_STATUS_REFUNDED,
} from '../strategies';
import type { WooPaymentsOrderStatusChangeConfig } from '../types';

const createConfig = (
	overrides: Partial< WooPaymentsOrderStatusChangeConfig > = {}
): WooPaymentsOrderStatusChangeConfig => ( {
	order_status: 'wc-processing',
	can_refund: true,
	refund_amount: 25,
	formatted_refund_amount: '$25.00',
	refunded_amount: 0,
	...overrides,
} );

describe( 'getOrderStatusChangeDecision', () => {
	describe( 'when the merchant picks Refunded', () => {
		it( 'does nothing when the order is already refunded', () => {
			const decision = getOrderStatusChangeDecision(
				ORDER_STATUS_REFUNDED,
				createConfig( { order_status: ORDER_STATUS_REFUNDED } )
			);

			expect( decision ).toEqual( { type: 'none' } );
		} );

		it( 'does nothing when the order is already refunded, even with nothing refundable left', () => {
			const decision = getOrderStatusChangeDecision(
				ORDER_STATUS_REFUNDED,
				createConfig( {
					order_status: ORDER_STATUS_REFUNDED,
					can_refund: false,
					refund_amount: 0,
				} )
			);

			expect( decision ).toEqual( { type: 'none' } );
		} );

		it( 'errors when the order cannot be refunded', () => {
			const decision = getOrderStatusChangeDecision(
				ORDER_STATUS_REFUNDED,
				createConfig( { can_refund: false } )
			);

			expect( decision ).toEqual( {
				type: 'error',
				message: 'Order cannot be refunded',
			} );
		} );

		it( 'reports "cannot be refunded" ahead of an invalid amount', () => {
			const decision = getOrderStatusChangeDecision(
				ORDER_STATUS_REFUNDED,
				createConfig( { can_refund: false, refund_amount: 0 } )
			);

			expect( decision ).toEqual( {
				type: 'error',
				message: 'Order cannot be refunded',
			} );
		} );

		it( 'errors when there is nothing left to refund', () => {
			const decision = getOrderStatusChangeDecision(
				ORDER_STATUS_REFUNDED,
				createConfig( { refund_amount: 0 } )
			);

			expect( decision ).toEqual( {
				type: 'error',
				message: 'Invalid refund amount',
			} );
		} );

		it( 'errors when the refundable amount is negative', () => {
			const decision = getOrderStatusChangeDecision(
				ORDER_STATUS_REFUNDED,
				createConfig( { refund_amount: -5 } )
			);

			expect( decision ).toEqual( {
				type: 'error',
				message: 'Invalid refund amount',
			} );
		} );

		it( 'asks for refund confirmation when the order is refundable', () => {
			const decision = getOrderStatusChangeDecision(
				ORDER_STATUS_REFUNDED,
				createConfig( {
					order_status: 'wc-processing',
					refund_amount: 42.5,
					formatted_refund_amount: '$42.50',
					refunded_amount: 7.5,
				} )
			);

			expect( decision ).toEqual( {
				type: 'refund-confirmation',
				previousStatus: 'wc-processing',
				refundAmount: 42.5,
				formattedRefundAmount: '$42.50',
				refundedAmount: 7.5,
			} );
		} );
	} );

	describe( 'when the merchant picks Cancelled', () => {
		it( 'does nothing when the order is already cancelled', () => {
			const decision = getOrderStatusChangeDecision(
				ORDER_STATUS_CANCELLED,
				createConfig( { order_status: ORDER_STATUS_CANCELLED } )
			);

			expect( decision ).toEqual( { type: 'none' } );
		} );

		it( 'asks whether a refund was meant when money is still refundable', () => {
			const decision = getOrderStatusChangeDecision(
				ORDER_STATUS_CANCELLED,
				createConfig( { order_status: 'wc-on-hold' } )
			);

			expect( decision ).toEqual( {
				type: 'cancel-confirmation',
				previousStatus: 'wc-on-hold',
			} );
		} );

		it( 'does nothing when the order cannot be refunded', () => {
			const decision = getOrderStatusChangeDecision(
				ORDER_STATUS_CANCELLED,
				createConfig( { can_refund: false } )
			);

			expect( decision ).toEqual( { type: 'none' } );
		} );

		it( 'does nothing when there is nothing left to refund', () => {
			const decision = getOrderStatusChangeDecision(
				ORDER_STATUS_CANCELLED,
				createConfig( { refund_amount: 0 } )
			);

			expect( decision ).toEqual( { type: 'none' } );
		} );
	} );

	// PHP hands the config over as JSON (`wp_add_inline_script` +
	// `wp_json_encode`), not through `wp_localize_script`, which would
	// stringify every scalar and turn `false` into `""` and `0` into `"0"` —
	// the latter being truthy in JS. These cases pin the decisions to the real
	// types so nothing here can start depending on a coercion again.
	describe( 'against the config exactly as PHP serializes it', () => {
		const parseConfig = (
			json: string
		): WooPaymentsOrderStatusChangeConfig =>
			JSON.parse( json ) as WooPaymentsOrderStatusChangeConfig;

		it( 'refuses a refund on a genuine boolean false', () => {
			const config = parseConfig(
				'{"order_status":"wc-processing","can_refund":false,"refund_amount":25,"formatted_refund_amount":"$25.00","refunded_amount":0}'
			);

			expect( config.can_refund ).toBe( false );
			expect(
				getOrderStatusChangeDecision( ORDER_STATUS_REFUNDED, config )
			).toEqual( {
				type: 'error',
				message: 'Order cannot be refunded',
			} );
		} );

		it( 'refuses a refund on a genuine numeric zero', () => {
			const config = parseConfig(
				'{"order_status":"wc-processing","can_refund":true,"refund_amount":0,"formatted_refund_amount":"$0.00","refunded_amount":25}'
			);

			expect( typeof config.refund_amount ).toBe( 'number' );
			expect(
				getOrderStatusChangeDecision( ORDER_STATUS_REFUNDED, config )
			).toEqual( {
				type: 'error',
				message: 'Invalid refund amount',
			} );
		} );

		it( 'confirms a refund on a genuine positive amount', () => {
			const config = parseConfig(
				'{"order_status":"wc-processing","can_refund":true,"refund_amount":25,"formatted_refund_amount":"$25.00","refunded_amount":0}'
			);

			expect(
				getOrderStatusChangeDecision( ORDER_STATUS_REFUNDED, config )
			).toEqual( {
				type: 'refund-confirmation',
				previousStatus: 'wc-processing',
				refundAmount: 25,
				formattedRefundAmount: '$25.00',
				refundedAmount: 0,
			} );
		} );
	} );

	describe( 'when the merchant picks any other status', () => {
		it.each( [
			'wc-pending',
			'wc-processing',
			'wc-on-hold',
			'wc-completed',
			'wc-failed',
			'wc-checkout-draft',
			'trash',
			'',
		] )( 'does nothing for %s', ( newOrderStatus ) => {
			expect(
				getOrderStatusChangeDecision( newOrderStatus, createConfig() )
			).toEqual( { type: 'none' } );
		} );

		it( 'does nothing for an unprefixed "refunded", which is not a dropdown value', () => {
			expect(
				getOrderStatusChangeDecision( 'refunded', createConfig() )
			).toEqual( { type: 'none' } );
		} );
	} );
} );
