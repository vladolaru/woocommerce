/**
 * Internal dependencies
 */
import { getPaymentMethodDefinition } from '../payment-method-definitions';

// The settings icons follow client 11.1.0's `get_settings_icon_url()` definitions, using the copies core ships.
const EXPECTED_SETTINGS_ICONS: Record< string, string > = {
	card: 'images/payment-methods/generic-card-black.svg',
	alipay: 'images/payment-methods/alipay-logo.svg',
	au_becs_debit: 'images/payment-methods/bank-debit.svg',
	bancontact: 'images/payment-methods/bancontact.svg',
	eps: 'images/payment-methods/eps.svg',
	grabpay: 'images/payment-methods/grabpay.svg',
	ideal: 'images/payment-methods/ideal-wero.svg',
	multibanco: 'images/payment-methods/multibanco.svg',
	p24: 'images/payment-methods/p24.svg',
	sepa_debit: 'images/payment-methods/sepa-debit.svg',
	wechat_pay: 'images/payment-methods/wechat-pay.svg',
	affirm: 'images/payment-methods/affirm-badge.svg',
	klarna: 'images/payment-methods/klarna.svg',
};

describe( 'WooPayments payment method definitions', () => {
	it.each( Object.entries( EXPECTED_SETTINGS_ICONS ) )(
		'uses the client settings icon for %s',
		( methodId, iconPath ) => {
			expect( getPaymentMethodDefinition( methodId )?.iconUrl ).toMatch(
				new RegExp( `${ iconPath.replace( '.', '\\.' ) }$` )
			);
		}
	);

	it.each( [
		[ 'US', 'images/payment-methods/afterpay-cashapp-badge.svg' ],
		[ 'GB', 'images/payment-methods/clearpay.svg' ],
		[ 'AU', 'images/payment-methods/afterpay-logo.svg' ],
	] )(
		'uses the account-country Afterpay icon for %s',
		( country, iconPath ) => {
			expect(
				getPaymentMethodDefinition( 'afterpay_clearpay', country )
					?.iconUrl
			).toMatch( new RegExp( `${ iconPath.replace( '.', '\\.' ) }$` ) );
		}
	);
} );
