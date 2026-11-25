/**
 * External dependencies
 */
import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import { SettingsSaveBar } from '../settings-shell';
import {
	recordMutations,
	wasTextAddedToExistingNode,
} from './helpers/live-region';

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

const IDLE_TEXTS = [ 'Settings are up to date.', 'You have unsaved changes.' ];
const BLOCKING_MESSAGE =
	'A support phone number is required. Please enter a valid phone number.';

describe( 'SettingsSaveBar', () => {
	it( 'keeps Save inactive on a clean page and saves after a real change', async () => {
		const onSave = jest.fn();
		const { container, rerender } = render(
			<SettingsSaveBar
				isDirty={ false }
				isSaving={ false }
				onSave={ onSave }
			/>
		);
		const save = screen.getByRole( 'button', { name: 'Save changes' } );

		expect( save ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( save ).toHaveAccessibleDescription( '' );
		expect( container ).toHaveTextContent( /^Save changes$/ );
		IDLE_TEXTS.forEach( ( text ) =>
			expect( screen.queryByText( text ) ).not.toBeInTheDocument()
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
		expect( save ).toHaveAccessibleDescription( '' );
		expect( container ).toHaveTextContent( /^Save changes$/ );
		IDLE_TEXTS.forEach( ( text ) =>
			expect( screen.queryByText( text ) ).not.toBeInTheDocument()
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

	it( 'does not point Save at an empty status line', () => {
		const { container, rerender } = render( renderBar( false ) );
		const save = screen.getByRole( 'button', { name: 'Save changes' } );

		expect( save ).not.toHaveAttribute( 'aria-describedby' );
		expect(
			container.querySelector( '[aria-live="polite"]' )
		).toBeEmptyDOMElement();

		rerender( renderBar( true ) );

		expect( save ).not.toHaveAttribute( 'aria-describedby' );
	} );

	describe( 'when a field blocks the save', () => {
		const renderBlocked = ( isDirty: boolean, onSave = jest.fn() ) => (
			<>
				<input aria-label="Support phone" id="support-phone" />
				<SettingsSaveBar
					isDirty={ isDirty }
					isSaving={ false }
					validationError={ {
						inputId: 'support-phone',
						message: BLOCKING_MESSAGE,
					} }
					onSave={ onSave }
				/>
			</>
		);

		it( 'names the problem, describes Save by it, and moves focus to the field', async () => {
			const onSave = jest.fn();
			render( renderBlocked( true, onSave ) );
			const save = screen.getByRole( 'button', { name: 'Save changes' } );

			expect( screen.getByText( BLOCKING_MESSAGE ) ).toHaveClass(
				'is-error'
			);
			expect( save ).toHaveAccessibleDescription( BLOCKING_MESSAGE );
			expect( save ).toHaveAttribute( 'aria-disabled', 'true' );

			await userEvent.click( save );

			expect( screen.getByLabelText( 'Support phone' ) ).toHaveFocus();
			expect( onSave ).not.toHaveBeenCalled();
			expect( leavePage() ).toBe( true );
		} );

		it( 'adds the message to a status line that is already on the page', () => {
			const { container, rerender } = render( renderBlocked( false ) );
			const region = container.querySelector(
				'[aria-live="polite"]'
			) as HTMLElement;

			const records = recordMutations( () =>
				rerender( renderBlocked( true ) )
			);

			expect( region ).toHaveTextContent( BLOCKING_MESSAGE );
			expect(
				wasTextAddedToExistingNode( records, region, BLOCKING_MESSAGE )
			).toBe( true );
		} );

		it( 'leaves Save inactive and the message gone once the change is undone', () => {
			const { rerender } = render( renderBlocked( true ) );
			const save = screen.getByRole( 'button', { name: 'Save changes' } );

			rerender( renderBlocked( false ) );

			expect( screen.queryByText( BLOCKING_MESSAGE ) ).toBeNull();
			expect( save ).toHaveAttribute( 'aria-disabled', 'true' );
			expect( leavePage() ).toBe( false );
		} );
	} );
} );
