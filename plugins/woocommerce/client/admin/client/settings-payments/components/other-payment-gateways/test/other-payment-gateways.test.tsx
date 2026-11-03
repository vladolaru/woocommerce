/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { SuggestedPaymentsExtension } from '@woocommerce/data';

/**
 * Internal dependencies
 */
import { OtherPaymentGateways } from '../other-payment-gateways';

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

const suggestion = (
	plugin: Record< string, string >
): SuggestedPaymentsExtension =>
	( {
		id: 'woopayments',
		_priority: 1,
		_type: 'psp',
		title: 'Accept payments with Woo',
		description: 'Payments',
		short_description: '',
		plugin,
		image: '',
		icon: '',
		links: [],
		tags: [],
		category: '',
		_links: {},
	} ) as unknown as SuggestedPaymentsExtension;

const renderExpanded = ( extension: SuggestedPaymentsExtension ) => {
	const setUpPlugin = jest.fn();
	window.history.replaceState( {}, '', '/?other_pes_section=expanded' );
	render(
		<OtherPaymentGateways
			suggestions={ [ extension ] }
			suggestionCategories={ [
				{
					id: 'psp',
					_priority: 1,
					title: 'Payment providers',
					description: '',
				},
			] }
			installingPlugin={ null }
			setUpPlugin={ setUpPlugin }
			isFetching={ false }
			morePaymentOptionsLink={ <a href="/more">More</a> }
		/>
	);
	return setUpPlugin;
};

describe( 'OtherPaymentGateways', () => {
	afterEach( () => {
		window.history.replaceState( {}, '', '/' );
	} );

	it.each( [
		{
			name: 'the hidden built-in WooPayments suggestion',
			plugin: { status: 'not_installed' },
			badge: 'Built in',
		},
		{
			name: "the WooPayments extension's suggestion",
			plugin: { slug: 'woocommerce-payments', status: 'not_installed' },
			badge: 'Official',
		},
	] )( 'shows the "$badge" badge on $name', ( { plugin, badge } ) => {
		renderExpanded( suggestion( plugin ) );

		expect(
			screen.getByRole( 'button', {
				name: new RegExp( `badge ${ badge }$` ),
			} )
		).toBeInTheDocument();
	} );

	it.each( [
		{
			name: 'the hidden built-in WooPayments suggestion',
			plugin: { status: 'not_installed' },
			label: 'Set up',
		},
		{
			name: "the WooPayments extension's suggestion",
			plugin: { slug: 'woocommerce-payments', status: 'not_installed' },
			label: 'Install',
		},
		{
			name: "the installed WooPayments extension's suggestion",
			plugin: { slug: 'woocommerce-payments', status: 'installed' },
			label: 'Activate',
		},
	] )(
		'labels the card button "$label" for $name and sets it up on click',
		async ( { plugin, label } ) => {
			const extension = suggestion( plugin );
			const setUpPlugin = renderExpanded( extension );

			await userEvent.click(
				screen.getByRole( 'button', { name: label } )
			);

			expect( setUpPlugin ).toHaveBeenCalledWith(
				extension,
				null,
				null,
				'wc_settings_payments__other_payment_options'
			);
		}
	);
} );
