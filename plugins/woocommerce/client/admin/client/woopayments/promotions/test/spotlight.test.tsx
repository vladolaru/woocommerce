/**
 * External dependencies
 */
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { SpotlightPromotion } from '../spotlight';
import { usePmPromotionActions, usePmPromotions } from '../data/hooks';
import type { PmPromotion } from '../types';

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

jest.mock( '../data/register', () => ( {
	registerWooPaymentsPmPromotionsStore: jest.fn(),
} ) );

jest.mock( '../data/hooks', () => ( {
	usePmPromotions: jest.fn(),
	usePmPromotionActions: jest.fn(),
} ) );

const mockUsePmPromotions = usePmPromotions as jest.MockedFunction<
	typeof usePmPromotions
>;
const mockUsePmPromotionActions = usePmPromotionActions as jest.MockedFunction<
	typeof usePmPromotionActions
>;
const mockRecordEvent = recordEvent as jest.MockedFunction<
	typeof recordEvent
>;
const activatePmPromotion = jest.fn();
const dismissPmPromotion = jest.fn();

const spotlightPromotion: PmPromotion = {
	id: 'affirm-spotlight',
	promo_id: 'affirm_2026',
	payment_method: 'affirm',
	type: 'spotlight',
	title: 'Offer Affirm and save',
	description: '<p>Enable Affirm for eligible customers.</p>',
	cta_label: 'Activate Affirm',
	tc_url: 'https://example.com/terms',
	tc_label: 'Promotion terms',
	badge_text: 'Limited time',
	badge_type: 'primary',
	footnote: '<p>Terms apply.</p>',
	image: 'https://example.com/promo.png',
};

const viewEvents = () =>
	mockRecordEvent.mock.calls.filter(
		( [ eventName ] ) => eventName === 'wcpay_payment_method_promotion_view'
	);

// Client 11.1.0 `components/spotlight/index.tsx:66-101`: the card appears 4 seconds after the page renders.
const showSpotlight = async () => {
	await act( async () => {
		jest.advanceTimersByTime( 4000 );
	} );
	await act( async () => {
		jest.runOnlyPendingTimers();
	} );
};

describe( 'SpotlightPromotion', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		mockRecordEvent.mockClear();
		activatePmPromotion.mockReset();
		dismissPmPromotion.mockReset();
		mockUsePmPromotions.mockReturnValue( {
			pmPromotions: [ spotlightPromotion ],
			isLoading: false,
		} );
		mockUsePmPromotionActions.mockReturnValue( {
			activatePmPromotion,
			dismissPmPromotion,
		} );
		window.history.pushState(
			{},
			'',
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Foverview'
		);
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'renders nothing while PM promotions are still loading', async () => {
		mockUsePmPromotions.mockReturnValue( {
			pmPromotions: [],
			isLoading: true,
		} );

		const { container } = render( <SpotlightPromotion /> );
		await showSpotlight();

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'shows the spotlight dialog only after 4 seconds and records the view then', async () => {
		render( <SpotlightPromotion /> );

		await act( async () => {
			jest.advanceTimersByTime( 3999 );
		} );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
		expect( viewEvents() ).toHaveLength( 0 );

		await showSpotlight();

		const dialog = screen.getByRole( 'dialog', {
			name: 'Offer Affirm and save',
		} );
		expect( dialog ).toHaveAttribute( 'aria-modal', 'true' );
		expect( dialog ).toHaveFocus();
		expect( viewEvents() ).toEqual( [
			[
				'wcpay_payment_method_promotion_view',
				{
					promo_id: 'affirm_2026',
					payment_method: 'affirm',
					display_context: 'spotlight',
					source: 'wcpay-overview',
				},
			],
		] );
		expect( screen.getByText( 'Limited time' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Enable Affirm for eligible customers.' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Terms apply.' ) ).toBeInTheDocument();
		expect( dialog.querySelector( 'img' ) ).toHaveAttribute(
			'src',
			'https://example.com/promo.png'
		);
	} );

	// Client 11.1.0 `components/spotlight/index.tsx:225-233,376-388` and `promotions/spotlight/index.tsx:91-115`.
	it( 'puts the terms button before the CTA and opens the terms in a new window', async () => {
		const openSpy = jest
			.spyOn( window, 'open' )
			.mockImplementation( () => null );
		render( <SpotlightPromotion /> );
		await showSpotlight();

		const footerButtons = Array.from(
			screen
				.getByRole( 'dialog' )
				.querySelectorAll( '.components-card__footer button' )
		).map( ( button ) => button.textContent );
		expect( footerButtons ).toEqual( [
			'Promotion terms',
			'Activate Affirm',
		] );
		expect(
			screen.queryByRole( 'link', { name: /Promotion terms/ } )
		).not.toBeInTheDocument();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Promotion terms' } )
		);

		expect( openSpy ).toHaveBeenCalledWith(
			'https://example.com/terms',
			'_blank',
			'noopener,noreferrer'
		);
		expect( mockRecordEvent ).toHaveBeenCalledWith(
			'wcpay_payment_method_promotion_link_click',
			expect.objectContaining( {
				promo_id: 'affirm_2026',
				link_type: 'terms',
			} )
		);
		openSpy.mockRestore();
	} );

	it( 'does not open an unsafe terms URL', async () => {
		const openSpy = jest
			.spyOn( window, 'open' )
			.mockImplementation( () => null );
		mockUsePmPromotions.mockReturnValue( {
			pmPromotions: [
				{
					...spotlightPromotion,
					tc_url: 'javascript:alert(1)',
				},
			],
			isLoading: false,
		} );
		render( <SpotlightPromotion /> );
		await showSpotlight();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Promotion terms' } )
		);

		expect( openSpy ).not.toHaveBeenCalled();
		openSpy.mockRestore();
	} );

	it( 'activates the promotion and closes without dismissing it', async () => {
		render( <SpotlightPromotion /> );
		await showSpotlight();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Activate Affirm' } )
		);
		await act( async () => {
			jest.advanceTimersByTime( 300 );
		} );

		expect( activatePmPromotion ).toHaveBeenCalledWith(
			'affirm-spotlight'
		);
		expect( mockRecordEvent ).toHaveBeenCalledWith(
			'wcpay_payment_method_promotion_activate_click',
			expect.objectContaining( {
				promo_id: 'affirm_2026',
				source: 'wcpay-overview',
			} )
		);
		expect( dismissPmPromotion ).not.toHaveBeenCalled();
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );

	it( 'dismisses the promotion from the Close button and returns focus', async () => {
		render(
			<>
				<button type="button">Page action</button>
				<SpotlightPromotion />
			</>
		);
		screen.getByRole( 'button', { name: 'Page action' } ).focus();
		await showSpotlight();

		expect( screen.getByRole( 'dialog' ) ).toHaveFocus();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Close' } )
		);
		expect( mockRecordEvent ).toHaveBeenCalledWith(
			'wcpay_payment_method_promotion_dismiss_click',
			expect.objectContaining( { promo_id: 'affirm_2026' } )
		);
		await act( async () => {
			jest.advanceTimersByTime( 300 );
		} );

		expect( dismissPmPromotion ).toHaveBeenCalledWith( 'affirm-spotlight' );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Page action' } )
		).toHaveFocus();
	} );

	it( 'dismisses the promotion on Escape', async () => {
		render( <SpotlightPromotion /> );
		await showSpotlight();

		await userEvent.keyboard( '{Escape}' );
		await act( async () => {
			jest.advanceTimersByTime( 300 );
		} );

		expect( dismissPmPromotion ).toHaveBeenCalledWith( 'affirm-spotlight' );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps Tab within the dialog', async () => {
		render( <SpotlightPromotion /> );
		await showSpotlight();

		screen.getByRole( 'button', { name: 'Activate Affirm' } ).focus();
		await userEvent.tab();
		expect( screen.getByRole( 'button', { name: 'Close' } ) ).toHaveFocus();

		await userEvent.tab( { shift: true } );
		expect(
			screen.getByRole( 'button', { name: 'Activate Affirm' } )
		).toHaveFocus();
	} );
} );
