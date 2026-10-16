/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import { ExportButton } from '../money-movement/table';

describe( 'WooPayments list ExportButton', () => {
	it( 'keeps keyboard focus while an export runs and ignores further presses', async () => {
		const onClick = jest.fn();
		const { rerender } = render( <ExportButton onClick={ onClick } /> );
		const button = screen.getByRole( 'button', { name: 'Export' } );
		button.focus();

		rerender( <ExportButton onClick={ onClick } isBusy disabled /> );
		await userEvent.click( button );

		expect( button ).toHaveFocus();
		expect( button ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( onClick ).not.toHaveBeenCalled();
	} );
} );
