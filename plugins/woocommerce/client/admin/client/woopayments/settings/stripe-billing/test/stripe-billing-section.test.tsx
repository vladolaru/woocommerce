/**
 * External dependencies
 */
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import { StripeBillingSection } from '../stripe-billing-section';

type SectionState = {
	isStripeBillingEnabled: boolean;
	isManualCaptureEnabled: boolean;
	isMigrating: boolean;
	migratedCount: number;
	subscriptionCount: number;
	isResolvingMigrateRequest: boolean;
	hasResolvedMigrateRequest: boolean;
	isSaving: boolean;
};

const mockUpdateIsStripeBillingEnabled = jest.fn();
const mockStartMigration = jest.fn();
let mockState: SectionState;

jest.mock( '../../data/hooks', () => ( {
	useStripeBilling: () => [
		mockState.isStripeBillingEnabled,
		mockUpdateIsStripeBillingEnabled,
	],
	useManualCapture: () => [ mockState.isManualCaptureEnabled, jest.fn() ],
	useStripeBillingMigration: () => [
		mockState.isMigrating,
		mockState.migratedCount,
		mockState.subscriptionCount,
		mockStartMigration,
		mockState.isResolvingMigrateRequest,
		mockState.hasResolvedMigrateRequest,
	],
	useSettings: () => ( { isLoading: false, isSaving: mockState.isSaving } ),
} ) );

const MIGRATE_OPTION =
	/There are 2 customer subscriptions using Stripe Billing for payment processing. We suggest migrating them to on-site billing powered by the Woo Subscriptions plugin./;
const MIGRATE_AUTOMATICALLY =
	/There are currently 2 customer subscriptions using Stripe Billing for payment processing. These subscriptions will be automatically migrated/;
const MIGRATION_PROGRESS =
	'2 customer subscriptions are being migrated from Stripe off-site billing to billing powered by Woo Subscriptions and WooPayments.';
const MIGRATION_COMPLETED =
	'3 customer subscriptions were successfully migrated from Stripe off-site billing to on-site billing powered by Woo Subscriptions and WooPayments.';

// Notices also announce their text in a live region outside the section, so text is looked up inside the section.
const Section = () => (
	<div data-testid="stripe-billing-section">
		<StripeBillingSection />
	</div>
);
const section = () => within( screen.getByTestId( 'stripe-billing-section' ) );

const renderSection = ( state: Partial< SectionState > = {} ) => {
	mockState = {
		isStripeBillingEnabled: false,
		isManualCaptureEnabled: false,
		isMigrating: false,
		migratedCount: 0,
		subscriptionCount: 0,
		isResolvingMigrateRequest: false,
		hasResolvedMigrateRequest: false,
		isSaving: false,
		...state,
	};

	return render( <Section /> );
};

// Client 11.1.0 `client/settings/advanced-settings/stripe-billing-section.tsx`, `stripe-billing-toggle.tsx` and `stripe-billing-notices/*`.
describe( 'StripeBillingSection', () => {
	beforeEach( () => {
		mockUpdateIsStripeBillingEnabled.mockReset();
		mockStartMigration.mockReset();
	} );

	it( 'turns Stripe Billing on from the toggle', async () => {
		renderSection();

		await userEvent.click(
			section().getByRole( 'checkbox', {
				name: 'Enable Stripe Billing for future subscriptions',
			} )
		);

		expect( mockUpdateIsStripeBillingEnabled ).toHaveBeenCalledWith( true );
		expect(
			section().getByText(
				/By enabling this setting, future WooPayments subscription purchases will utilize Stripe Billing/
			)
		).toBeInTheDocument();
	} );

	it( 'explains the manual capture conflict instead of turning Stripe Billing on', async () => {
		renderSection( { isManualCaptureEnabled: true } );

		await userEvent.click(
			section().getByRole( 'checkbox', {
				name: 'Enable Stripe Billing for future subscriptions',
			} )
		);

		const dialog = screen.getByRole( 'dialog', {
			name: 'Enable Stripe Billing',
		} );
		expect( dialog ).toHaveTextContent(
			'Stripe Billing is not available with manual capture enabled. To use Stripe Billing, disable manual capture in your settings list.'
		);
		expect( mockUpdateIsStripeBillingEnabled ).not.toHaveBeenCalled();

		await userEvent.click(
			within( dialog ).getByRole( 'button', { name: 'OK' } )
		);
		expect(
			screen.queryByRole( 'dialog', { name: 'Enable Stripe Billing' } )
		).not.toBeInTheDocument();
	} );

	it( 'offers to migrate when Stripe Billing is off with subscriptions left, and starts the migration', async () => {
		const { rerender } = renderSection( { subscriptionCount: 2 } );

		expect( section().getByText( MIGRATE_OPTION ) ).toBeInTheDocument();
		expect(
			section().getByText(
				/Alternatively, you can enable this setting and future WooPayments subscription purchases will also utilize Stripe Billing/
			)
		).toBeInTheDocument();

		await userEvent.click(
			section().getByRole( 'button', { name: 'Begin migration' } )
		);
		expect( mockStartMigration ).toHaveBeenCalledTimes( 1 );

		mockState.hasResolvedMigrateRequest = true;
		rerender( <Section /> );

		expect(
			section().queryByText( MIGRATE_OPTION )
		).not.toBeInTheDocument();
		expect( section().getByText( MIGRATION_PROGRESS ) ).toBeInTheDocument();
	} );

	it( 'does not offer to migrate when Stripe Billing is on, nothing is left, or a migration runs', () => {
		const enabled = renderSection( {
			subscriptionCount: 2,
			isStripeBillingEnabled: true,
		} );
		expect(
			section().queryByText( MIGRATE_OPTION )
		).not.toBeInTheDocument();
		enabled.unmount();

		const empty = renderSection( { subscriptionCount: 0 } );
		expect(
			section().queryByRole( 'button', { name: 'Begin migration' } )
		).not.toBeInTheDocument();
		empty.unmount();

		renderSection( { subscriptionCount: 2, isMigrating: true } );
		expect(
			section().queryByText( MIGRATE_OPTION )
		).not.toBeInTheDocument();
		expect(
			section().queryByText( MIGRATE_AUTOMATICALLY )
		).not.toBeInTheDocument();
		expect( section().getByText( MIGRATION_PROGRESS ) ).toBeInTheDocument();
	} );

	it( 'withdraws the migration offer as soon as Stripe Billing is turned on, before saving', () => {
		const { rerender } = renderSection( { subscriptionCount: 2 } );
		expect( section().getByText( MIGRATE_OPTION ) ).toBeInTheDocument();

		mockState.isStripeBillingEnabled = true;
		rerender( <Section /> );

		expect(
			section().queryByText( MIGRATE_OPTION )
		).not.toBeInTheDocument();
		expect(
			section().getByText(
				/By enabling this setting, future WooPayments subscription purchases will utilize Stripe Billing/
			)
		).toBeInTheDocument();
	} );

	it( 'warns that turning Stripe Billing off migrates the subscriptions, then shows the migration once saved', () => {
		const { rerender } = renderSection( {
			subscriptionCount: 2,
			isStripeBillingEnabled: true,
		} );
		expect(
			section().queryByText( MIGRATE_AUTOMATICALLY )
		).not.toBeInTheDocument();

		mockState.isStripeBillingEnabled = false;
		rerender( <Section /> );
		expect(
			section().getByText( MIGRATE_AUTOMATICALLY )
		).toBeInTheDocument();
		expect(
			section().queryByText( MIGRATION_PROGRESS )
		).not.toBeInTheDocument();

		mockState.isSaving = true;
		rerender( <Section /> );
		mockState.isSaving = false;
		rerender( <Section /> );

		expect(
			section().queryByText( MIGRATE_AUTOMATICALLY )
		).not.toBeInTheDocument();
		expect( section().getByText( MIGRATION_PROGRESS ) ).toBeInTheDocument();
	} );

	it( 'lets the migration progress notice be dismissed', async () => {
		renderSection( { subscriptionCount: 2, isMigrating: true } );

		await userEvent.click(
			section().getByRole( 'button', { name: 'Close' } )
		);

		expect(
			section().queryByText( MIGRATION_PROGRESS )
		).not.toBeInTheDocument();
	} );

	it( 'reports completed migrations while Stripe Billing is off and no migration runs', async () => {
		const { unmount } = renderSection( { migratedCount: 3 } );
		expect(
			section().getByText( MIGRATION_COMPLETED )
		).toBeInTheDocument();

		await userEvent.click(
			section().getByRole( 'button', { name: 'Close' } )
		);
		expect(
			section().queryByText( MIGRATION_COMPLETED )
		).not.toBeInTheDocument();
		unmount();

		const enabled = renderSection( {
			migratedCount: 3,
			isStripeBillingEnabled: true,
		} );
		expect(
			section().queryByText( MIGRATION_COMPLETED )
		).not.toBeInTheDocument();
		enabled.unmount();

		renderSection( { migratedCount: 3, isMigrating: true } );
		expect(
			section().queryByText( MIGRATION_COMPLETED )
		).not.toBeInTheDocument();
	} );
} );
