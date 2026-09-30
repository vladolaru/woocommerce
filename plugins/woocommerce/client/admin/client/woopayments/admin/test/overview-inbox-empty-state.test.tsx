/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { InboxNotifications } from '../overview/components/inbox-notifications';

jest.mock( '@wordpress/data', () => ( {
	...jest.requireActual( '@wordpress/data' ),
	useSelect: jest.fn(),
} ) );
jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

const mockUseSelect = useSelect as jest.Mock;

// Client 11.1.0 `overview/inbox-notifications/index.js:48-56,85-87`.
const CLIENT_EMPTY_INBOX_COPY =
	'As things begin to happen in your store your inbox will start to fill up. ' +
	"You'll see things like achievements, new feature announcements, extension recommendations and more!";

describe( 'overview inbox empty state', () => {
	it( 'shows the client empty-inbox card when there are no notes', () => {
		mockUseSelect.mockReturnValue( {
			isError: false,
			isLoading: false,
			notes: [],
		} );

		render( <InboxNotifications /> );

		const emptyCard = screen.getByText( CLIENT_EMPTY_INBOX_COPY );
		expect( emptyCard ).toHaveClass( 'woocommerce-empty-activity-card' );
		expect(
			screen.queryByText( 'No inbox notifications.' )
		).not.toBeInTheDocument();
	} );

	it( 'shows the client empty-inbox card when every note is deleted', () => {
		mockUseSelect.mockReturnValue( {
			isError: false,
			isLoading: false,
			notes: [
				{
					id: 10,
					name: 'wcpay-test-note',
					type: 'info',
					status: 'unactioned',
					title: 'Deleted note',
					content: '',
					date_created: '2026-06-19T10:00:00',
					date_created_gmt: '2026-06-19T07:00:00',
					is_deleted: true,
					is_read: false,
				},
			],
		} );

		render( <InboxNotifications /> );

		expect(
			screen.getByText( CLIENT_EMPTY_INBOX_COPY )
		).toBeInTheDocument();
		expect( screen.queryByText( 'Deleted note' ) ).not.toBeInTheDocument();
	} );
} );
