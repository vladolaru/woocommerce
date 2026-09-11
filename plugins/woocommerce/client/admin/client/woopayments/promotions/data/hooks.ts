/**
 * External dependencies
 */
import { useDispatch, useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { registerWooPaymentsPmPromotionsStore, STORE_NAME } from './register';

export const usePmPromotions = () => {
	registerWooPaymentsPmPromotionsStore();

	const pmPromotions = useSelect( ( select ) =>
		select( STORE_NAME ).getPmPromotions()
	);
	const isLoading = useSelect( ( select ) => {
		select( STORE_NAME ).getPmPromotions();
		const isResolving =
			select( STORE_NAME ).isResolving( 'getPmPromotions' );
		const hasFinishedResolving =
			select( STORE_NAME ).hasFinishedResolution( 'getPmPromotions' );

		return isResolving || ! hasFinishedResolving;
	} );

	return {
		pmPromotions,
		isLoading,
	};
};

export const usePmPromotionActions = () => {
	registerWooPaymentsPmPromotionsStore();

	const { activatePmPromotion, dismissPmPromotion } =
		useDispatch( STORE_NAME );

	return {
		activatePmPromotion,
		dismissPmPromotion,
	};
};
