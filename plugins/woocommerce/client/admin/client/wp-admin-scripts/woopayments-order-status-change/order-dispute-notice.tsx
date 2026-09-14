/**
 * WooPayments dispute notice for the WooCommerce order-edit screen.
 */

/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type {
	WooPaymentsCharge,
	WooPaymentsDispute,
} from '../../woopayments/admin/money-movement/types';
import {
	getChargeDisputes,
	isDisputeAwaitingResponse,
	isDisputeInquiry,
	isDisputeRefundable,
} from '../../woopayments/admin/money-movement/dispute-utils';
import {
	formatAmount,
	formatDate,
	formatDisputeReasonLabel,
	getTransactionDetailsRoute,
} from '../../woopayments/admin/money-movement/utils';
import { getSettingsPaymentsProviderRouteUrl } from '../../woopayments/admin/utils';

const DAY_IN_MILLISECONDS = 24 * 60 * 60 * 1000;
const LOCK_REASON_PRIORITY = [
	'lost',
	'under_review',
	'needs_response',
	'charge_refunded',
];

const getEvidenceDueBy = ( dispute: WooPaymentsDispute ) => {
	const dueBy = Number(
		dispute.evidence_details?.due_by ?? dispute.evidence_due_by
	);

	return Number.isFinite( dueBy ) && dueBy > 0 ? dueBy : 0;
};

const getNoticeCurrency = ( disputes: WooPaymentsDispute[] ) =>
	disputes.find(
		( dispute ) =>
			typeof dispute.currency === 'string' &&
			/^[a-z]{3}$/i.test( dispute.currency )
	)?.currency;

const getNoticeAmount = ( dispute: WooPaymentsDispute ) =>
	typeof dispute.amount === 'number' && Number.isFinite( dispute.amount )
		? dispute.amount
		: undefined;

const getBlockingDispute = ( disputes: WooPaymentsDispute[] ) => {
	const blockers = disputes.filter(
		( dispute ) => ! isDisputeRefundable( dispute )
	);

	return (
		LOCK_REASON_PRIORITY.map( ( status ) =>
			blockers.find( ( dispute ) => dispute.status === status )
		).find( Boolean ) || blockers[ 0 ]
	);
};

const getDetailsUrl = ( chargeId: string ) =>
	getSettingsPaymentsProviderRouteUrl(
		getTransactionDetailsRoute( { charge_id: chargeId } )
	);

const NoticeAction = ( { href, label }: { href: string; label: string } ) => (
	<p>
		<a className="components-button is-secondary" href={ href }>
			{ label }
		</a>
	</p>
);

const LockedNotice = ( {
	message,
	detailsUrl,
}: {
	message: string;
	detailsUrl: string;
} ) => (
	<Notice status="warning" isDismissible={ false }>
		<div>
			{ message }{ ' ' }
			<a href={ detailsUrl }>{ __( 'View details', 'woocommerce' ) }</a>
		</div>
	</Notice>
);

const AwaitingResponseNotice = ( {
	disputes,
	detailsUrl,
}: {
	disputes: WooPaymentsDispute[];
	detailsUrl: string;
} ) => {
	const earliestDueBy = Math.min( ...disputes.map( getEvidenceDueBy ) );
	const countdownDays = Math.floor(
		( earliestDueBy * 1000 - Date.now() ) / DAY_IN_MILLISECONDS
	);
	const actionLabel =
		countdownDays < 1
			? __( 'Respond today', 'woocommerce' )
			: __( 'Respond now', 'woocommerce' );
	const formattedDueBy = formatDate( earliestDueBy );
	const status = countdownDays < 3 ? 'error' : 'warning';

	if ( disputes.length === 1 ) {
		const dispute = disputes[ 0 ];
		const isInquiry = isDisputeInquiry( dispute );
		const formattedAmount = formatAmount(
			getNoticeAmount( dispute ),
			getNoticeCurrency( [ dispute ] )
		);
		const reason = formatDisputeReasonLabel( dispute.reason );
		const message = isInquiry
			? sprintf(
					/* translators: 1: disputed amount, 2: dispute reason. */
					__(
						"Please resolve the inquiry on this order of %1$s with reason '%2$s'.",
						'woocommerce'
					),
					formattedAmount,
					reason
			  )
			: sprintf(
					/* translators: 1: disputed amount, 2: dispute reason. */
					__(
						"This order has a payment dispute for %1$s for the reason '%2$s'.",
						'woocommerce'
					),
					formattedAmount,
					reason
			  );

		return (
			<Notice status={ status } isDismissible={ false }>
				<div>
					<strong>{ message }</strong>{ ' ' }
					{ sprintf(
						/* translators: %s: response due date. */
						__( 'Please respond before %s.', 'woocommerce' ),
						formattedDueBy
					) }
					<NoticeAction href={ detailsUrl } label={ actionLabel } />
				</div>
			</Notice>
		);
	}

	const totalAmount = disputes.reduce(
		( sum, dispute ) => sum + ( getNoticeAmount( dispute ) || 0 ),
		0
	);
	const currency = getNoticeCurrency( disputes );
	const allInquiries = disputes.every( isDisputeInquiry );
	const message = allInquiries
		? sprintf(
				/* translators: 1: inquiry count, 2: combined disputed amount. */
				__(
					'This order has %1$d payment inquiries totaling %2$s.',
					'woocommerce'
				),
				disputes.length,
				formatAmount( totalAmount, currency )
		  )
		: sprintf(
				/* translators: 1: dispute count, 2: combined disputed amount. */
				__(
					'This order has %1$d payment disputes totaling %2$s.',
					'woocommerce'
				),
				disputes.length,
				formatAmount( totalAmount, currency )
		  );

	return (
		<Notice status={ status } isDismissible={ false }>
			<div>
				<strong>{ message }</strong>{ ' ' }
				{ sprintf(
					/* translators: %s: earliest response due date. */
					__( 'Please respond before %s.', 'woocommerce' ),
					formattedDueBy
				) }
				<NoticeAction href={ detailsUrl } label={ actionLabel } />
			</div>
		</Notice>
	);
};

export const WooPaymentsOrderDisputeNotice = ( {
	chargeId,
	onDisableOrderRefund,
}: {
	chargeId: string;
	onDisableOrderRefund: ( status: string ) => void;
} ) => {
	const [ charge, setCharge ] = useState< WooPaymentsCharge | null >( null );

	useEffect( () => {
		let isCurrent = true;

		if ( ! chargeId ) {
			setCharge( null );
			return () => {
				isCurrent = false;
			};
		}

		void apiFetch< WooPaymentsCharge >( {
			path: `/wc/v3/payments/charges/${ encodeURIComponent( chargeId ) }`,
			method: 'GET',
		} )
			.then( ( response ) => {
				if ( isCurrent ) {
					setCharge( response );
				}
			} )
			.catch( () => {
				if ( isCurrent ) {
					setCharge( null );
				}
			} );

		return () => {
			isCurrent = false;
		};
	}, [ chargeId ] );

	const disputes = charge ? getChargeDisputes( charge ) : [];
	const blockingDispute = getBlockingDispute( disputes );

	useEffect( () => {
		if ( blockingDispute ) {
			onDisableOrderRefund( blockingDispute.status || 'unknown' );
		}
	}, [ blockingDispute, onDisableOrderRefund ] );

	if ( ! disputes.length ) {
		return null;
	}

	const now = Date.now() / 1000;
	const awaitingDisputes = disputes.filter(
		( dispute ) =>
			isDisputeAwaitingResponse( dispute ) &&
			getEvidenceDueBy( dispute ) > now
	);
	const detailsUrl = getDetailsUrl( chargeId );

	if ( awaitingDisputes.length ) {
		return (
			<AwaitingResponseNotice
				disputes={ awaitingDisputes }
				detailsUrl={ detailsUrl }
			/>
		);
	}

	if (
		disputes.some(
			( dispute ) =>
				dispute.status === 'under_review' &&
				! isDisputeInquiry( dispute )
		)
	) {
		return (
			<LockedNotice
				message={ __(
					'This order has an active payment dispute. Refunds and order editing are disabled.',
					'woocommerce'
				) }
				detailsUrl={ detailsUrl }
			/>
		);
	}

	if ( disputes.some( ( dispute ) => dispute.status === 'lost' ) ) {
		return (
			<LockedNotice
				message={ __(
					'Refunds and order editing have been disabled as a result of a lost dispute.',
					'woocommerce'
				) }
				detailsUrl={ detailsUrl }
			/>
		);
	}

	return null;
};
