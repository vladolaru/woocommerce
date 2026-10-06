/**
 * External dependencies
 */
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import { AccountBalancesCard } from '../overview/components/account-balances-card';
import type { WooPaymentsDepositsOverview } from '../overview/types';
import { saveOption } from '../../settings/data/actions';

const mockCreateSuccessNotice = jest.fn();
const mockCreateErrorNotice = jest.fn();

jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );

	return {
		...actual,
		dispatch: jest.fn( ( storeName ) => {
			if ( storeName === 'core/notices' ) {
				return {
					createSuccessNotice: mockCreateSuccessNotice,
					createErrorNotice: mockCreateErrorNotice,
				};
			}

			return actual.dispatch( storeName );
		} ),
	};
} );

jest.mock( '../../settings/data/actions', () => ( {
	saveOption: jest.fn(),
} ) );

const createOverview = (
	overrides: Partial< WooPaymentsDepositsOverview > = {}
): WooPaymentsDepositsOverview => ( {
	balance: {
		available: [
			{
				amount: 1000,
				currency: 'usd',
			},
		],
		pending: [
			{
				amount: 250,
				currency: 'usd',
			},
		],
		instant: [],
	},
	account: {
		default_currency: 'usd',
	},
	deposit: {
		last_paid: [],
	},
	...overrides,
} );

// The notice text, not its copy in the screen reader announcement region.
const queryOffer = () =>
	screen.queryByText(
		'Get $9.00 via instant payout. Funds are typically in your bank account within 30 mins. Fee: 1.5%.',
		{ selector: '.components-notice__content' }
	);

describe( 'AccountBalancesCard', () => {
	beforeEach( () => {
		mockCreateSuccessNotice.mockClear();
		mockCreateErrorNotice.mockClear();
		Object.defineProperty( window, 'wcSettings', {
			configurable: true,
			value: {
				adminUrl: 'https://example.com/wp-admin/',
			},
		} );
	} );

	// Client 11.1.0 `components/account-balances/index.tsx:47-66`: both balance blocks with placeholder amounts.
	it( 'shows the balance blocks with placeholder amounts while loading', () => {
		render( <AccountBalancesCard isLoading overview={ null } /> );

		const card = within(
			screen.getByRole( 'region', { name: 'Balance' } )
		);

		expect( card.getByText( 'Total balance' ) ).toBeVisible();
		expect( card.getByText( 'Available funds' ) ).toBeVisible();
		expect(
			document.querySelectorAll(
				'.woocommerce-woopayments-overview__placeholder'
			)
		).toHaveLength( 2 );
		expect( card.queryByText( /\$/ ) ).not.toBeInTheDocument();
		// The loading announcement stays for screen readers only.
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Loading balance…'
		);
		expect( screen.getByRole( 'status' ) ).toHaveClass(
			'screen-reader-text'
		);
		expect( screen.getByRole( 'region' ) ).toHaveAttribute(
			'aria-busy',
			'true'
		);
	} );

	// The page raises the client's snackbar (`data/deposits/resolvers.js:68`); the card keeps its frame and prints no server text.
	it( 'keeps only the card frame when the balance read fails', () => {
		render(
			<AccountBalancesCard
				isLoading={ false }
				hasError
				overview={ null }
			/>
		);

		const region = screen.getByRole( 'region', { name: 'Balance' } );

		expect( region ).toHaveAttribute( 'aria-busy', 'false' );
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		expect( region.textContent ).toBe( 'Balance' );
	} );

	it( 'renders balance totals when overview data is available', () => {
		render(
			<AccountBalancesCard
				isLoading={ false }
				overview={ createOverview() }
				selectedCurrency="usd"
				onCurrencyChange={ jest.fn() }
			/>
		);

		expect( screen.getByText( 'Available funds' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Total balance' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Available funds' ) ).toHaveTextContent(
			'$10.00'
		);
		expect( screen.getByLabelText( 'Total balance' ) ).toHaveTextContent(
			'$12.50'
		);
		expect(
			screen.queryByRole( 'combobox', { name: 'Balance currency' } )
		).not.toBeInTheDocument();
	} );

	it( 'lets merchants switch the balance currency', async () => {
		const onCurrencyChange = jest.fn();
		const overview = createOverview( {
			balance: {
				available: [
					{ amount: 1000, currency: 'usd' },
					{ amount: 2500, currency: 'eur' },
				],
				pending: [
					{ amount: 250, currency: 'usd' },
					{ amount: 500, currency: 'eur' },
				],
				instant: [],
			},
		} );
		const { rerender } = render(
			<AccountBalancesCard
				isLoading={ false }
				overview={ overview }
				selectedCurrency="usd"
				onCurrencyChange={ onCurrencyChange }
			/>
		);

		expect(
			screen.getByRole( 'combobox', { name: 'Balance currency' } )
		).toHaveValue( 'usd' );
		expect( screen.getByText( '$12.50' ) ).toBeInTheDocument();

		await userEvent.selectOptions(
			screen.getByRole( 'combobox', { name: 'Balance currency' } ),
			'eur'
		);

		expect( onCurrencyChange ).toHaveBeenCalledWith( 'eur' );

		rerender(
			<AccountBalancesCard
				isLoading={ false }
				overview={ overview }
				selectedCurrency="eur"
				onCurrencyChange={ onCurrencyChange }
			/>
		);

		expect( screen.getByText( '€30.00' ) ).toBeInTheDocument();
		expect( screen.getByText( '€25.00' ) ).toBeInTheDocument();
	} );

	it( 'preserves the reference balance help copy', async () => {
		render(
			<AccountBalancesCard
				isLoading={ false }
				overview={ createOverview() }
				selectedCurrency="usd"
				onCurrencyChange={ jest.fn() }
			/>
		);

		// Client 11.1.0 `balance-tooltip.tsx`: the explanations sit behind help icons, not under the amounts.
		expect(
			screen.queryByText( /combines both pending funds/ )
		).not.toBeInTheDocument();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Total balance tooltip' } )
		);
		expect(
			screen.getByText(
				/combines both pending funds \(transactions under processing\) and available funds \(ready for payout\)\./
			)
		).toHaveTextContent( /^Total balance combines both/ );
		expect(
			screen.getByText(
				'Total balance = Available funds + Pending funds'
			)
		).toBeInTheDocument();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Available funds tooltip' } )
		);
		expect(
			screen.getByText(
				/have completed processing and are ready to be dispatched to your bank account\./
			)
		).toHaveTextContent( /^Available funds have completed processing/ );
		expect(
			screen.getAllByRole( 'link', { name: /Learn more/ } )[ 0 ]
		).toHaveAttribute(
			'href',
			'https://woocommerce.com/document/woopayments/payouts/payout-schedule/'
		);
	} );

	it( 'points a negative balance to the negative balance guide', async () => {
		render(
			<AccountBalancesCard
				isLoading={ false }
				overview={ createOverview( {
					balance: {
						available: [ { amount: -500, currency: 'usd' } ],
						pending: [ { amount: 0, currency: 'usd' } ],
						instant: [],
					},
				} ) }
				selectedCurrency="usd"
				onCurrencyChange={ jest.fn() }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Total balance tooltip' } )
		);
		expect(
			screen.getByRole( 'link', { name: /Discover why/ } )
		).toHaveAttribute(
			'href',
			'https://woocommerce.com/document/woopayments/fees/account-showing-negative-balance/'
		);
	} );

	it( 'opens the instant payout modal and submits the native action', async () => {
		const submitInstantPayout = jest.fn().mockResolvedValue( {
			id: 'po_instant',
			date: 1781740800000,
			type: 'instant',
			amount: 900,
			status: 'in_transit',
			currency: 'usd',
		} );
		render(
			<AccountBalancesCard
				isLoading={ false }
				overview={ createOverview( {
					balance: {
						available: [ { amount: 1000, currency: 'usd' } ],
						pending: [ { amount: 250, currency: 'usd' } ],
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
				} ) }
				selectedCurrency="usd"
				onCurrencyChange={ jest.fn() }
				onInstantPayoutSubmit={ submitInstantPayout }
			/>
		);

		const notice = screen.getByText(
			'Get $9.00 via instant payout. Funds are typically in your bank account within 30 mins. Fee: 1.5%.',
			{ selector: '.components-notice__content' }
		);
		expect(
			notice.closest(
				'.woocommerce-woopayments-overview__instant-payout'
			)
		).not.toHaveAttribute( 'role', 'status' );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Get $9.00 now' } )
		);

		expect(
			screen.getByRole( 'dialog', { name: 'Instant payout' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Balance available for instant payout:' )
		).toBeInTheDocument();
		expect( screen.getByText( '1.5% service fee:' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Net payout amount:' ) ).toBeInTheDocument();

		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Pay out $8.86 now' } )
			);
		} );

		await waitFor( () =>
			expect( submitInstantPayout ).toHaveBeenCalledWith( 'usd' )
		);
		await waitFor( () =>
			expect(
				screen.queryByRole( 'dialog', { name: 'Instant payout' } )
			).not.toBeInTheDocument()
		);
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Instant payout for $9.00 in transit.',
			{
				actions: [
					{
						label: 'View details',
						url: 'https://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fpayouts%2Fdetails&id=po_instant',
					},
				],
			}
		);
		expect( mockCreateErrorNotice ).not.toHaveBeenCalled();
	} );

	describe( 'instant payout while the request runs', () => {
		const instantOverview = () =>
			createOverview( {
				balance: {
					available: [ { amount: 1000, currency: 'usd' } ],
					pending: [ { amount: 250, currency: 'usd' } ],
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
			} );
		const deposit = {
			id: 'po_instant',
			date: 1781740800000,
			type: 'instant',
			amount: 900,
			status: 'in_transit',
			currency: 'usd',
		};

		// Client 11.1.0 `deposits/instant-payouts/index.tsx:66-72`: `( isModalOpen || inProgress )` keeps the dialog while the request runs.
		it( 'keeps the dialog open until the payout request settles', async () => {
			let resolvePayout: ( value: typeof deposit ) => void = () => {};
			const submitInstantPayout = jest.fn(
				() =>
					new Promise< typeof deposit >( ( resolve ) => {
						resolvePayout = resolve;
					} )
			);
			render(
				<AccountBalancesCard
					isLoading={ false }
					overview={ instantOverview() }
					selectedCurrency="usd"
					onCurrencyChange={ jest.fn() }
					onInstantPayoutSubmit={ submitInstantPayout }
				/>
			);

			await userEvent.click(
				screen.getByRole( 'button', { name: 'Get $9.00 now' } )
			);
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Pay out $8.86 now' } )
			);
			await userEvent.keyboard( '{Escape}' );
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Cancel' } )
			);

			const dialog = screen.getByRole( 'dialog', {
				name: 'Instant payout',
			} );
			expect(
				within( dialog ).getByRole( 'button', { name: 'Cancel' } )
			).toHaveAttribute( 'aria-disabled', 'true' );
			expect(
				within( dialog ).getByRole( 'button', {
					name: 'Pay out $8.86 now',
				} )
			).toHaveAttribute( 'aria-disabled', 'true' );

			await act( async () => {
				resolvePayout( deposit );
			} );

			expect(
				screen.queryByRole( 'dialog', { name: 'Instant payout' } )
			).not.toBeInTheDocument();
			expect( submitInstantPayout ).toHaveBeenCalledTimes( 1 );
		} );
	} );

	// Client 11.1.0 `components/account-balances/index.tsx:31-46, 157-222`.
	describe( 'instant payout offer dismissal', () => {
		const renderOffer = ( isDismissed: boolean ) =>
			render(
				<AccountBalancesCard
					isLoading={ false }
					overview={ createOverview( {
						balance: {
							available: [ { amount: 1000, currency: 'usd' } ],
							pending: [ { amount: 250, currency: 'usd' } ],
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
					} ) }
					selectedCurrency="usd"
					onInstantPayoutSubmit={ jest.fn() }
					isInstantDepositNoticeDismissed={ isDismissed }
				/>
			);
		const helpLabel = 'Learn more about instant payouts';

		beforeEach( () => {
			( saveOption as jest.Mock ).mockReset();
		} );

		it( 'hides the offer, stores the dismissal and moves the help beside the button', async () => {
			renderOffer( false );

			expect( queryOffer() ).toBeInTheDocument();
			expect(
				screen.queryByRole( 'button', { name: helpLabel } )
			).not.toBeInTheDocument();

			await userEvent.click(
				screen.getByRole( 'button', { name: 'Close' } )
			);

			expect( queryOffer() ).not.toBeInTheDocument();
			expect( saveOption ).toHaveBeenCalledWith(
				'wcpay_instant_deposit_notice_dismissed',
				true
			);
			expect(
				screen.getByRole( 'button', { name: 'Get $9.00 now' } )
			).toBeInTheDocument();

			await userEvent.click(
				screen.getByRole( 'button', { name: helpLabel } )
			);

			expect(
				screen.getByRole( 'link', { name: /Learn more/ } )
			).toHaveAttribute(
				'href',
				'https://woocommerce.com/document/woopayments/payouts/instant-payouts/'
			);
		} );

		it( 'keeps the offer hidden once it was dismissed', () => {
			renderOffer( true );

			expect( queryOffer() ).not.toBeInTheDocument();
			expect(
				screen.getByRole( 'button', { name: helpLabel } )
			).toBeInTheDocument();
			expect( saveOption ).not.toHaveBeenCalled();
		} );
	} );

	// Client 11.1.0 `components/account-balances/index.tsx:226-251`.
	describe( 'instant payouts unavailable warning', () => {
		const instantBalance = ( amount: number ) => ( {
			amount,
			currency: 'usd',
			fee: 14,
			net: 886,
			fee_percentage: 1.5,
		} );
		const renderCard = (
			previouslyEligible: boolean,
			instant: ReturnType< typeof instantBalance >[]
		) =>
			render(
				<AccountBalancesCard
					isLoading={ false }
					overview={ createOverview( {
						balance: {
							available: [ { amount: 1000, currency: 'usd' } ],
							pending: [ { amount: 250, currency: 'usd' } ],
							instant,
						},
					} ) }
					selectedCurrency="usd"
					instantDepositsPreviouslyEligible={ previouslyEligible }
				/>
			);
		const queryWarning = () =>
			screen.queryByText(
				/Instant payouts are currently unavailable for your account\./,
				{ selector: '.components-notice__content' }
			);

		it.each( [
			[ 'no instant balance', [] ],
			[ 'a zero instant balance', [ instantBalance( 0 ) ] ],
		] )(
			'warns a previously eligible account with %s',
			( _label, instant ) => {
				renderCard( true, instant );

				expect( queryWarning() ).toBeInTheDocument();
				expect(
					screen.getByRole( 'link', {
						name: /Learn about eligibility requirements/,
					} )
				).toHaveAttribute(
					'href',
					'https://woocommerce.com/document/woopayments/payouts/instant-payouts/'
				);
			}
		);

		it( 'does not warn while an instant balance is available', () => {
			renderCard( true, [ instantBalance( 900 ) ] );

			expect( queryWarning() ).not.toBeInTheDocument();
		} );

		it( 'does not warn an account that was never eligible', () => {
			renderCard( false, [] );

			expect( queryWarning() ).not.toBeInTheDocument();
		} );
	} );
} );
