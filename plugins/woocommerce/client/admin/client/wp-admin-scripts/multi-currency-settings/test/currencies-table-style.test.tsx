/**
 * External dependencies
 */
import { render, screen, within } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { MultiCurrencySettingsApp } from '../app';
import type { MultiCurrencyCurrency } from '../types';
import { hasStyleRule } from '../../../woopayments/admin/test/helpers/style-rules';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '../store-settings', () => ( {
	StoreLevelSettings: () => null,
} ) );

const STYLESHEET = '../../wp-admin-scripts/multi-currency-settings/style.scss';

const currency = (
	code: string,
	name: string,
	overrides: Partial< MultiCurrencyCurrency > = {}
) =>
	( {
		id: code.toLowerCase(),
		code,
		name,
		rate: 0.8,
		symbol: code === 'USD' ? '$' : '€',
		symbol_position: 'left',
		is_zero_decimal: false,
		charm: 0,
		rounding: '0',
		last_updated: null,
		...overrides,
	} ) as MultiCurrencyCurrency;

// Client 11.1.0 `enabled-currencies-list`: at 360px every value stays readable. Here the actions wrap inside their
// cell instead of spilling over the rate, and words break only when they cannot fit a line.
describe( 'Enabled currencies table on a phone', () => {
	beforeEach( async () => {
		const usd = currency( 'USD', 'United States (US) dollar', {
			rate: 1,
			is_default: true,
		} );
		const eur = currency( 'EUR', 'Euro' );
		( apiFetch as unknown as jest.Mock ).mockResolvedValueOnce( {
			available: { USD: usd, EUR: eur },
			enabled: { USD: usd, EUR: eur },
			default: usd,
			automatic_rates: { available: true, source: 'woopayments' },
		} );
		render( <MultiCurrencySettingsApp /> );
		await screen.findByRole( 'button', { name: 'Manage Euro settings' } );
	} );

	it( 'wraps Manage and Remove inside the actions cell', () => {
		const actions = screen.getByRole( 'button', {
			name: 'Manage Euro settings',
		} ).parentElement;
		if ( ! actions ) {
			throw new Error( 'The Manage button has no actions wrapper.' );
		}

		expect(
			within( actions ).getByRole( 'button', {
				name: 'Remove Euro as an enabled currency',
			} )
		).toBeInTheDocument();
		expect( hasStyleRule( actions, STYLESHEET, 'flex-wrap', 'wrap' ) ).toBe(
			true
		);
	} );

	it( 'breaks words only when they cannot fit their line', () => {
		const header = screen.getByRole( 'columnheader', {
			name: 'Exchange rate',
		} );

		expect(
			hasStyleRule( header, STYLESHEET, 'overflow-wrap', 'anywhere' )
		).toBe( false );
		expect(
			hasStyleRule( header, STYLESHEET, 'overflow-wrap', 'break-word' )
		).toBe( true );
	} );

	it( 'gives the rate and actions more room below 600px', () => {
		const [ , rateHeader, actionsHeader ] =
			screen.getAllByRole( 'columnheader' );

		expect(
			hasStyleRule(
				rateHeader,
				STYLESHEET,
				'padding-inline',
				'8px',
				'max-width: 600px'
			)
		).toBe( true );
		expect(
			hasStyleRule(
				actionsHeader,
				STYLESHEET,
				'width',
				'25%',
				'max-width: 600px'
			)
		).toBe( true );
	} );
} );
