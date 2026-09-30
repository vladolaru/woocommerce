/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { recordEvent } from '@woocommerce/tracks';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { WooPaymentsOrderDisputeNotice } from '../order-dispute-notice';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

const mockApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

describe( 'WooPayments order dispute notice', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		jest.setSystemTime( new Date( '2023-10-20T00:00:00Z' ) );
		mockApiFetch.mockReset();
		window.wcSettings = {
			adminUrl: 'http://example.com/wp-admin',
		};
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'consolidates future disputes with their total and earliest deadline', async () => {
		mockApiFetch.mockResolvedValue( {
			disputes: [
				{
					id: 'dp_later',
					status: 'needs_response',
					amount: 1000,
					currency: 'usd',
					evidence_details: { due_by: 1698672000 },
				},
				{
					id: 'dp_earlier',
					status: 'needs_response',
					amount: 1550,
					currency: 'usd',
					evidence_details: { due_by: 1698500219 },
				},
			],
		} );

		render(
			<WooPaymentsOrderDisputeNotice
				chargeId="ch_123"
				onDisableOrderRefund={ jest.fn() }
			/>
		);

		expect(
			await screen.findByText(
				'This order has 2 payment disputes totaling $25.50.'
			)
		).toBeInTheDocument();
		expect(
			screen.getAllByText( /Please respond before October 28, 2023/ )
				.length
		).toBeGreaterThan( 0 );
		expect(
			screen.getByRole( 'link', { name: 'Respond now' } )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions%2Fdetails&id=ch_123'
		);
		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: '/wc/v3/payments/charges/ch_123',
			method: 'GET',
		} );
	} );

	it( 'records the client 11.1.0 notice view and action Tracks events', async () => {
		// Expected properties: client `components/disputed-order-notice/index.js:357-364,422-427`.
		mockApiFetch.mockResolvedValue( {
			disputes: [
				{
					id: 'dp_later',
					status: 'needs_response',
					amount: 1000,
					currency: 'usd',
					evidence_details: { due_by: 1698672000 },
				},
				{
					id: 'dp_earlier',
					status: 'needs_response',
					amount: 1550,
					currency: 'usd',
					evidence_details: { due_by: 1698500219 },
				},
			],
		} );

		render(
			<WooPaymentsOrderDisputeNotice
				chargeId="ch_123"
				onDisableOrderRefund={ jest.fn() }
			/>
		);

		fireEvent.click(
			await screen.findByRole( 'link', { name: 'Respond now' } )
		);
		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_order_dispute_notice_view',
			{
				is_inquiry: false,
				dispute_reason: 'multiple',
				due_by_days: 8,
				dispute_count: 2,
			}
		);
		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_order_dispute_notice_action_click',
			{ due_by_days: 8 }
		);
	} );

	it( 'uses inquiry wording only when every actionable item is an inquiry', async () => {
		mockApiFetch.mockResolvedValue( {
			disputes: [
				{
					id: 'dp_first',
					status: 'warning_needs_response',
					amount: 1000,
					currency: 'usd',
					evidence_details: { due_by: 1698672000 },
				},
				{
					id: 'dp_second',
					status: 'warning_needs_response',
					amount: 1550,
					currency: 'usd',
					evidence_details: { due_by: 1698500219 },
				},
			],
		} );

		render(
			<WooPaymentsOrderDisputeNotice
				chargeId="ch_inquiries"
				onDisableOrderRefund={ jest.fn() }
			/>
		);

		expect(
			await screen.findByText(
				'This order has 2 payment inquiries totaling $25.50.'
			)
		).toBeInTheDocument();
	} );

	it( 'keeps a live deadline visible while reporting the strongest refund blocker', async () => {
		const onDisableOrderRefund = jest.fn();
		mockApiFetch.mockResolvedValue( {
			disputes: [
				{
					id: 'dp_awaiting',
					status: 'needs_response',
					reason: 'fraudulent',
					amount: 1000,
					currency: 'usd',
					evidence_details: { due_by: 1698672000 },
				},
				{
					id: 'dp_review',
					status: 'under_review',
				},
				{
					id: 'dp_lost',
					status: 'lost',
				},
			],
		} );

		render(
			<WooPaymentsOrderDisputeNotice
				chargeId="ch_mixed"
				onDisableOrderRefund={ onDisableOrderRefund }
			/>
		);

		expect(
			(
				await screen.findAllByText(
					/Please respond before October 30, 2023/
				)
			).length
		).toBeGreaterThan( 0 );
		expect( onDisableOrderRefund ).toHaveBeenCalledWith( 'lost' );
	} );

	it( 'uses the singular dispute fallback for a reason-specific notice', async () => {
		const onDisableOrderRefund = jest.fn();
		mockApiFetch.mockResolvedValue( {
			dispute: {
				id: 'dp_singular',
				status: 'needs_response',
				reason: 'fraudulent',
				amount: 1000,
				currency: 'usd',
				evidence_details: { due_by: 1698500219 },
			},
		} );

		render(
			<WooPaymentsOrderDisputeNotice
				chargeId="ch_singular"
				onDisableOrderRefund={ onDisableOrderRefund }
			/>
		);

		expect(
			(
				await screen.findAllByText(
					"This order has a payment dispute for $10.00 for the reason 'Transaction unauthorized'."
				)
			).length
		).toBeGreaterThan( 0 );
		expect( onDisableOrderRefund ).toHaveBeenCalledWith( 'needs_response' );
	} );

	it.each( [
		[
			'under_review',
			'This order has an active payment dispute. Refunds and order editing are disabled.',
		],
		[
			'lost',
			'Refunds and order editing have been disabled as a result of a lost dispute.',
		],
	] )( 'shows the charge-wide %s lock notice', async ( status, message ) => {
		const onDisableOrderRefund = jest.fn();
		mockApiFetch.mockResolvedValue( {
			disputes: [ { id: `dp_${ status }`, status } ],
		} );

		render(
			<WooPaymentsOrderDisputeNotice
				chargeId="ch_locked"
				onDisableOrderRefund={ onDisableOrderRefund }
			/>
		);

		expect(
			( await screen.findAllByText( message ) ).length
		).toBeGreaterThan( 0 );
		expect( onDisableOrderRefund ).toHaveBeenCalledWith( status );
	} );

	it( 'ignores malformed amounts, currencies, and deadlines', async () => {
		mockApiFetch.mockResolvedValue( {
			disputes: [
				{
					id: 'dp_invalid_deadline',
					status: 'needs_response',
					amount: 5000,
					currency: 'usd',
					evidence_details: { due_by: 'not-a-deadline' },
				},
				{
					id: 'dp_invalid_amount',
					status: 'needs_response',
					amount: 'not-an-amount' as unknown as number,
					currency: 'not-a-code',
					evidence_details: { due_by: 1698672000 },
				},
				{
					id: 'dp_nonfinite_amount',
					status: 'needs_response',
					amount: Number.POSITIVE_INFINITY,
					currency: '',
					evidence_details: { due_by: 1698500219 },
				},
			],
		} );

		render(
			<WooPaymentsOrderDisputeNotice
				chargeId="ch_malformed"
				onDisableOrderRefund={ jest.fn() }
			/>
		);

		expect(
			await screen.findByText(
				'This order has 2 payment disputes totaling $0.00.'
			)
		).toBeInTheDocument();
		expect(
			screen.getAllByText( /Please respond before October 28, 2023/ )
				.length
		).toBeGreaterThan( 0 );
	} );

	it( 'locks refunds when a dispute omits its status', async () => {
		const onDisableOrderRefund = jest.fn();
		mockApiFetch.mockResolvedValue( {
			disputes: [ { id: 'dp_missing_status' } ],
		} );

		render(
			<WooPaymentsOrderDisputeNotice
				chargeId="ch_missing_status"
				onDisableOrderRefund={ onDisableOrderRefund }
			/>
		);

		await waitFor( () =>
			expect( onDisableOrderRefund ).toHaveBeenCalledWith( 'unknown' )
		);
	} );

	it( 'renders nothing and leaves refunds alone when the charge has no disputes', async () => {
		const onDisableOrderRefund = jest.fn();
		mockApiFetch.mockResolvedValue( { disputes: [] } );

		const { container } = render(
			<WooPaymentsOrderDisputeNotice
				chargeId="ch_clear"
				onDisableOrderRefund={ onDisableOrderRefund }
			/>
		);

		await waitFor( () =>
			expect( mockApiFetch ).toHaveBeenCalledTimes( 1 )
		);
		expect( container ).toBeEmptyDOMElement();
		expect( onDisableOrderRefund ).not.toHaveBeenCalled();
	} );

	it( 'fails soft when no charge can be read', async () => {
		mockApiFetch.mockRejectedValue( new Error( 'Provider unavailable' ) );

		const { container } = render(
			<WooPaymentsOrderDisputeNotice
				chargeId="ch_unavailable"
				onDisableOrderRefund={ jest.fn() }
			/>
		);

		await waitFor( () =>
			expect( mockApiFetch ).toHaveBeenCalledTimes( 1 )
		);
		expect( container ).toBeEmptyDOMElement();
	} );
	// Client 11.1.0 `components/disputed-order-notice/index.js:147-152,228-276,297-306`: under seven days
	// left the single-dispute notice switches to the urgent wording with a days-left countdown.
	describe( 'when the response deadline is less than a week away', () => {
		const renderSingle = ( status: string, dueBy: number ) => {
			mockApiFetch.mockResolvedValue( {
				disputes: [
					{
						id: 'dp_urgent',
						status,
						reason: 'fraudulent',
						amount: 1000,
						currency: 'usd',
						evidence_details: { due_by: dueBy },
					},
				],
			} );

			render(
				<WooPaymentsOrderDisputeNotice
					chargeId="ch_urgent"
					onDisableOrderRefund={ jest.fn() }
				/>
			);
		};

		it( 'asks to resolve the dispute by its deadline and counts the days left', async () => {
			// 2023-10-25T12:00:00Z, 5.5 days after "now".
			renderSingle( 'needs_response', 1698235200 );

			const message = await screen.findByText(
				"Please resolve the dispute on this order of $10.00 labeled 'Transaction unauthorized' by October 25, 2023."
			);
			expect( message.tagName ).toBe( 'STRONG' );
			expect( message.parentElement ).toHaveTextContent(
				"Please resolve the dispute on this order of $10.00 labeled 'Transaction unauthorized' by October 25, 2023. (5 days left)"
			);
			expect(
				screen.getByRole( 'link', { name: 'Respond now' } )
			).toBeInTheDocument();
		} );

		it( 'uses the inquiry wording and the singular day', async () => {
			// 2023-10-21T12:00:00Z, 1.5 days after "now".
			renderSingle( 'warning_needs_response', 1697889600 );

			const message = await screen.findByText(
				"Please resolve the inquiry on this order of $10.00 labeled 'Transaction unauthorized' by October 21, 2023."
			);
			expect( message.parentElement ).toHaveTextContent( '(1 day left)' );
		} );

		it( 'says it is the last day when less than a day is left', async () => {
			// 2023-10-20T12:00:00Z, half a day after "now".
			renderSingle( 'needs_response', 1697803200 );

			const message = await screen.findByText(
				"Please resolve the dispute on this order of $10.00 labeled 'Transaction unauthorized' by October 20, 2023."
			);
			expect( message.parentElement ).toHaveTextContent(
				'(Last day today)'
			);
			expect(
				screen.getByRole( 'link', { name: 'Respond today' } )
			).toBeInTheDocument();
		} );

		it( 'adds the countdown to the consolidated notice', async () => {
			// Client `index.js:437-455`.
			mockApiFetch.mockResolvedValue( {
				disputes: [
					{
						id: 'dp_first',
						status: 'needs_response',
						amount: 1000,
						currency: 'usd',
						evidence_details: { due_by: 1698235200 },
					},
					{
						id: 'dp_second',
						status: 'needs_response',
						amount: 500,
						currency: 'usd',
						evidence_details: { due_by: 1698672000 },
					},
				],
			} );

			render(
				<WooPaymentsOrderDisputeNotice
					chargeId="ch_urgent_many"
					onDisableOrderRefund={ jest.fn() }
				/>
			);

			const message = await screen.findByText(
				'This order has 2 payment disputes totaling $15.00.'
			);
			expect( message.parentElement ).toHaveTextContent(
				'This order has 2 payment disputes totaling $15.00. Please respond before October 25, 2023. (5 days left)'
			);
		} );
	} );
} );
