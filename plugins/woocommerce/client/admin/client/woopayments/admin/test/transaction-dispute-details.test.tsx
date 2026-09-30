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
