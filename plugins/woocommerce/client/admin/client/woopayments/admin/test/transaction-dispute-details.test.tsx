/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import { WooPaymentsTransactionDisputeDetails } from '../money-movement/transaction-dispute-details';
import type { WooPaymentsDispute } from '../money-movement/types';

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

jest.mock( '../money-movement/data', () => ( {
	closeWooPaymentsDispute: jest.fn(),
} ) );

// Client 11.1.0 dispute-awaiting-response-details.tsx:370-374.
const VISA_ACKNOWLEDGEMENT =
	'By checking this box, you acknowledge that challenging this Visa compliance dispute incurs a $500 USD network fee, which will be refunded if you win the dispute.';

const makeDispute = (
	overrides: Partial< WooPaymentsDispute > = {}
): WooPaymentsDispute => ( {
	id: 'dp_visa_compliance_1',
	reason: 'noncompliant',
	status: 'needs_response',
	amount: 5000,
	currency: 'usd',
	enhanced_eligibility_types: [ 'visa_compliance' ],
	evidence_details: { has_evidence: false, due_by: 1772028000 },
	...overrides,
} );

const renderDetails = ( dispute: WooPaymentsDispute ) =>
	render(
		<WooPaymentsTransactionDisputeDetails
			transaction={ { id: 'pi_test', amount: 5000, currency: 'usd' } }
			dispute={ dispute }
			ordinal={ 1 }
			total={ 1 }
		/>
	);

describe( 'WooPaymentsTransactionDisputeDetails Visa compliance', () => {
	beforeEach( () => {
		window.wcSettings = {
			...window.wcSettings,
			adminUrl: 'https://example.com/wp-admin/',
		};
	} );

	it( 'keeps Challenge dispute disabled until the network fee is acknowledged', async () => {
		renderDetails( makeDispute() );

		const checkbox = screen.getByRole( 'checkbox', {
			name: VISA_ACKNOWLEDGEMENT,
		} );
		expect( checkbox ).not.toBeChecked();
		expect(
			screen.getByRole( 'button', { name: 'Challenge dispute' } )
		).toBeDisabled();
		expect(
			screen.queryByRole( 'link', { name: 'Challenge dispute' } )
		).not.toBeInTheDocument();

		await userEvent.click( checkbox );

		expect( checkbox ).toBeChecked();
		expect(
			screen.getByRole( 'link', { name: 'Challenge dispute' } )
		).toHaveAttribute(
			'href',
			'https://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fdisputes%2Fchallenge&id=dp_visa_compliance_1'
		);
	} );

	it( 'shows the $500 USD Visa network fee steps and help link', () => {
		renderDetails( makeDispute() );

		expect( screen.getByText( 'Steps you can take' ) ).toBeInTheDocument();
		expect(
			screen.getByText(
				'Accepting the dispute means you’ll forfeit the funds, pay the standard dispute fee, and avoid the $500 USD Visa network fee.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Challenging the dispute will incur a $500 USD Visa network fee, which is charged when you submit evidence. This fee will be refunded if you win the dispute.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'The outcome of this dispute will be determined by Visa.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', {
				name: ( accessibleName: string ) =>
					accessibleName.startsWith(
						'Learn more about Visa compliance disputes'
					),
			} )
		).toHaveAttribute(
			'href',
			'https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/#visa-compliance-disputes'
		);
	} );

	it( 'treats a noncompliant reason without the eligibility type as Visa compliance', () => {
		renderDetails( makeDispute( { enhanced_eligibility_types: [] } ) );

		expect(
			screen.getByRole( 'checkbox', { name: VISA_ACKNOWLEDGEMENT } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Challenge dispute' } )
		).toBeDisabled();
	} );

	it( 'treats the visa_compliance eligibility type as Visa compliance for other reasons', () => {
		renderDetails( makeDispute( { reason: 'fraudulent' } ) );

		expect(
			screen.getByRole( 'button', { name: 'Challenge dispute' } )
		).toBeDisabled();
	} );

	it( 'starts acknowledged when the dispute already has staged evidence', () => {
		renderDetails(
			makeDispute( {
				evidence_details: { has_evidence: true, due_by: 1772028000 },
			} )
		);

		expect(
			screen.getByRole( 'checkbox', { name: VISA_ACKNOWLEDGEMENT } )
		).toBeChecked();
		// Client 11.1.0 dispute-awaiting-response-details.tsx:331-339, 410-415: the staged-evidence notice
		// and the "Continue with challenge" label.
		expect(
			screen.getByText(
				"You initiated a challenge to this dispute. Click 'Continue with challenge' to proceed with your draft response.",
				{ selector: '.components-notice__content' }
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Continue with challenge' } )
		).toBeInTheDocument();
	} );

	it( 'does not gate or disclose the fee for other disputes', () => {
		renderDetails(
			makeDispute( {
				reason: 'fraudulent',
				enhanced_eligibility_types: [],
			} )
		);

		expect(
			screen.queryByRole( 'checkbox', { name: VISA_ACKNOWLEDGEMENT } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByText( /\$500 USD Visa network fee/ )
		).not.toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Challenge dispute' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', {
				name: ( accessibleName: string ) =>
					accessibleName.startsWith(
						'Learn more about responding to disputes'
					),
			} )
		).toBeInTheDocument();
	} );
} );

// Client 11.1.0 payment-details/dispute-details/dispute-steps.tsx and
// dispute-awaiting-response-details.tsx:279-470.
describe( 'WooPaymentsTransactionDisputeDetails steps you can take', () => {
	const transaction = {
		id: 'pi_test',
		amount: 5000,
		currency: 'usd',
		created: 1771423200,
		billing_details: { name: 'Ada Lovelace', email: 'ada@example.com' },
		payment_method_details: { type: 'card' },
	};
	const renderPane = (
		dispute: Partial< WooPaymentsDispute >,
		paymentMethod = 'card'
	) =>
		render(
			<WooPaymentsTransactionDisputeDetails
				transaction={ {
					...transaction,
					payment_method_details: { type: paymentMethod },
				} }
				dispute={ {
					id: 'dp_1',
					amount: 5000,
					currency: 'usd',
					created: 1771509600,
					evidence_details: {
						has_evidence: false,
						due_by: 1772028000,
					},
					...dispute,
				} }
				ordinal={ 1 }
				total={ 1 }
				onIssueRefund={ jest.fn() }
			/>
		);
	const getItemTitles = () =>
		Array.from(
			document.querySelectorAll(
				'.woocommerce-woopayments-dispute-step__name'
			)
		).map( ( title ) => title.textContent );

	beforeEach( () => {
		window.wcSettings = {
			...window.wcSettings,
			adminUrl: 'https://example.com/wp-admin/',
			siteTitle: 'Example Store',
		};
	} );

	it( 'offers the collapsed dispute steps with the customer email', async () => {
		renderPane( { reason: 'fraudulent', status: 'needs_response' } );

		const toggle = screen.getByRole( 'button', {
			name: /^Steps you can take/,
		} );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( toggle ).toHaveTextContent(
			'We recommend reviewing your options before responding before the deadline.'
		);
		expect( screen.queryByText( 'Contact your customer' ) ).toBeNull();

		await userEvent.click( toggle );

		expect( getItemTitles() ).toEqual( [
			'Contact your customer',
			'Ask for the dispute to be withdrawn',
			'Challenge or accept the dispute',
		] );
		const email = screen
			.getByRole( 'link', { name: 'Email customer' } )
			.getAttribute( 'href' ) as string;
		expect( email ).toMatch( /^mailto:ada@example\.com\?subject=/ );
		expect( decodeURIComponent( email ) ).toContain(
			'subject=Problem with your purchase from Example Store on February 18, 2026?'
		);
		expect( decodeURIComponent( email ) ).toContain(
			'Hello Ada Lovelace,\n\nWe noticed that on February 19, 2026, you disputed a $50.00 charge on February 18, 2026.'
		);
		expect(
			screen.getByRole( 'link', { name: 'Learn more ↗' } )
		).toHaveAttribute(
			'href',
			'https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/#withdrawals'
		);
		expect(
			screen.getByText(
				"The outcome of this dispute will be determined by the cardholder's bank."
			)
		).toBeInTheDocument();
	} );

	it( 'offers the inquiry steps with the inquiry email', async () => {
		renderPane( {
			reason: 'fraudulent',
			status: 'warning_needs_response',
		} );

		await userEvent.click(
			screen.getByRole( 'button', { name: /^Steps you can take/ } )
		);

		expect( getItemTitles() ).toEqual( [
			'Contact your customer',
			'Submit evidence or issue a refund',
		] );
		expect(
			decodeURIComponent(
				screen
					.getByRole( 'link', { name: 'Email customer' } )
					.getAttribute( 'href' ) as string
			)
		).toContain( 'you raised a question with your payment provider' );
		expect(
			screen.getByText(
				"The outcome of this inquiry will be determined by the cardholder's bank."
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Submit evidence' } )
		).toBeInTheDocument();
	} );

	it( 'lets a Klarna inquiry only be refunded, with the challenge explained', async () => {
		renderPane(
			{
				reason: 'credit_not_processed',
				status: 'warning_needs_response',
			},
			'klarna'
		);

		expect(
			screen.queryByRole( 'link', { name: 'Submit evidence' } )
		).not.toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Issue refund' } )
		).toHaveClass( 'is-primary' );
		expect(
			screen.getByRole( 'button', {
				name: 'Challenge dispute — available if the inquiry escalates to a dispute',
			} )
		).toHaveAttribute( 'aria-disabled', 'true' );

		await userEvent.click(
			screen.getByRole( 'button', { name: /^Steps you can take/ } )
		);

		expect( getItemTitles() ).toEqual( [
			'Contact your customer',
			'Issue a refund',
			'Respond when the inquiry becomes a dispute',
		] );
		expect(
			screen.getByText(
				"Reach out to the customer to check if they're returning the item(s)."
			)
		).toBeInTheDocument();
	} );

	it( 'opens the Visa compliance steps by default', () => {
		renderPane( {
			reason: 'noncompliant',
			status: 'needs_response',
			enhanced_eligibility_types: [ 'visa_compliance' ],
		} );

		expect(
			screen.getByRole( 'button', { name: /^Steps you can take/ } )
		).toHaveAttribute( 'aria-expanded', 'true' );
		expect( getItemTitles() ).toEqual( [
			'Accepting the dispute',
			'Challenge the dispute',
		] );
	} );
} );
