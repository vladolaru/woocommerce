/**
 * External dependencies
 */
import { useEffect } from '@wordpress/element';
import { useLocation } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { getWooPaymentsDispute } from './data';
import type { WooPaymentsDispute } from './types';
import { getTransactionDetailsRoute } from './utils';
import { getSettingsPaymentsProviderRouteUrl } from '../utils';

const DISPUTES_LIST_ROUTE = '/woopayments/disputes';

const hasTransactionReference = ( dispute: WooPaymentsDispute ) => {
	const charge =
		typeof dispute.charge === 'object' ? dispute.charge : undefined;
	const balanceTransaction = charge?.balance_transaction;
	const references = [
		dispute.payment_intent,
		dispute.transaction_id,
		dispute.charge_id,
		typeof dispute.charge === 'string' ? dispute.charge : undefined,
		charge?.id,
		charge?.payment_intent,
		typeof balanceTransaction === 'string'
			? balanceTransaction
			: balanceTransaction?.id,
	];

	return references.some(
		( reference ) =>
			typeof reference === 'string' && reference.trim() !== ''
	);
};

export const WooPaymentsDisputeDetailsRedirect = () => {
	const location = useLocation();

	useEffect( () => {
		let isMounted = true;
		const params = new URLSearchParams( location.search );
		const id = params.get( 'id' ) || params.get( 'charge_id' ) || '';
		const chargeId = params.get( 'charge_id' ) || '';

		const redirectTo = ( route: string ) => {
			if ( isMounted ) {
				window.location.assign(
					getSettingsPaymentsProviderRouteUrl( route )
				);
			}
		};

		if ( id && ! id.startsWith( 'ch_' ) && ! id.startsWith( 'py_' ) ) {
			getWooPaymentsDispute( id )
				.then( ( dispute ) => {
					redirectTo(
						hasTransactionReference( dispute )
							? getTransactionDetailsRoute( dispute )
							: DISPUTES_LIST_ROUTE
					);
				} )
				.catch( () => {
					redirectTo( DISPUTES_LIST_ROUTE );
				} );

			return () => {
				isMounted = false;
			};
		}

		if ( ! id ) {
			redirectTo( DISPUTES_LIST_ROUTE );

			return () => {
				isMounted = false;
			};
		}

		redirectTo(
			getTransactionDetailsRoute( { charge_id: chargeId || id } )
		);

		return () => {
			isMounted = false;
		};
	}, [ location.search ] );

	return null;
};
