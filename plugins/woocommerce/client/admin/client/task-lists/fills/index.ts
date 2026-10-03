/**
 * External dependencies
 */
import { select, subscribe } from '@wordpress/data';
import { onboardingStore } from '@woocommerce/data';

/**
 * Internal dependencies
 */
import { isImportProduct } from './utils';
import './PaymentGatewaySuggestions';
import './shipping';
import './Marketing';
import './appearance';
import './tax';
import './deprecated-tasks';
import './launch-your-store';

const possiblyImportProductTask = async () => {
	if ( isImportProduct() ) {
		void import(
			/* webpackChunkName: "import-products" */ './import-products'
		);
	} else {
		void import( /* webpackChunkName: "products" */ './products' );
	}
};

void possiblyImportProductTask();

void import(
	/* webpackChunkName: "shipping-recommendation" */ './shipping-recommendation'
);

const WOOPAYMENTS_GO_LIVE_TASK_ID = 'go-live-payments';

// Reads only resolved task lists, so it never starts a task list request of its own.
const hasWooPaymentsGoLiveTask = () => {
	const store = select( onboardingStore );

	return (
		store.hasFinishedResolution( 'getTaskLists', [] ) &&
		( store.getTaskLists() || [] ).some( ( taskList ) =>
			taskList.tasks.some(
				( task ) => task.id === WOOPAYMENTS_GO_LIVE_TASK_ID
			)
		)
	);
};

// The PHP task `WooPaymentsGoLiveTask` decides visibility; its fill loads only when the task is listed.
const possiblyImportWooPaymentsGoLiveTask = () => {
	const importGoLiveTask = () =>
		void import(
			/* webpackChunkName: "woopayments-go-live-task" */ '~/woopayments/home-tasks/go-live-task'
		);

	if ( hasWooPaymentsGoLiveTask() ) {
		importGoLiveTask();
		return;
	}

	const unsubscribe = subscribe( () => {
		if ( hasWooPaymentsGoLiveTask() ) {
			unsubscribe();
			importGoLiveTask();
		}
	}, onboardingStore );
};

possiblyImportWooPaymentsGoLiveTask();
