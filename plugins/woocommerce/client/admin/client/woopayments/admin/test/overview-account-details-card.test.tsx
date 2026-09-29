/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { AccountDetailsCard } from '../overview/components/account-details-card';
import type {
	WooPaymentsOverviewAccountDetails,
	WooPaymentsOverviewAccountFee,
} from '../overview/types';

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

// Recorded :8889 overview shell `account_details` (2026-09-29), the platform shape the client's `payout-status-wrapper.tsx` reads.
const createAccountDetails = (
	overrides: Partial< WooPaymentsOverviewAccountDetails > = {}
): WooPaymentsOverviewAccountDetails => ( {
	account_status: {
		text: 'Connected',
		background_color: 'green',
	},
	payout_status: {
		text: 'Active',
		background_color: 'green',
		popover: {
			text: "Your next payout will be sent in 2 days based on your daily schedule. Arrival time depends on your bank's processing speed.",
			cta_text: 'Learn more about payouts',
			cta_link:
				'https://woocommerce.com/document/woopayments/payouts/payout-schedule/#new-accounts',
		},
	},
	banner: null,
	...overrides,
} );

// Fee fixtures from client 11.1.0 `components/account-details/account-fees/__tests__/index.test.js`.
const cardFee = (
	discount: Record< string, unknown >[],
	base: Record< string, unknown > = {
		percentage_rate: 0.029,
		fixed_rate: 30,
		currency: 'usd',
	}
): WooPaymentsOverviewAccountFee => ( {
	payment_method: 'card',
	fee: { base, discount },
} );

const renderFees = ( accountFees: WooPaymentsOverviewAccountFee[] ) =>
	render(
		<AccountDetailsCard
			accountDetails={ createAccountDetails() }
			accountFees={ accountFees }
		/>
	);

describe( 'AccountDetailsCard', () => {
	beforeEach( () => {
		( recordEvent as jest.Mock ).mockClear();
	} );

	describe( 'Edit details link', () => {
		it( 'links to the account link with the client source arguments and records the click', async () => {
			render(
				<AccountDetailsCard
					accountDetails={ createAccountDetails() }
					accountLink="https://example.com/wp-admin/admin.php?page=wc-settings&wcpay-login=1&_wpnonce=abc"
				/>
			);

			const link = screen.getByRole( 'link', { name: /Edit details/ } );

			expect( link ).toHaveAttribute(
				'href',
				'https://example.com/wp-admin/admin.php?page=wc-settings&wcpay-login=1&_wpnonce=abc&from=WCPAY_ACCOUNT_DETAILS&source=wcpay-account-details'
			);
			expect( link ).toHaveAttribute( 'target', '_blank' );

			await userEvent.click( link );

			expect( recordEvent ).toHaveBeenCalledWith(
				'wcpay_account_details_link_clicked',
				{
					from: 'WCPAY_ACCOUNT_DETAILS',
					source: 'wcpay-account-details',
				}
			);
		} );

		it( 'hides the link without an account link, like a test-drive account', () => {
			render(
				<AccountDetailsCard
					accountDetails={ createAccountDetails() }
					accountLink=""
				/>
			);

			expect(
				screen.queryByRole( 'link', { name: /Edit details/ } )
			).not.toBeInTheDocument();
		} );
	} );

	describe( 'payout status popover', () => {
		it( 'shows the platform popover text and call to action on demand', async () => {
			render(
				<AccountDetailsCard accountDetails={ createAccountDetails() } />
			);

			expect(
				screen.queryByText( /Your next payout will be sent in 2 days/ )
			).not.toBeInTheDocument();

			await userEvent.click(
				screen.getByRole( 'button', {
					name: 'More information about payout status',
				} )
			);

			expect(
				screen.getByText( /Your next payout will be sent in 2 days/ )
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'link', { name: /Learn more about payouts/ } )
			).toHaveAttribute(
				'href',
				'https://woocommerce.com/document/woopayments/payouts/payout-schedule/#new-accounts'
			);
		} );

		it( 'shows the popover text without a link when the call to action is incomplete', async () => {
			render(
				<AccountDetailsCard
					accountDetails={ createAccountDetails( {
						payout_status: {
							text: 'Paused',
							background_color: 'red',
							popover: {
								text: 'Payouts are paused.',
								cta_text: 'Learn more',
							},
						},
					} ) }
				/>
			);

			await userEvent.click(
				screen.getByRole( 'button', {
					name: 'More information about payout status',
				} )
			);

			expect(
				screen.getByText( 'Payouts are paused.' )
			).toBeInTheDocument();
			expect(
				screen.queryByText( 'Learn more' )
			).not.toBeInTheDocument();
		} );

		it( 'has no popover button when the platform sends no popover', () => {
			render(
				<AccountDetailsCard
					accountDetails={ createAccountDetails( {
						payout_status: {
							text: 'Active',
							background_color: 'green',
						},
					} ) }
				/>
			);

			expect(
				screen.queryByRole( 'button', {
					name: 'More information about payout status',
				} )
			).not.toBeInTheDocument();
		} );
	} );

	describe( 'active discounts', () => {
		it( 'renders nothing without discounted fees', () => {
			renderFees( [] );

			expect(
				screen.queryByRole( 'heading', { name: 'Active discounts' } )
			).not.toBeInTheDocument();
		} );

		it( 'strikes the base fee when the discount entry carries no currency, like the client', () => {
			renderFees( [
				cardFee( [
					{
						end_time: null,
						volume_allowance: null,
						volume_currency: null,
						current_volume: null,
						percentage_rate: 0.029,
						fixed_rate: 30,
					},
				] ),
			] );

			expect(
				screen.getByText( '2.9% + $0.30 per transaction' ).tagName
			).toBe( 'S' );
			expect(
				screen.queryByRole( 'progressbar' )
			).not.toBeInTheDocument();
			expect(
				screen.queryByText( /Discounted base fee expires/ )
			).not.toBeInTheDocument();
		} );

		it( 'renders a custom discounted base fee with its volume allowance', () => {
			renderFees( [
				cardFee( [
					{
						percentage_rate: 0.02,
						fixed_rate: 20,
						volume_allowance: 100000000,
						current_volume: 1234556,
						volume_currency: 'usd',
					},
				] ),
			] );

			expect(
				screen.getByRole( 'heading', { name: 'Active discounts' } )
			).toBeInTheDocument();
			expect(
				screen.getByText( 'Card transactions:' )
			).toBeInTheDocument();
			expect(
				screen.getByText( /United States \(US\) dollar \(USD\)/ )
			).toBeInTheDocument();
			expect(
				screen.getByText( '2.9% + $0.30 per transaction' ).tagName
			).toBe( 'S' );
			expect(
				screen.getByText( /\) 2% \+ \$0\.20 per transaction$/ )
			).toBeInTheDocument();
			expect( screen.getByRole( 'progressbar' ) ).toHaveTextContent(
				'$12,345.56$1,000,000.00'
			);
			expect(
				screen.getByText(
					'Discounted base fee expires after the first $1,000,000.00 of total payment volume.'
				)
			).toBeInTheDocument();
		} );

		it( 'renders a percentage discount with its end date', () => {
			renderFees( [
				cardFee( [
					{
						discount: 0.3,
						end_time: '2025-03-31 12:00:00',
						volume_currency: 'usd',
					},
				] ),
			] );

			expect(
				screen.getByText(
					/2\.03% \+ \$0\.21 per transaction \(30% discount\)/
				)
			).toBeInTheDocument();
			expect(
				screen.queryByRole( 'progressbar' )
			).not.toBeInTheDocument();
			expect(
				screen.getByText(
					'Discounted base fee expires on March 31, 2025.'
				)
			).toBeInTheDocument();
		} );

		it( 'renders both the volume allowance and the end date', () => {
			renderFees( [
				cardFee( [
					{
						discount: 0.3,
						volume_allowance: 2500000,
						current_volume: 1234556,
						end_time: '2025-03-31 12:00:00',
						volume_currency: 'usd',
					},
				] ),
			] );

			expect(
				screen.getByText(
					'Discounted base fee expires after the first $25,000.00 of total payment volume or on March 31, 2025.'
				)
			).toBeInTheDocument();
		} );

		it( 'shows only the first of several stacked discounts', () => {
			renderFees( [
				cardFee( [
					{ discount: 0.2, volume_currency: 'usd' },
					{
						discount: 0.3,
						volume_allowance: 2500000,
						current_volume: 1234556,
						volume_currency: 'usd',
					},
				] ),
			] );

			expect(
				screen.getByText( /\(20% discount\)/ )
			).toBeInTheDocument();
			expect(
				screen.queryByText( /\(30% discount\)/ )
			).not.toBeInTheDocument();
			expect(
				screen.queryByRole( 'progressbar' )
			).not.toBeInTheDocument();
		} );

		it( 'names other payment methods by their title', () => {
			renderFees( [
				{
					...cardFee( [ { discount: 0.3 } ] ),
					payment_method: 'bancontact',
				},
				{
					...cardFee( [ { discount: 0.3 } ] ),
					payment_method: 'card_present',
				},
				{
					...cardFee( [ { discount: 0.3 } ] ),
					payment_method: 'not_a_method',
				},
			] );

			expect(
				screen.getByText( 'Bancontact transactions:' )
			).toBeInTheDocument();
			expect(
				screen.getByText( 'In-person transactions:' )
			).toBeInTheDocument();
			expect(
				screen.getByText( 'Unknown transactions:' )
			).toBeInTheDocument();
		} );
	} );
} );
