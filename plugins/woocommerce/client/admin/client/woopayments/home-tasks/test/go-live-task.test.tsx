/**
 * External dependencies
 */
import {
	render,
	screen,
	waitFor,
	waitForElementToBeRemoved,
	within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SlotFillProvider } from '@wordpress/components';
import { PluginArea } from '@wordpress/plugins';
import { WooOnboardingTaskListItem } from '@woocommerce/onboarding';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
// The WC Home task list loads its fills from here; importing it proves the go-live fill is wired in.
import '~/task-lists/fills';

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );
// Keep the other core task fills out of this test; only the WooPayments go-live fill matters here.
jest.mock( '~/task-lists/fills/PaymentGatewaySuggestions', () => ( {} ) );
jest.mock( '~/task-lists/fills/shipping', () => ( {} ) );
jest.mock( '~/task-lists/fills/Marketing', () => ( {} ) );
jest.mock( '~/task-lists/fills/appearance', () => ( {} ) );
jest.mock( '~/task-lists/fills/tax', () => ( {} ) );
jest.mock( '~/task-lists/fills/deprecated-tasks', () => ( {} ) );
jest.mock( '~/task-lists/fills/launch-your-store', () => ( {} ) );
jest.mock( '~/task-lists/fills/products', () => ( {} ) );
jest.mock( '~/task-lists/fills/import-products', () => ( {} ) );
jest.mock( '~/task-lists/fills/shipping-recommendation', () => ( {} ) );
jest.mock( '~/task-lists/fills/utils', () => ( {
	isImportProduct: () => false,
} ) );

// Stands in for core's DefaultTaskItem, which the task list hands to a fill (`task-list-item.tsx:268-276`).
const DefaultTaskItem = ( { onClick }: { onClick?: () => void } ) => (
	<button type="button" onClick={ onClick }>
		Activate payments
	</button>
);

const renderTaskSlot = ( id: string ) =>
	render(
		<SlotFillProvider>
			<WooOnboardingTaskListItem.Slot
				id={ id }
				fillProps={ { defaultTaskItem: DefaultTaskItem } }
			/>
			<PluginArea scope="woocommerce-tasks" />
		</SlotFillProvider>
	);

// Client 11.1.0 `overview/task-list/tasks/go-live-task.tsx:29-44`: a click records the task click and opens the
// live payments modal on its own root; `components/sandbox-mode-switch-to-live-notice/modal/index.tsx` is the modal.
describe( 'WooPayments go-live WC Home task', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		Object.defineProperty( window, 'wcSettings', {
			configurable: true,
			value: { adminUrl: 'https://example.test/wp-admin/' },
		} );
	} );

	afterEach( async () => {
		const dialog = screen.queryByRole( 'dialog' );
		if ( dialog ) {
			await userEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Close' } )
			);
			await waitForElementToBeRemoved( () =>
				screen.queryByRole( 'dialog' )
			);
		}
	} );

	it( 'takes over only the go-live task', () => {
		renderTaskSlot( 'update-business-details' );

		expect(
			screen.queryByRole( 'button', { name: 'Activate payments' } )
		).not.toBeInTheDocument();
	} );

	it( 'records the click and opens the live payments modal outside the task list', async () => {
		const { container } = renderTaskSlot( 'go-live-payments' );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Activate payments' } )
		);

		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_overview_task_click',
			{ task: 'go-live', source: 'wcpay-go-live-task' }
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Activate payments on your store',
		} );
		// The task list refetches after a click, so the modal must not live inside it.
		expect( container ).not.toContainElement( dialog );
		expect(
			within( dialog ).getByText(
				'Your test account will be deactivated, but your transactions can be found in your order history.'
			)
		).toBeInTheDocument();

		const activateUrl = new URL(
			within( dialog )
				.getByRole( 'link', { name: 'Activate payments' } )
				.getAttribute( 'href' ) ?? ''
		);
		expect( activateUrl.origin + activateUrl.pathname ).toBe(
			'https://example.test/wp-admin/admin.php'
		);
		expect( activateUrl.searchParams.get( 'page' ) ).toBe( 'wc-settings' );
		expect( activateUrl.searchParams.get( 'tab' ) ).toBe( 'checkout' );
		expect( activateUrl.searchParams.get( 'path' ) ).toBe(
			'/woopayments/onboarding'
		);
		expect( activateUrl.searchParams.get( 'source' ) ).toBe(
			'wcpay-go-live-task'
		);
		expect( activateUrl.searchParams.get( 'from' ) ).toBe(
			'wcpay-setup-live-payments'
		);
	} );

	it( 'records the modal exit with the task attribution and closes it', async () => {
		renderTaskSlot( 'go-live-payments' );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Activate payments' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Activate payments on your store',
		} );
		await userEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Close' } )
		);

		// The modal reports the close after its exit animation.
		await waitFor( () =>
			expect( recordEvent ).toHaveBeenCalledWith(
				'wcpay_setup_live_payments_modal_exit',
				{ from: 'WCPAY_GO_LIVE_TASK', source: 'wcpay-go-live-task' }
			)
		);
		await waitFor( () =>
			expect(
				document.getElementById( 'wcpay-golivemodal-container' )
			).toBeNull()
		);
		expect(
			screen.queryByRole( 'dialog', {
				name: 'Activate payments on your store',
			} )
		).not.toBeInTheDocument();
	} );
} );
