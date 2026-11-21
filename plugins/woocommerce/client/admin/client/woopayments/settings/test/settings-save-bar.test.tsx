/**
 * External dependencies
 */
import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import { SettingsSaveBar } from '../settings-shell';

const leavePage = () => {
	const event = new Event( 'beforeunload', { cancelable: true } );
	window.dispatchEvent( event );

	return event.defaultPrevented;
};

const renderBar = ( isDirty: boolean ) => (
	<SettingsSaveBar
		isDirty={ isDirty }
		isSaving={ false }
		onSave={ jest.fn() }
	/>
);

describe( 'SettingsSaveBar', () => {
	it( 'keeps Save inactive on a clean page and saves after a real change', async () => {
		const onSave = jest.fn();
		const { rerender } = render(
			<SettingsSaveBar
				isDirty={ false }
				isSaving={ false }
				onSave={ onSave }
			/>
		);
		const save = screen.getByRole( 'button', { name: 'Save changes' } );

		expect( save ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( save ).toHaveAccessibleDescription(
			'Settings are up to date.'
		);
		fireEvent.click( save );
		expect( onSave ).not.toHaveBeenCalled();

		rerender(
			<SettingsSaveBar
				isDirty={ true }
				isSaving={ false }
				onSave={ onSave }
			/>
		);

		expect( save ).not.toHaveAttribute( 'aria-disabled' );
		expect( save ).toHaveAccessibleDescription(
			'You have unsaved changes.'
		);
		await userEvent.click( save );
		expect( onSave ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'asks before leaving only while there are unsaved changes', () => {
		const { rerender, unmount } = render( renderBar( false ) );
		expect( leavePage() ).toBe( false );

		rerender( renderBar( true ) );
		expect( leavePage() ).toBe( true );

		// The change is saved or undone, so the page reads clean again.
		rerender( renderBar( false ) );
		expect( leavePage() ).toBe( false );

		unmount();
	} );
} );
