import { useCallback, useMemo } from '@wordpress/element';

import {
	CommonHooks,
	PayLaterMessagingHooks,
	PaymentHooks,
	SettingsHooks,
	StylingHooks,
	TodosHooks,
} from '../data';

export const useStoreManager = () => {
	const { withActivity } = CommonHooks.useBusyState();

	const paymentStore = PaymentHooks.useStore();
	const settingsStore = SettingsHooks.useStore();
	const stylingStore = StylingHooks.useStore();
	const todosStore = TodosHooks.useStore();
	const payLaterStore = PayLaterMessagingHooks.useStore();

	const coreStoreActions = useMemo(
		() => [
			{
				key: 'methods',
				message: 'Process payment methods',
				store: paymentStore,
			},
			{
				key: 'settings',
				message: 'Process the settings',
				store: settingsStore,
			},
			{
				key: 'styling',
				message: 'Process styling details',
				store: stylingStore,
			},
			{
				key: 'todos',
				message: 'Process todos state',
				store: todosStore,
			},
			{
				key: 'pay-later-messaging',
				message: 'Process pay later messaging details',
				store: payLaterStore,
			},
		],
		[ payLaterStore, paymentStore, settingsStore, stylingStore, todosStore ]
	);

	const persistAll = useCallback( () => {
		/**
		 * Executes onSave on TabPayLaterMessaging component.
		 *
		 * Todo: find a better way for this, because it's highly unreliable
		 *       (it only works when the user is still on the "Pay Later Messaging" tab)
		 */
		document.getElementById( 'configurator-publishButton' )?.click();

		coreStoreActions.forEach( ( { key, message, store } ) => {
			withActivity( `persist-${ key }`, message, store.persist );
		} );
	}, [ coreStoreActions, withActivity ] );

	const refreshAll = useCallback( () => {
		coreStoreActions.forEach( ( { key, message, store } ) => {
			withActivity( `refresh-${ key }`, message, store.refresh );
		} );
	}, [ coreStoreActions, withActivity ] );

	return {
		persistAll,
		refreshAll,
	};
};
