/**
 * External dependencies
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { summaryItem } from './helpers/table-summary';
import { WooPaymentsDocumentsPage } from '../documents/page';
import {
	getWooPaymentsDocuments,
	getWooPaymentsDocumentsAccount,
	getWooPaymentsDocumentsSummary,
	saveWooPaymentsVatDetails,
	validateWooPaymentsVatNumber,
} from '../documents/data';
import {
	mockUpdateUserPreferences,
	setMockUserPreferences,
} from './helpers/user-preferences';
import {
	getTestModeNoticeText,
	mockAccountMode,
} from './helpers/test-mode-account';

jest.mock( '@woocommerce/data', () => ( {
	useUserPreferences: () =>
		jest
			.requireActual( './helpers/user-preferences' )
			.useMockUserPreferences(),
} ) );

jest.mock( '../documents/data', () => ( {
	buildWooPaymentsDocumentUrl: ( documentId: string ) =>
		`https://site.test/wp-json/wc/v3/payments/documents/${ documentId }?_wpnonce=rest-nonce`,
	getWooPaymentsDocuments: jest.fn(),
	getWooPaymentsDocumentsAccount: jest.fn(),
	getWooPaymentsDocumentsSummary: jest.fn(),
	saveWooPaymentsVatDetails: jest.fn(),
	validateWooPaymentsVatNumber: jest.fn(),
} ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => <div>Spotlight promotion</div>,
} ) );

jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: ( {
		data = [],
		fields = [],
		header,
		isLoading,
		onChangeView,
		search = true,
		searchLabel,
		view = {},
	}: {
		data?: Array< Record< string, unknown > >;
		fields?: Array< {
			id: string;
			label: string;
			filterBy?: false | Record< string, unknown >;
			elements?: Array< { label: string; value: string } >;
			render?: ( props: {
				item: Record< string, unknown >;
			} ) => ReactNode;
		} >;
		header?: ReactNode;
		isLoading?: boolean;
		onChangeView?: ( view: Record< string, unknown > ) => void;
		search?: boolean;
		searchLabel?: string;
		view?: Record< string, unknown >;
	} ) => (
		<div
			data-testid="documents-dataviews"
			data-visible-fields={ ( view.fields as string[] )?.join( ',' ) }
			aria-busy={ isLoading }
		>
			{ header }
			<button
				type="button"
				onClick={ () =>
					onChangeView?.( {
						...view,
						fields: [ 'date', 'type', 'actions' ],
					} )
				}
			>
				Mock hide Description
			</button>
			{ search && searchLabel && (
				<input
					type="search"
					aria-label={ searchLabel }
					value={ String( view.search || '' ) }
					onChange={ ( event ) =>
						onChangeView?.( {
							...view,
							search: event.currentTarget.value,
						} )
					}
				/>
			) }
			{ fields.map( ( field ) => (
				<div key={ field.id }>
					{ field.label }
					{ field.filterBy && <span>Filter { field.label }</span> }
					{ field.elements?.map( ( element ) => (
						<span key={ element.value }>{ element.label }</span>
					) ) }
				</div>
			) ) }
			{ data.map( ( item ) => (
				<div key={ String( item.document_id ) }>
					{ fields.map( ( field ) => (
						<div key={ field.id }>
							{ field.render
								? field.render( { item } )
								: String( item[ field.id ] || '' ) }
						</div>
					) ) }
				</div>
			) ) }
		</div>
	),
} ) );

const mockGetDocuments = getWooPaymentsDocuments as jest.MockedFunction<
	typeof getWooPaymentsDocuments
>;
const mockGetDocumentsAccount =
	getWooPaymentsDocumentsAccount as jest.MockedFunction<
		typeof getWooPaymentsDocumentsAccount
	>;
const mockGetDocumentsSummary =
	getWooPaymentsDocumentsSummary as jest.MockedFunction<
		typeof getWooPaymentsDocumentsSummary
	>;
const mockValidateVat = validateWooPaymentsVatNumber as jest.MockedFunction<
	typeof validateWooPaymentsVatNumber
>;
const mockSaveVat = saveWooPaymentsVatDetails as jest.MockedFunction<
	typeof saveWooPaymentsVatDetails
>;

const documentRow = {
	document_id: 'vat_invoice_123',
	date: '2026-06-01 10:11:12',
	type: 'vat_invoice',
	period_from: '2026-05-01 00:00:00',
	period_to: '2026-05-31 23:59:59',
};

const enabledAccount = {
	account: {
		connected: true,
		test_mode: true,
		test_drive: true,
		sandbox: false,
		live: false,
		mode: 'test',
	},
	documents: {
		enabled: true,
		has_submitted_vat_data: true,
		country: 'DE',
	},
	urls: {},
};

const renderDocumentsPage = ( initialEntries = [ '/woopayments/documents' ] ) =>
	render(
		<MemoryRouter initialEntries={ initialEntries }>
			<WooPaymentsDocumentsPage />
		</MemoryRouter>
	);

describe( 'WooPaymentsDocumentsPage', () => {
	let openSpy: jest.SpyInstance;

	beforeEach( () => {
		openSpy = jest.spyOn( window, 'open' ).mockImplementation();
		setMockUserPreferences( {} );
		mockAccountMode( false );
		window.wcSettings = {
			adminUrl: 'http://example.com/wp-admin',
		};
		mockGetDocuments.mockReset();
		mockGetDocumentsAccount.mockReset();
		mockGetDocumentsSummary.mockReset();
		mockValidateVat.mockReset();
		mockSaveVat.mockReset();
		mockGetDocumentsAccount.mockResolvedValue( enabledAccount );
		mockGetDocuments.mockResolvedValue( {
			data: [ documentRow ],
			total_count: 1,
		} );
		mockGetDocumentsSummary.mockResolvedValue( { count: 1 } );
		mockValidateVat.mockResolvedValue( {
			valid: true,
			vat_number: 'DE123456789',
			name: 'Ada Bakery',
			address: '1 Market Street',
			country_code: 'DE',
		} );
		mockSaveVat.mockResolvedValue( {
			vat_number: 'DE123456789',
			name: 'Ada Bakery',
			address: '1 Market Street',
		} );
	} );

	afterEach( () => {
		openSpy.mockRestore();
	} );

	it( 'renders Documents with account mode, summary, DataViews fields, and spotlight', async () => {
		renderDocumentsPage();

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Loading Documents…'
		);

		// Client 11.1.0 `documents/list/index.tsx:241-290`: a TableCard titled Documents with the count in its footer.
		expect(
			await screen.findByRole( 'heading', { name: 'Documents' } )
		).toBeInTheDocument();
		expect(
			await screen.findByText( summaryItem( '1 document' ) )
		).toBeInTheDocument();
		expect( screen.getByText( 'Date' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Type' ) ).toBeInTheDocument();
		// The date and type filters sit under Show: Advanced filters.
		expect( screen.getByLabelText( 'Show' ) ).toHaveValue( 'all' );
		expect( screen.queryByText( 'Filter Date' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Filter Type' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Description' ) ).toBeInTheDocument();
		expect( screen.getAllByText( 'Download' ).length ).toBeGreaterThan( 0 );
		expect( screen.getAllByText( 'Tax Invoice' ).length ).toBeGreaterThan(
			0
		);
		expect(
			screen.getByText( /Tax invoice for .* to .*/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', {
				name: 'Download Tax Invoice vat_invoice_123',
			} )
		).toBeInTheDocument();
		expect( screen.getByText( 'Spotlight promotion' ) ).toBeInTheDocument();
		expect( mockGetDocuments ).toHaveBeenCalledWith(
			expect.objectContaining( {
				page: 1,
				pagesize: 25,
				sort: 'date',
				direction: 'desc',
			} )
		);
	} );

	it( "keeps hidden document columns in the client's user meta key", async () => {
		setMockUserPreferences( {
			wc_payments_documents_hidden_columns: [ 'download' ],
		} );
		renderDocumentsPage();

		await screen.findByText( summaryItem( '1 document' ) );
		expect( screen.getByTestId( 'documents-dataviews' ) ).toHaveAttribute(
			'data-visible-fields',
			'date,type,description'
		);
		expect( mockUpdateUserPreferences ).not.toHaveBeenCalled();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Mock hide Description' } )
		);

		expect( mockUpdateUserPreferences ).toHaveBeenCalledWith( {
			wc_payments_documents_hidden_columns: [ 'description' ],
		} );
		await waitFor( () =>
			expect(
				screen.getByTestId( 'documents-dataviews' )
			).toHaveAttribute( 'data-visible-fields', 'date,type,actions' )
		);
	} );

	it( 'leaves test mode to the page notice instead of a badge beside the title', async () => {
		mockGetDocumentsAccount.mockResolvedValue( {
			...enabledAccount,
			account: {
				...enabledAccount.account,
				live: true,
				test_mode: true,
				mode: 'test',
			},
		} );

		renderDocumentsPage();

		await screen.findByText( summaryItem( '1 document' ) );
		expect( screen.queryByText( 'Test Mode' ) ).not.toBeInTheDocument();
	} );

	it( 'offers the advanced filters and "match any" under Show: Advanced filters, and no search', async () => {
		renderDocumentsPage( [ '/woopayments/documents?filter=advanced' ] );

		await screen.findByText( summaryItem( '1 document' ) );
		expect( screen.queryByRole( 'searchbox' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Filter Date' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Filter Type' ) ).toBeInTheDocument();

		await act( async () => {
			await userEvent.selectOptions(
				screen.getByLabelText( 'Documents match' ),
				'any'
			);
		} );

		await waitFor( () =>
			expect( mockGetDocuments ).toHaveBeenLastCalledWith(
				expect.objectContaining( { match: 'any' } )
			)
		);
		expect( mockGetDocumentsSummary ).toHaveBeenLastCalledWith(
			expect.objectContaining( { match: 'any' } )
		);
	} );

	it( 'fails closed when Documents are not enabled for the account', async () => {
		mockGetDocumentsAccount.mockResolvedValue( {
			...enabledAccount,
			documents: {
				enabled: false,
				has_submitted_vat_data: false,
				country: 'DE',
			},
		} );

		renderDocumentsPage();

		expect(
			await screen.findByText(
				'Documents are not available for this WooPayments account.',
				{ selector: '.components-notice__content' }
			)
		).toBeInTheDocument();
		expect( mockGetDocuments ).not.toHaveBeenCalled();
		expect( mockGetDocumentsSummary ).not.toHaveBeenCalled();
	} );

	it( 'downloads VAT invoices immediately when VAT details were submitted', async () => {
		renderDocumentsPage();

		await userEvent.click(
			await screen.findByRole( 'button', {
				name: 'Download Tax Invoice vat_invoice_123',
			} )
		);

		expect( openSpy ).toHaveBeenCalledWith(
			'https://site.test/wp-json/wc/v3/payments/documents/vat_invoice_123?_wpnonce=rest-nonce',
			'_blank'
		);
		expect(
			screen.queryByRole( 'dialog', { name: 'Set your tax details' } )
		).not.toBeInTheDocument();
	} );

	it( 'collects VAT details before retrying an interrupted VAT invoice download', async () => {
		mockGetDocumentsAccount.mockResolvedValue( {
			...enabledAccount,
			documents: {
				enabled: true,
				has_submitted_vat_data: false,
				country: 'DE',
			},
		} );

		renderDocumentsPage();

		await userEvent.click(
			await screen.findByRole( 'button', {
				name: 'Download Tax Invoice vat_invoice_123',
			} )
		);

		expect(
			await screen.findByRole( 'dialog', {
				name: 'Set your tax details',
			} )
		).toBeInTheDocument();
		expect( openSpy ).not.toHaveBeenCalled();

		await userEvent.click(
			screen.getByRole( 'checkbox', {
				name: 'I have a valid VAT Number',
			} )
		);
		await userEvent.clear( screen.getByLabelText( 'VAT Number' ) );
		await userEvent.type(
			screen.getByLabelText( 'VAT Number' ),
			'DE123456789'
		);
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Continue' } )
			);
		} );

		expect( mockValidateVat ).toHaveBeenCalledWith( 'DE123456789' );
		const businessName = await screen.findByLabelText( 'Business name' );
		expect( businessName ).toHaveValue( 'Ada Bakery' );
		expect( businessName ).toHaveFocus();
		expect( screen.getByLabelText( 'Address' ) ).toHaveValue(
			'1 Market Street'
		);

		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Confirm' } )
			);
		} );

		await waitFor( () =>
			expect( mockSaveVat ).toHaveBeenCalledWith( {
				vat_number: 'DE123456789',
				name: 'Ada Bakery',
				address: '1 Market Street',
			} )
		);
		expect( openSpy ).toHaveBeenCalledWith(
			'https://site.test/wp-json/wc/v3/payments/documents/vat_invoice_123?_wpnonce=rest-nonce',
			'_blank'
		);
	} );

	it( 'collects VAT details without requiring a tax ID before retrying an interrupted VAT invoice download', async () => {
		mockGetDocumentsAccount.mockResolvedValue( {
			...enabledAccount,
			documents: {
				enabled: true,
				has_submitted_vat_data: false,
				country: 'DE',
			},
		} );

		renderDocumentsPage();

		await userEvent.click(
			await screen.findByRole( 'button', {
				name: 'Download Tax Invoice vat_invoice_123',
			} )
		);
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Continue' } )
			);
		} );

		expect( mockValidateVat ).not.toHaveBeenCalled();
		const businessName = await screen.findByLabelText( 'Business name' );
		expect( businessName ).toHaveFocus();
		const confirmButton = screen.getByRole( 'button', { name: 'Confirm' } );
		expect( confirmButton ).toHaveAttribute( 'aria-disabled', 'true' );
		fireEvent.change( businessName, {
			target: { value: 'Ada Bakery' },
		} );
		expect( confirmButton ).toHaveAttribute( 'aria-disabled', 'true' );
		fireEvent.change( screen.getByLabelText( 'Address' ), {
			target: { value: '1 Market Street' },
		} );
		expect( confirmButton ).not.toHaveAttribute( 'aria-disabled', 'true' );
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Confirm' } )
			);
		} );

		await waitFor( () =>
			expect( mockSaveVat ).toHaveBeenCalledWith( {
				vat_number: null,
				name: 'Ada Bakery',
				address: '1 Market Street',
			} )
		);
		expect( openSpy ).toHaveBeenCalledWith(
			'https://site.test/wp-json/wc/v3/payments/documents/vat_invoice_123?_wpnonce=rest-nonce',
			'_blank'
		);
	} );

	it( 'does not retry an interrupted download after the VAT modal is closed during save', async () => {
		let resolveSave: ( value: unknown ) => void = () => {};
		mockSaveVat.mockReturnValue(
			new Promise( ( resolve ) => {
				resolveSave = resolve;
			} )
		);
		mockGetDocumentsAccount.mockResolvedValue( {
			...enabledAccount,
			documents: {
				enabled: true,
				has_submitted_vat_data: false,
				country: 'DE',
			},
		} );

		renderDocumentsPage();

		await userEvent.click(
			await screen.findByRole( 'button', {
				name: 'Download Tax Invoice vat_invoice_123',
			} )
		);
		await userEvent.click(
			await screen.findByRole( 'checkbox', {
				name: 'I have a valid VAT Number',
			} )
		);
		await userEvent.type(
			screen.getByLabelText( 'VAT Number' ),
			'DE123456789'
		);
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Continue' } )
			);
		} );
		await screen.findByLabelText( 'Business name' );

		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Confirm' } )
			);
		} );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Cancel' } )
		);
		resolveSave( {
			vat_number: 'DE123456789',
			name: 'Ada Bakery',
			address: '1 Market Street',
		} );

		await waitFor( () =>
			expect(
				screen.queryByRole( 'dialog', {
					name: 'Set your tax details',
				} )
			).not.toBeInTheDocument()
		);
		expect( openSpy ).not.toHaveBeenCalled();
	} );

	it( 'uses country-specific tax ID copy in the VAT modal controls', async () => {
		mockGetDocumentsAccount.mockResolvedValue( {
			...enabledAccount,
			documents: {
				enabled: true,
				has_submitted_vat_data: false,
				country: 'AU',
			},
		} );

		renderDocumentsPage();

		await userEvent.click(
			await screen.findByRole( 'button', {
				name: 'Download Tax Invoice vat_invoice_123',
			} )
		);

		expect(
			await screen.findByRole( 'checkbox', {
				name: 'I have a valid Australian Business Number',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( 'Australian Business Number' )
		).toBeInTheDocument();
	} );

	it( 'runs direct deep-link downloads only when document id and type are present', async () => {
		renderDocumentsPage( [
			'/woopayments/documents?document_id=vat_invoice_123&document_type=vat_invoice',
		] );

		await waitFor( () =>
			expect( openSpy ).toHaveBeenCalledWith(
				'https://site.test/wp-json/wc/v3/payments/documents/vat_invoice_123?_wpnonce=rest-nonce',
				'_self'
			)
		);
	} );

	// Client 11.1.0 documents/index.tsx:17.
	it.each( [ true, false ] )(
		'shows the documents test-mode notice only in test mode (test mode: %s)',
		async ( testMode ) => {
			mockAccountMode( testMode );

			renderDocumentsPage();

			await screen.findByRole( 'heading', { name: 'Documents' } );
			expect( await getTestModeNoticeText() ).toBe(
				testMode
					? 'Viewing test documents. To view live documents, disable test mode in WooPayments settings.'
					: null
			);
		}
	);
} );
