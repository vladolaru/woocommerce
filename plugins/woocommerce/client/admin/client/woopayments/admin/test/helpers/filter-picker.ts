/**
 * External dependencies
 */
import { fireEvent, screen, within } from '@testing-library/react';

/**
 * The toggle of a list filter picker, found by its label ("Show", "Disputes match").
 *
 * @param label The filter label.
 */
export const getFilterPicker = ( label: string ) =>
	screen.getByLabelText( label, { selector: 'button' } );

/**
 * The filter picker's current choice, as its toggle shows it.
 *
 * @param label The filter label.
 */
export const getFilterPickerValue = ( label: string ) =>
	getFilterPicker( label ).textContent;

const openFilterPicker = ( label: string ) => {
	const toggle = getFilterPicker( label );

	if ( toggle.getAttribute( 'aria-expanded' ) !== 'true' ) {
		fireEvent.click( toggle );
	}

	return within( screen.getByRole( 'list', { name: label } ) );
};

/**
 * The choices a filter picker offers, in order; the picker is closed again afterwards.
 *
 * @param label The filter label.
 */
export const getFilterPickerChoices = ( label: string ) => {
	const choices = openFilterPicker( label )
		.getAllByRole( 'button' )
		.map( ( button ) => button.textContent );

	fireEvent.click( getFilterPicker( label ) );

	return choices;
};

/**
 * Opens a filter picker and clicks one of its choices.
 *
 * @param label  The filter label.
 * @param choice The choice text, such as "Advanced filters".
 */
export const chooseFilter = ( label: string, choice: string ) =>
	fireEvent.click(
		openFilterPicker( label ).getByRole( 'button', { name: choice } )
	);
