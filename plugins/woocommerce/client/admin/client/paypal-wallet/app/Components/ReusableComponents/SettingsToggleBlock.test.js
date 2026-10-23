import { render, screen } from '@testing-library/react';

import SettingsToggleBlock from './SettingsToggleBlock';

describe( 'SettingsToggleBlock', () => {
	it( 'names its toggle after the label it shows', () => {
		render(
			<SettingsToggleBlock
				label="Enable Sandbox Mode"
				description="Test with sample data."
				isToggled={ false }
				setToggled={ jest.fn() }
			/>
		);

		expect(
			screen.getByRole( 'checkbox', { name: 'Enable Sandbox Mode' } )
		).not.toBeChecked();
	} );

	it( 'reports the new state when the toggle or its label is clicked', () => {
		const setToggled = jest.fn();
		render(
			<SettingsToggleBlock
				label="Manually Connect"
				isToggled={ false }
				setToggled={ setToggled }
			/>
		);

		screen.getByRole( 'checkbox', { name: 'Manually Connect' } ).click();
		screen.getByText( 'Manually Connect' ).click();

		expect( setToggled ).toHaveBeenNthCalledWith( 1, true );
		expect( setToggled ).toHaveBeenNthCalledWith( 2, true );
	} );
} );
