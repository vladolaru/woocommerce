/**
 * External dependencies
 */
import { Flex, FlexItem, Spinner } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useLocation } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { getWooPaymentsDispute } from './data';
import type { WooPaymentsDispute } from './types';
import { getTransactionDetailsRoute } from './utils';
import { navigateToSettingsPaymentsProviderRoute } from '../utils';
import './transaction-details.scss';

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

/**
 * The legacy dispute details route: it opens the dispute's payment details, else the disputes list.
 * Client 11.1.0 `disputes/redirect-to-transaction-details/index.tsx:60-111`: a spinner while the dispute loads, then an
 * in-app history replace, so Back skips this route; falling back to the list says why in a snackbar.
 */
export const WooPaymentsDisputeDetailsRedirect = () => {
	const location = useLocation();

	useEffect( () => {
		let isMounted = true;
		const params = new URLSearchParams( location.search );
		const id = params.get( 'id' ) || params.get( 'charge_id' ) || '';
		const chargeId = params.get( 'charge_id' ) || '';

		const redirectTo = ( route: string ) => {
			if ( isMounted ) {
				navigateToSettingsPaymentsProviderRoute( route, {
					replace: true,
				} );
			}
		};

		const fallBackToDisputesList = () => {
			if ( ! isMounted ) {
				return;
			}

			(
				dispatch( 'core/notices' ) as unknown as {
					createInfoNotice: (
						message: string,
						options: { type: 'snackbar' }
					) => void;
				}
			 ).createInfoNotice(
				__(
					"We couldn't open that dispute directly. Find it in your disputes list below.",
					'woocommerce'
				),
				{ type: 'snackbar' }
			);
			redirectTo( DISPUTES_LIST_ROUTE );
		};

		if ( id && ! id.startsWith( 'ch_' ) && ! id.startsWith( 'py_' ) ) {
			getWooPaymentsDispute( id )
				.then( ( dispute ) => {
					if ( hasTransactionReference( dispute ) ) {
						redirectTo( getTransactionDetailsRoute( dispute ) );
					} else {
						fallBackToDisputesList();
					}
				} )
				.catch( fallBackToDisputesList );

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

	return (
		<Flex
			direction="column"
			className="woocommerce-woopayments-dispute-details-redirect"
		>
			<FlexItem>
				<Spinner />
			</FlexItem>
			<FlexItem>
				<div>
					<b>{ __( 'One moment please', 'woocommerce' ) }</b>
				</div>
				<div>{ __( 'Redirecting…', 'woocommerce' ) }</div>
			</FlexItem>
		</Flex>
	);
};
