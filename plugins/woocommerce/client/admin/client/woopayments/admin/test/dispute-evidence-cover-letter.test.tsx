/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { getSettings, setSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import { DisputeEvidenceForm } from '../money-movement/dispute-evidence-form';
import { generateDisputeCoverLetter } from '../money-movement/dispute-evidence-cover-letter';
import { updateWooPaymentsDispute } from '../money-movement/data';
import { useGetSettings } from '../../settings/data/hooks';
import type { WooPaymentsDispute } from '../money-movement/types';

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

jest.mock( '@wordpress/a11y', () => ( {
	speak: jest.fn(),
} ) );

jest.mock( '../money-movement/data', () => ( {
	updateWooPaymentsDispute: jest.fn(),
	uploadWooPaymentsDisputeFile: jest.fn(),
} ) );

jest.mock( '../../settings/data/hooks', () => ( {
	useGetSettings: jest.fn(),
} ) );

const mockUpdateDispute = updateWooPaymentsDispute as jest.MockedFunction<
	typeof updateWooPaymentsDispute
>;
const mockUseGetSettings = useGetSettings as jest.MockedFunction<
	typeof useGetSettings
>;
const originalWcSettings = window.wcSettings;
const originalDateSettings = getSettings();

// A site's usual formats; the `@wordpress/date` default time format is "g: i a".
beforeAll( () => {
	setSettings( {
		...originalDateSettings,
		formats: {
			...originalDateSettings.formats,
			date: 'F j, Y',
			time: 'g:i a',
		},
	} );
} );

afterAll( () => {
	setSettings( originalDateSettings );
} );
const NO_FILES = {};

const makeDispute = (
	overrides: Partial< WooPaymentsDispute > = {}
): WooPaymentsDispute => ( {
	id: 'dp_test',
	reason: 'fraudulent',
	status: 'needs_response',
	amount: 5000,
	currency: 'usd',
	created: 1771423200,
	charge: {
		id: 'ch_test',
		created: 1771336800,
		billing_details: { name: 'Ada Lovelace' },
		payment_method_details: {
			type: 'card',
			card: { issuer: 'Test Issuing Bank' },
		},
	},
	evidence: { access_activity_log: 'file_usage_records' },
	evidence_details: { due_by: 1772028000 },
	metadata: { __product_type: 'digital_product_or_service' },
	...overrides,
} );

const setStoreSettings = ( {
	siteTitle,
	general,
	formattedStoreAddress,
}: {
	siteTitle?: string;
	general?: Record< string, string >;
	formattedStoreAddress?: string;
} ) => {
	window.wcSettings = {
		...originalWcSettings,
		siteTitle,
		admin: {
			...originalWcSettings?.admin,
			preloadSettings: general ? { general } : undefined,
			woopaymentsSettings: formattedStoreAddress
				? { formattedStoreAddress }
				: undefined,
		},
	} as typeof window.wcSettings;
};

const renderReviewStep = async ( dispute: WooPaymentsDispute ) => {
	render(
		<DisputeEvidenceForm dispute={ dispute } fileDetails={ NO_FILES } />
	);
	await userEvent.type(
		screen.getByRole( 'textbox', {
			name: 'Product or service description',
		} ),
		'Downloaded software.'
	);
	await userEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );

	const coverLetter = await screen.findByRole( 'textbox', {
		name: 'Cover letter',
	} );

	return coverLetter instanceof window.HTMLTextAreaElement
		? coverLetter.value
		: '';
};

describe( 'Dispute cover letter prefill', () => {
	beforeEach( () => {
		mockUpdateDispute.mockReset();
		mockUpdateDispute.mockImplementation( async ( _id, data ) =>
			makeDispute( { evidence: data.evidence } )
		);
		mockUseGetSettings.mockReset();
	} );

	afterAll( () => {
		window.wcSettings = originalWcSettings;
	} );

	it( 'prefills the merchant and bank details so no placeholder remains', async () => {
		setStoreSettings( {
			siteTitle: 'Coffee Roasters',
			general: {
				woocommerce_default_country: 'US:CA',
				woocommerce_store_address: '1 Market Street',
				woocommerce_store_address_2: 'Suite 2',
				woocommerce_store_city: 'San Francisco',
				woocommerce_store_postcode: '94105',
			},
		} );
		mockUseGetSettings.mockReturnValue( {
			account_business_support_email: 'support@example.com',
			account_business_support_phone: '+1 555 123 4567',
		} );

		const letter = await renderReviewStep( makeDispute() );

		// Without the preloaded formatted address, client formatMerchantAddress() joins the store fields.
		expect( letter.split( '\n' ).slice( 0, 4 ) ).toEqual( [
			'Coffee Roasters',
			'1 Market Street, Suite 2, San Francisco, CA, 94105, US',
			'support@example.com',
			'+1 555 123 4567',
		] );
		expect( letter ).toContain( 'To: Test Issuing Bank' );
		expect( letter ).toContain(
			'Our records indicate that the customer and legitimate cardholder, Ada Lovelace, ordered Downloaded software. on February 17, 2026, 2:00 pm.'
		);
		expect( letter ).toContain( '• Login or usage records (Attachment A)' );
		expect( letter.endsWith( 'Thank you,\nCoffee Roasters' ) ).toBe( true );
		expect( letter ).not.toMatch( /<[^>]+>/ );
	} );

	it( 'uses the store address WooCommerce formats', async () => {
		// Client 11.1.0 cover-letter-generator.ts:35-50, class-wc-payments-admin.php:1074-1085.
		setStoreSettings( {
			siteTitle: 'Coffee Roasters',
			general: {
				woocommerce_default_country: 'US:CA',
				woocommerce_store_address: '1 Market Street',
			},
			formattedStoreAddress:
				'1 Market Street, Suite 2, San Francisco, CA 94105',
		} );
		mockUseGetSettings.mockReturnValue( {} );

		const letter = await renderReviewStep( makeDispute() );

		expect( letter.split( '\n' )[ 1 ] ).toBe(
			'1 Market Street, Suite 2, San Francisco, CA 94105'
		);
	} );

	it( 'reads a saved "multiple" product type as Other', () => {
		// Client 11.1.0 new-evidence/resolve-product-type.ts with the additional evidence types on.
		setStoreSettings( {} );
		mockUseGetSettings.mockReturnValue( {} );
		render(
			<DisputeEvidenceForm
				dispute={ makeDispute( {
					metadata: { __product_type: 'multiple' },
				} ) }
				fileDetails={ NO_FILES }
			/>
		);

		expect(
			screen.getByRole( 'combobox', {
				name: 'Product or service type',
			} )
		).toHaveValue( 'other' );
	} );

	it( "falls back to the client's placeholders when the data is missing", async () => {
		setStoreSettings( {} );
		mockUseGetSettings.mockReturnValue( {} );

		const letter = await renderReviewStep(
			makeDispute( {
				charge: { id: 'ch_test' },
				evidence: {},
			} )
		);

		expect( letter.split( '\n' ).slice( 0, 4 ) ).toEqual( [
			'<Your Business Name>',
			'',
			'<business@email.com>',
			'<Business Phone Number>',
		] );
		expect( letter ).toContain( 'To: <Bank Name>' );
		expect( letter ).toContain(
			'Our records indicate that the customer and legitimate cardholder, <Customer Name>, ordered Downloaded software. on <Order Date>.'
		);
		expect( letter ).toContain(
			'• <Attachment description> (Attachment A)\n• <Attachment description> (Attachment B)'
		);
		expect( letter.endsWith( 'Thank you,\n<Your Business Name>' ) ).toBe(
			true
		);
	} );
} );

// Client 11.1.0 cover-letter-generator.ts:675-1062.
describe( 'Dispute cover letter bodies', () => {
	const baseInput = {
		merchantName: 'Coffee Roasters',
		merchantAddress: '1 Market Street',
		merchantEmail: 'support@example.com',
		merchantPhone: '+1 555 123 4567',
		bankName: 'Test Issuing Bank',
		today: 'Feb 20, 2026',
		productType: 'digital_product_or_service',
		evidence: { product_description: 'Coffee beans' },
	};
	const letterFor = (
		reason: string,
		extra: Partial<
			Parameters< typeof generateDisputeCoverLetter >[ 0 ]
		> = {}
	) =>
		generateDisputeCoverLetter( {
			...baseInput,
			dispute: makeDispute( { reason, created: undefined } ),
			...extra,
		} );
	const REQUEST_US =
		'Based on this information, we respectfully request that the chargeback be reversed. Please let us know if any further details are required.';

	it( 'uses the header, recipient and closing layout', () => {
		expect( letterFor( 'fraudulent' ) ).toMatch(
			/^Coffee Roasters\n1 Market Street\nsupport@example.com\n\+1 555 123 4567\nFeb 20, 2026\n\nTo: Test Issuing Bank\nSubject: Chargeback Dispute – Case #dp_test\n\nDear Dispute Resolution Team,\n\nWe are submitting evidence in response to chargeback #dp_test for transaction #ch_test on <Transaction Date>\.\n\n/
		);
	} );

	it.each( [ 'fraudulent', 'unrecognized' ] )(
		'uses the unrecognized body for %s disputes',
		( reason ) => {
			const letter = letterFor( reason );

			expect( letter ).toContain(
				'Our records indicate that the customer and legitimate cardholder, Ada Lovelace, ordered Coffee beans on '
			);
			expect( letter ).toContain(
				'Based on this information, we respectfully request that the chargeback be reversed. Please let me know if any further details are required.'
			);
		}
	);

	it.each( [ 'general', 'product_not_received', 'bank_cannot_process' ] )(
		'uses the general body for %s disputes',
		( reason ) => {
			const letter = letterFor( reason );

			expect( letter ).toContain(
				'Our records indicate that the customer, Ada Lovelace, ordered Coffee beans on '
			);
			expect( letter ).toContain(
				' and received it on <Delivery/Service Date>.'
			);
			expect( letter ).toContain( REQUEST_US );
		}
	);

	it( 'uses the product unacceptable body', () => {
		expect( letterFor( 'product_unacceptable' ) ).toContain(
			'. The product matched the description provided at the time of sale, and we did not receive any indication from the customer that it was defective or not as described.'
		);
	} );

	it( 'uses the subscription canceled body', () => {
		expect( letterFor( 'subscription_canceled' ) ).toContain(
			"Our records indicate that the customer, Ada Lovelace, subscribed to Coffee beans and was billed according to the terms accepted at the time of signup. The customer's account remained active and no cancellation was recorded prior to the billing date."
		);
	} );

	it( 'uses the credit not processed bodies for each refund status', () => {
		expect(
			letterFor( 'credit_not_processed', {
				refundStatus: 'refund_has_been_issued',
			} )
		).toContain(
			"was refunded on February 17, 2026, 2:00 pm for the amount of 50.00 USD. The refund was processed through our payment provider and should be visible on the customer's statement within 7 - 10 business days."
		);
		expect(
			letterFor( 'credit_not_processed', {
				refundStatus: 'refund_was_not_owed',
			} )
		).toContain(
			'on <Transaction Date>.\nThe customer requested a refund outside of the eligible window outlined in our refund policy, which was clearly presented on the website and on the order confirmation.'
		);
	} );

	it( 'uses the duplicate bodies for each duplicate status', () => {
		expect(
			letterFor( 'duplicate', { duplicateStatus: 'is_duplicate' } )
		).toContain(
			"Our records indicate that this charge was a duplicate of a previous transaction. A refund has already been issued to the customer on February 17, 2026, 2:00 pm for the amount of 50.00 USD. This refund should be visible on the customer's statement within 7 - 10 business days."
		);
		expect(
			letterFor( 'duplicate', { duplicateStatus: 'is_not_duplicate' } )
		).toContain(
			'Our records show that the customer placed two distinct orders: dp_test and ch_test. Both transactions were legitimate, fulfilled independently, and are not duplicates.'
		);
	} );
} );

// Client 11.1.0 cover-letter-generator.ts:52-64, 75-650, 1043-1093.
describe( 'Dispute cover letter dates and attachments', () => {
	const letterFor = (
		dispute: Partial< WooPaymentsDispute >,
		extra: Partial<
			Parameters< typeof generateDisputeCoverLetter >[ 0 ]
		> = {}
	) =>
		generateDisputeCoverLetter( {
			dispute: makeDispute( { evidence: {}, ...dispute } ),
			...extra,
		} );
	const attachmentsOf = ( letter: string ) =>
		letter.split( '\n' ).filter( ( line ) => line.startsWith( '• ' ) );

	it( 'dates the transaction and order with the time, today and the delivery without', () => {
		jest.useFakeTimers().setSystemTime(
			new Date( '2026-02-20T12:00:00Z' )
		);
		const letter = letterFor(
			{ reason: 'product_not_received' },
			{ evidence: { shipping_date: '2026-02-19' } }
		);
		jest.useRealTimers();

		expect( letter.split( '\n' )[ 4 ] ).toBe( 'February 20, 2026' );
		expect( letter ).toContain(
			'for transaction #ch_test on February 18, 2026, 2:00 pm.'
		);
		expect( letter ).toContain(
			'ordered <Product> on February 17, 2026, 2:00 pm and received it on February 19, 2026.'
		);
	} );

	it( 'labels and orders duplicate evidence as the client', () => {
		expect(
			attachmentsOf(
				letterFor(
					{
						reason: 'duplicate',
						evidence: {
							refund_policy: 'file_policy',
							duplicate_charge_documentation: 'file_refund',
							receipt: 'file_receipt',
							access_activity_log: 'file_log',
						},
					},
					{ duplicateStatus: 'is_duplicate' }
				)
			)
		).toEqual( [
			'• Order receipt (Attachment A)',
			'• Refund receipt (Attachment B)',
			'• Proof of active subscription (Attachment C)',
			'• Refund policy (Attachment D)',
		] );
	} );

	it( 'puts booking documentation first for an unacceptable booking', () => {
		expect(
			attachmentsOf(
				letterFor(
					{
						reason: 'product_unacceptable',
						evidence: {
							receipt: 'file_receipt',
							service_documentation: 'file_booking',
							cancellation_policy: 'file_terms',
						},
					},
					{ productType: 'booking_reservation' }
				)
			)
		).toEqual( [
			'• Event or booking documentation (Attachment A)',
			'• Order receipt (Attachment B)',
			'• Cancellation policy (Attachment C)',
		] );
	} );

	it( 'lists proof of acceptance first when no refund was owed', () => {
		expect(
			attachmentsOf(
				letterFor(
					{
						reason: 'credit_not_processed',
						evidence: {
							receipt: 'file_receipt',
							uncategorized_file: 'file_acceptance',
							customer_communication: 'file_chat',
						},
					},
					{
						productType: 'digital_product_or_service',
						refundStatus: 'refund_was_not_owed',
					}
				)
			)
		).toEqual( [
			'• Proof of acceptance (Attachment A)',
			'• Order receipt (Attachment B)',
			'• Other documents (Attachment C)',
		] );
	} );
} );
