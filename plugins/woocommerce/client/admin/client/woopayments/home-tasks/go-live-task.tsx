/**
 * External dependencies
 */
import { createRoot } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';
import { WooOnboardingTaskListItem } from '@woocommerce/onboarding';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { getSettingsPaymentsProviderRouteUrl } from '~/woopayments/admin/utils';
import { registerPaymentsRuntimeTracksProperty } from '~/woopayments/tracks-runtime';

// PHP lists this task, and so loads this chunk, only while native owns the runtime.
registerPaymentsRuntimeTracksProperty();

// The PHP task `WooPaymentsGoLiveTask` decides visibility and copy; this fill only handles the click.
const GO_LIVE_TASK_ID = 'go-live-payments';
const MODAL_CONTAINER_ID = 'wcpay-golivemodal-container';

// Client 11.1.0 `overview/task-list/tasks/go-live-task.tsx:37-43` mounts the modal on its own root: the task list
// refetches after a click and would unmount a modal rendered inside it. The modal code loads only on click.
const openGoLiveModal = async () => {
	const { SetupLivePaymentsModal } = await import(
		/* webpackChunkName: "woopayments-go-live-modal" */ '~/woopayments/settings/account-mode-notice'
	);
	const container = document.createElement( 'div' );
	container.id = MODAL_CONTAINER_ID;
	document.body.appendChild( container );
	const root = createRoot( container );

	root.render(
		<SetupLivePaymentsModal
			from="WCPAY_GO_LIVE_TASK"
			source="wcpay-go-live-task"
			setupUrl={ getSettingsPaymentsProviderRouteUrl(
				'/woopayments/onboarding'
			) }
			onClose={ () =>
				// A root cannot unmount itself while handling its own event.
				setTimeout( () => {
					root.unmount();
					container.remove();
				} )
			}
		/>
	);
};

// Client 11.1.0 `overview/task-list/tasks/go-live-task.tsx:29-35`.
const onGoLiveTaskClick = () => {
	recordEvent( 'wcpay_overview_task_click', {
		task: 'go-live',
		source: 'wcpay-go-live-task',
	} );
	void openGoLiveModal();
};

const GoLiveTaskItem = () => (
	<WooOnboardingTaskListItem id={ GO_LIVE_TASK_ID }>
		{ ( { defaultTaskItem: DefaultTaskItem } ) => (
			<DefaultTaskItem onClick={ onGoLiveTaskClick } />
		) }
	</WooOnboardingTaskListItem>
);

registerPlugin( 'woocommerce-woopayments-task-go-live', {
	scope: 'woocommerce-tasks',
	render: GoLiveTaskItem,
} );
