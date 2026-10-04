/**
 * External dependencies
 */
import { getHistory } from '@woocommerce/navigation';

/**
 * Internal dependencies
 */
import {
	getSettingsPaymentsProviderAdminPath,
	getSettingsPaymentsProviderRouteUrl,
	handleSettingsPaymentsProviderRouteClick,
} from '../utils';
import {
	formatDisputeReasonLabel,
	getErrorMessage,
	getTransactionDetailsRoute,
} from '../money-movement/utils';
import {
	getBalanceCurrencyOptions,
	getInstantBalanceForCurrency,
	getMonthlyAnchorLabel,
	getSelectedBalanceCurrency,
} from '../overview/utils';
import type { WooPaymentsDepositsOverview } from '../overview/types';

const createOverview = (
	overrides: Partial< WooPaymentsDepositsOverview > = {}
): WooPaymentsDepositsOverview => ( {
	balance: {
		available: [
			{ amount: 1000, currency: 'usd' },
			{ amount: 2500, currency: 'eur' },
		],
		pending: [
			{ amount: 250, currency: 'usd' },
			{ amount: 500, currency: 'gbp' },
		],
		instant: [
			{
				amount: 900,
				currency: 'usd',
				fee: 14,
				net: 886,
				fee_percentage: 1.5,
			},
		],
	},
	account: {
		default_currency: 'usd',
	},
	deposit: {
		last_paid: [
			{
				id: 'po_cad',
				date: 1781740800000,
				type: 'deposit',
				amount: 700,
				status: 'paid',
				currency: 'cad',
			},
		],
	},
	...overrides,
} );

describe( 'getSettingsPaymentsProviderRouteUrl', () => {
	beforeEach( () => {
		window.wcSettings = {
			...window.wcSettings,
			adminUrl: 'https://example.com/wp-admin',
		};
	} );

	it( 'builds provider route URLs without query parameters', () => {
		expect(
			getSettingsPaymentsProviderRouteUrl( '/woopayments/payouts' )
		).toBe(
			'https://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fpayouts'
		);
	} );

	it( 'builds express checkout provider route URLs', () => {
		expect(
			getSettingsPaymentsProviderRouteUrl(
				'/woopayments/settings/express-checkout/payment_request'
			)
		).toBe(
			'https://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings%2Fexpress-checkout%2Fpayment_request'
		);
	} );

	it( 'keeps route query parameters outside the encoded path', () => {
		expect(
			getSettingsPaymentsProviderRouteUrl(
				'/woopayments/settings/express-checkout/payment_request?from=settings-payments'
			)
		).toBe(
			'https://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings%2Fexpress-checkout%2Fpayment_request&from=settings-payments'
		);
	} );

	it( 'builds fraud protection settings provider route URLs', () => {
		expect(
			getSettingsPaymentsProviderRouteUrl(
				'/woopayments/settings/fraud-protection?from=woopayments-settings'
			)
		).toBe(
			'https://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings%2Ffraud-protection&from=woopayments-settings'
		);
	} );

	it( 'keeps the settings shell page unique and maps provider pagination to paged', () => {
		const adminPath = getSettingsPaymentsProviderAdminPath(
			'/woopayments/transactions?page=2&pagesize=25&search=Order%20%231520'
		);
		const url = new URL( adminPath, 'https://example.com/wp-admin/' );

		expect( url.pathname ).toBe( '/wp-admin/admin.php' );
		expect( url.searchParams.getAll( 'page' ) ).toEqual( [
			'wc-settings',
		] );
		expect( url.searchParams.get( 'tab' ) ).toBe( 'checkout' );
		expect( url.searchParams.get( 'path' ) ).toBe(
			'/woopayments/transactions'
		);
		expect( url.searchParams.get( 'paged' ) ).toBe( '2' );
		expect( url.searchParams.get( 'pagesize' ) ).toBe( '25' );
		expect( url.searchParams.get( 'search' ) ).toBe( 'Order #1520' );
	} );
} );

describe( 'handleSettingsPaymentsProviderRouteClick', () => {
	it( 'moves a plain click on a settings subpage link through the shell history instead of reloading', () => {
		const push = jest
			.spyOn( getHistory(), 'push' )
			.mockImplementation( () => undefined );
		const link = document.createElement( 'a' );
		const onClick = handleSettingsPaymentsProviderRouteClick(
			'/woopayments/settings/express-checkout/woopay?from=woopayments-settings'
		);
		link.addEventListener( 'click', ( event ) =>
			onClick( event as unknown as Parameters< typeof onClick >[ 0 ] )
		);

		try {
			const plainClick = new window.MouseEvent( 'click', {
				cancelable: true,
			} );
			link.dispatchEvent( plainClick );

			expect( plainClick.defaultPrevented ).toBe( true );
			expect( push ).toHaveBeenCalledWith(
				'admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings%2Fexpress-checkout%2Fwoopay&from=woopayments-settings'
			);

			// Opening the link in a new tab keeps the browser's own handling.
			push.mockClear();
			const newTabClick = new window.MouseEvent( 'click', {
				cancelable: true,
				metaKey: true,
			} );
			link.dispatchEvent( newTabClick );

			expect( newTabClick.defaultPrevented ).toBe( false );
			expect( push ).not.toHaveBeenCalled();
		} finally {
			push.mockRestore();
		}
	} );
} );

describe( 'getTransactionDetailsRoute', () => {
	it( 'uses card reader fee metadata as the transaction detail type', () => {
		expect(
			getTransactionDetailsRoute( {
				id: 'txn_reader_fee_123',
				type: 'charge',
				metadata: {
					charge_type: 'card_reader_fee',
				},
			} )
		).toBe(
			'/woopayments/transactions/details?id=txn_reader_fee_123&transaction_type=card_reader_fee'
		);
	} );
} );

describe( 'formatDisputeReasonLabel', () => {
	it( 'uses the established merchant-facing dispute reason labels', () => {
		expect( formatDisputeReasonLabel( 'fraudulent' ) ).toBe(
			'Transaction unauthorized'
		);
		expect( formatDisputeReasonLabel( 'bank_cannot_process' ) ).toBe(
			'Bank cannot process'
		);
		expect( formatDisputeReasonLabel( 'noncompliant' ) ).toBe(
			'Non-compliant'
		);
		expect( formatDisputeReasonLabel( 'future_provider_reason' ) ).toBe(
			'General'
		);
	} );
} );

describe( 'overview financial summary helpers', () => {
	it( 'extracts unique balance currency options with the default currency first', () => {
		expect( getBalanceCurrencyOptions( createOverview() ) ).toEqual( [
			'usd',
			'cad',
			'eur',
			'gbp',
		] );
	} );

	it( 'does not synthesize the account currency when no overview group uses it', () => {
		expect(
			getBalanceCurrencyOptions(
				createOverview( {
					account: {
						default_currency: 'aud',
					},
				} )
			)
		).toEqual( [ 'cad', 'usd', 'eur', 'gbp' ] );
	} );

	it( 'falls back to the first overview currency when the selected currency is unavailable', () => {
		expect(
			getSelectedBalanceCurrency(
				createOverview( {
					account: {
						default_currency: 'aud',
					},
				} ),
				'nzd'
			)
		).toBe( 'cad' );
		expect( getSelectedBalanceCurrency( createOverview(), 'aud' ) ).toBe(
			'usd'
		);
		expect( getSelectedBalanceCurrency( createOverview(), 'eur' ) ).toBe(
			'eur'
		);
	} );

	it( 'finds instant balances by currency', () => {
		expect(
			getInstantBalanceForCurrency( createOverview(), 'usd' )
		).toMatchObject( {
			amount: 900,
			currency: 'usd',
			fee_percentage: 1.5,
		} );
		expect(
			getInstantBalanceForCurrency( createOverview(), 'eur' )
		).toBeNull();
	} );

	it( 'formats monthly anchor labels', () => {
		expect( getMonthlyAnchorLabel( 1 ) ).toBe( '1st' );
		expect( getMonthlyAnchorLabel( 2 ) ).toBe( '2nd' );
		expect( getMonthlyAnchorLabel( 3 ) ).toBe( '3rd' );
		expect( getMonthlyAnchorLabel( 15 ) ).toBe( '15th' );
		expect( getMonthlyAnchorLabel( 21 ) ).toBe( '21st' );
		expect( getMonthlyAnchorLabel( 31 ) ).toBe( 'last day of every month' );
	} );
} );

describe( 'getErrorMessage', () => {
	// Recorded read-only from :8889 `wc/v3/payments/charges/{unknown id}`: the server escapes the quotes.
	const recordedNotFound = {
		code: 'wcpay_bad_request',
		message:
			'Error: No such charge: &#039;ch_3ZZZZZZZZZZZZZZZZZZZZZZZ&#039;',
		data: { status: 404 },
	};

	it( 'decodes the entities of a REST error message for display as text', () => {
		expect( getErrorMessage( recordedNotFound, 'Fallback.' ) ).toBe(
			"Error: No such charge: 'ch_3ZZZZZZZZZZZZZZZZZZZZZZZ'"
		);
	} );

	it( 'decodes the entities of an Error message', () => {
		expect(
			getErrorMessage(
				new Error( 'Tom &amp; Jerry&#039;s store' ),
				'Fallback.'
			)
		).toBe( "Tom & Jerry's store" );
	} );

	it( 'returns markup-looking text as plain text, never as elements', () => {
		const message = getErrorMessage(
			{ message: '&lt;img src=x onerror=alert(1)&gt;' },
			'Fallback.'
		);

		expect( typeof message ).toBe( 'string' );
		expect( message ).toBe( '<img src=x onerror=alert(1)>' );
	} );

	it( 'falls back when there is no message', () => {
		expect( getErrorMessage( { code: 'x' }, 'Fallback.' ) ).toBe(
			'Fallback.'
		);
	} );
} );
