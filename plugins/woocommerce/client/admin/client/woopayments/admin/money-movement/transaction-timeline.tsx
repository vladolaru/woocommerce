/**
 * External dependencies
 */
import { Button } from '@wordpress/components';
import { dateI18n } from '@wordpress/date';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf, TranslatableText } from '@wordpress/i18n';
import type { MouseEvent as ReactMouseEvent, ReactNode } from 'react';

/**
 * Internal dependencies
 */
import type { WooPaymentsTimelineEvent } from './types';
import type { WooPaymentsDisputeOrder } from './dispute-utils';
import { formatAmount, formatLabel } from './utils';

type TimelineDisplayEvent = {
	message: ReactNode;
	body?: ReactNode[];
	date?: string | number;
};

const earlyFraudWarningFraudTypeLabels: Record< string, string > = {
	card_never_received: __( 'Card never received', 'woocommerce' ),
	fraudulent_card_application: __(
		'Fraudulent card application',
		'woocommerce'
	),
	made_with_counterfeit_card: __(
		'Made with counterfeit card',
		'woocommerce'
	),
	made_with_lost_card: __( 'Made with lost card', 'woocommerce' ),
	made_with_stolen_card: __( 'Made with stolen card', 'woocommerce' ),
	misc: __( 'Other', 'woocommerce' ),
	unauthorized_use_of_card: __( 'Unauthorized use of card', 'woocommerce' ),
};

const hasDisplayValue = ( value: unknown ) =>
	value !== undefined && value !== null && value !== '';

const getEventDate = ( event: WooPaymentsTimelineEvent ) =>
	event.datetime || event.created;

const formatTimelineDate = ( value: string | number ) => {
	const timestamp =
		typeof value === 'number' && value < 10000000000 ? value * 1000 : value;
	const date = new Date( timestamp );

	if ( Number.isNaN( date.getTime() ) ) {
		return '-';
	}

	return dateI18n( 'M j, Y', date );
};

const getString = (
	record: Record< string, unknown >,
	key: string
): string | undefined => {
	const value = record[ key ];

	return typeof value === 'string' && value ? value : undefined;
};

const getNumber = (
	record: Record< string, unknown >,
	key: string
): number | undefined => {
	const value = record[ key ];

	return typeof value === 'number' && Number.isFinite( value )
		? value
		: undefined;
};

const getAmount = ( event: WooPaymentsTimelineEvent, ...keys: string[] ) => {
	for ( const key of keys ) {
		const value = getNumber( event, key );

		if ( value !== undefined ) {
			return value;
		}
	}

	return undefined;
};

const getCurrency = ( event: WooPaymentsTimelineEvent ) =>
	getString( event, 'currency' ) || 'usd';

const qualifyDisputeMessage = (
	message: ReactNode,
	event: WooPaymentsTimelineEvent,
	disputeOrder?: WooPaymentsDisputeOrder
) => {
	const disputeId = getString( event, 'dispute_id' );
	const ordinal = disputeId
		? disputeOrder?.orderById[ disputeId ]
		: undefined;

	if ( ! ordinal || ! disputeOrder || disputeOrder.total <= 1 ) {
		return message;
	}

	return sprintf(
		/* translators: 1: timeline message, 2: dispute position, 3: total disputes on the charge. */
		__( '%1$s · Dispute %2$d of %3$d', 'woocommerce' ),
		String( message ),
		ordinal,
		disputeOrder.total
	);
};

const getTimelineUserName = ( event: WooPaymentsTimelineEvent ) =>
	typeof event.user?.username === 'string' ? event.user.username : '';

const getFallbackMessage = ( event: WooPaymentsTimelineEvent ) => {
	if ( event.message ) {
		return event.message;
	}

	const userName = getTimelineUserName( event );
	if ( event.type === 'fraud_outcome_manual_approve' ) {
		return userName
			? sprintf(
					/* translators: %s: user display name. */
					__( 'Payment was approved by %s', 'woocommerce' ),
					userName
			  )
			: __( 'Payment was approved.', 'woocommerce' );
	}

	if ( event.type === 'fraud_outcome_manual_block' ) {
		return userName
			? sprintf(
					/* translators: %s: user display name. */
					__( 'Payment was blocked by %s', 'woocommerce' ),
					userName
			  )
			: __( 'Payment was blocked.', 'woocommerce' );
	}

	return formatLabel( event.type );
};

const createBodyLine = ( label: string, value: string ) =>
	hasDisplayValue( value )
		? sprintf(
				/* translators: 1: detail label, 2: detail value. */
				__( '%1$s: %2$s', 'woocommerce' ),
				label,
				value
		  )
		: null;

const getRefundBody = ( event: WooPaymentsTimelineEvent ) => {
	const body: ReactNode[] = [];
	const reason = getString( event, 'reason' );
	const arn = getString( event, 'acquirer_reference_number' );

	if ( reason ) {
		body.push(
			createBodyLine(
				__( 'Reason', 'woocommerce' ),
				formatLabel( reason )
			)
		);
	}

	if ( arn ) {
		body.push( createBodyLine( __( 'ARN', 'woocommerce' ), arn ) );
	}

	return body.filter( Boolean );
};

const getCapturedBody = ( event: WooPaymentsTimelineEvent ) => {
	const body: ReactNode[] = [];
	const currency = getCurrency( event );
	const fee = getAmount( event, 'fee' );
	const tax = getAmount( event, 'tax' );
	const net = getAmount( event, 'net' );

	if ( fee !== undefined ) {
		body.push(
			createBodyLine(
				__( 'Fee', 'woocommerce' ),
				formatAmount( Math.abs( fee ), currency )
			)
		);
	}

	if ( tax !== undefined ) {
		body.push(
			createBodyLine(
				__( 'Tax', 'woocommerce' ),
				formatAmount( Math.abs( tax ), currency )
			)
		);
	}

	if ( net !== undefined ) {
		body.push(
			createBodyLine(
				__( 'Net', 'woocommerce' ),
				formatAmount( net, currency )
			)
		);
	}

	return body.filter( Boolean );
};

const createAmountMessage = (
	template: TranslatableText< `${ string }%s${ string }` >,
	event: WooPaymentsTimelineEvent,
	...amountKeys: string[]
) => {
	const amount = getAmount( event, ...amountKeys );

	if ( amount === undefined ) {
		return undefined;
	}

	return sprintf( template, formatAmount( amount, getCurrency( event ) ) );
};

const mapTimelineEvent = (
	event: WooPaymentsTimelineEvent,
	disputeOrder?: WooPaymentsDisputeOrder,
	onRefund?: ( opener: HTMLElement ) => void,
	refundDialogId?: string,
	isRefundDialogOpen = false
): TimelineDisplayEvent[] => {
	const date = getEventDate( event );
	const type = event.type || '';

	switch ( type ) {
		case 'started':
			return [
				{
					message: __(
						'Payment status changed to Started.',
						'woocommerce'
					),
					date,
				},
			];
		case 'authorized': {
			const message = createAmountMessage(
				/* translators: %s: formatted amount. */
				__(
					'A payment of %s was successfully authorized.',
					'woocommerce'
				),
				event,
				'amount_authorized',
				'amount'
			);

			return [
				{
					message: __(
						'Payment status changed to Authorized.',
						'woocommerce'
					),
					date,
				},
				...( message ? [ { message, date } ] : [] ),
			];
		}
		case 'authorization_voided':
		case 'authorization_expired': {
			const isExpired = type === 'authorization_expired';
			const message = createAmountMessage(
				isExpired
					? /* translators: %s: formatted amount. */
					  __( 'Authorization for %s expired.', 'woocommerce' )
					: /* translators: %s: formatted amount. */
					  __( 'Authorization for %s was voided.', 'woocommerce' ),
				event,
				'amount_authorized',
				'amount'
			);

			return [
				{
					message: isExpired
						? __(
								'Payment status changed to Authorization expired.',
								'woocommerce'
						  )
						: __(
								'Payment status changed to Authorization voided.',
								'woocommerce'
						  ),
					date,
				},
				...( message ? [ { message, date } ] : [] ),
			];
		}
		case 'captured': {
			const message = createAmountMessage(
				/* translators: %s: formatted amount. */
				__(
					'A payment of %s was successfully charged.',
					'woocommerce'
				),
				event,
				'amount_captured',
				'amount'
			);

			return [
				{
					message: __(
						'Payment status changed to Paid.',
						'woocommerce'
					),
					date,
				},
				{
					message: message || getFallbackMessage( event ),
					body: getCapturedBody( event ),
					date,
				},
			];
		}
		case 'partial_refund':
		case 'full_refund': {
			const message = createAmountMessage(
				/* translators: %s: formatted amount. */
				__(
					'A payment of %s was successfully refunded.',
					'woocommerce'
				),
				event,
				'amount_refunded',
				'amount'
			);

			return [
				{
					message:
						message ||
						( type === 'full_refund'
							? __( 'Payment was refunded.', 'woocommerce' )
							: __(
									'Payment was partially refunded.',
									'woocommerce'
							  ) ),
					body: getRefundBody( event ),
					date,
				},
			];
		}
		case 'refund_failed': {
			const message = createAmountMessage(
				/* translators: %s: formatted amount. */
				__( 'A refund of %s failed.', 'woocommerce' ),
				event,
				'amount_refunded',
				'amount'
			);
			const failureReason = getString( event, 'failure_reason' );

			return [
				{
					message: message || __( 'Refund failed.', 'woocommerce' ),
					body: failureReason
						? [
								createBodyLine(
									__( 'Reason', 'woocommerce' ),
									failureReason
								),
						  ].filter( Boolean )
						: undefined,
					date,
				},
			];
		}
		case 'failed': {
			const message = createAmountMessage(
				/* translators: %s: formatted amount. */
				__( 'A payment of %s failed.', 'woocommerce' ),
				event,
				'amount'
			);
			const failureReason = getString( event, 'failure_reason' );

			return [
				{
					message: message || __( 'Payment failed.', 'woocommerce' ),
					body: failureReason
						? [
								createBodyLine(
									__( 'Reason', 'woocommerce' ),
									failureReason
								),
						  ].filter( Boolean )
						: undefined,
					date,
				},
			];
		}
		case 'dispute.created':
		case 'dispute_closed':
		case 'dispute.closed':
		case 'dispute.funds_withdrawn':
		case 'dispute.funds_reinstated': {
			const disputedAmount = createAmountMessage(
				type === 'dispute.created'
					? /* translators: %s: formatted amount. */
					  __( 'A dispute was opened for %s.', 'woocommerce' )
					: /* translators: %s: formatted amount. */
					  __( 'Dispute amount: %s', 'woocommerce' ),
				event,
				'amount'
			);

			return [
				{
					message: qualifyDisputeMessage(
						disputedAmount || getFallbackMessage( event ),
						event,
						disputeOrder
					),
					date,
				},
			];
		}
		case 'dispute_needs_response':
		case 'dispute_in_review':
		case 'dispute_won':
		case 'dispute_lost':
		case 'dispute_warning_closed':
		case 'dispute_charge_refunded':
			return [
				{
					message: qualifyDisputeMessage(
						getFallbackMessage( event ),
						event,
						disputeOrder
					),
					date,
				},
			];
		case 'financing_paydown': {
			const message = createAmountMessage(
				/* translators: %s: formatted amount. */
				__( 'A financing paydown of %s was applied.', 'woocommerce' ),
				event,
				'amount'
			);

			return [
				{
					message: message || getFallbackMessage( event ),
					date,
				},
			];
		}
		case 'early_fraud_warning': {
			const fraudType = getString( event, 'efw_type' );
			const fraudTypeLabel =
				fraudType &&
				Object.prototype.hasOwnProperty.call(
					earlyFraudWarningFraudTypeLabels,
					fraudType
				)
					? earlyFraudWarningFraudTypeLabels[ fraudType ]
					: undefined;
			const reportedReason = fraudTypeLabel
				? sprintf(
						/* translators: %s: card network reported fraud reason. */
						__( 'Reported reason: %s', 'woocommerce' ),
						fraudTypeLabel
				  )
				: null;

			if ( event.efw_actionable !== true ) {
				return [
					{
						message: __(
							'Payment status changed to Early fraud warning resolved.',
							'woocommerce'
						),
						date,
					},
					{
						message: __(
							'This early fraud warning is no longer actionable.',
							'woocommerce'
						),
						body: [
							__(
								'The payment was refunded or disputed, so no further action is needed to avoid a dispute.',
								'woocommerce'
							),
							reportedReason,
						].filter( Boolean ),
						date,
					},
				];
			}

			const refundGuidance = onRefund
				? createInterpolateElement(
						__(
							'Refunding this payment now can prevent a dispute. <refund>Refund this payment</refund>',
							'woocommerce'
						),
						{
							refund: (
								<Button
									variant="link"
									aria-haspopup="dialog"
									aria-expanded={ isRefundDialogOpen }
									aria-controls={
										isRefundDialogOpen
											? refundDialogId
											: undefined
									}
									onClick={ (
										clickEvent: ReactMouseEvent< HTMLButtonElement >
									) => onRefund( clickEvent.currentTarget ) }
								/>
							),
						}
				  )
				: __(
						'Refunding this payment now can prevent a dispute.',
						'woocommerce'
				  );

			return [
				{
					message: __(
						'Payment status changed to Early fraud warning.',
						'woocommerce'
					),
					date,
				},
				{
					message: __(
						'Payment received an early fraud warning',
						'woocommerce'
					),
					body: [
						__(
							'The card issuer flagged this payment as likely fraudulent.',
							'woocommerce'
						),
						reportedReason,
						refundGuidance,
					].filter( Boolean ),
					date,
				},
			];
		}
		case 'fraud_outcome_manual_approve':
		case 'fraud_outcome_manual_block':
			return [ { message: getFallbackMessage( event ), date } ];
		case 'fraud_outcome_auto_review':
			return [
				{
					message: __(
						'Payment was screened by your fraud filters and placed in review.',
						'woocommerce'
					),
					date,
				},
			];
		case 'fraud_outcome_auto_block':
			return [
				{
					message: __(
						'Payment was screened by your fraud filters and blocked.',
						'woocommerce'
					),
					date,
				},
			];
		default:
			return [ { message: getFallbackMessage( event ), date } ];
	}
};

export const WooPaymentsTransactionTimeline = ( {
	events,
	disputeOrder,
	onRefund,
	refundDialogId,
	isRefundDialogOpen = false,
}: {
	events: WooPaymentsTimelineEvent[];
	disputeOrder?: WooPaymentsDisputeOrder;
	onRefund?: ( opener: HTMLElement ) => void;
	refundDialogId?: string;
	isRefundDialogOpen?: boolean;
} ) => {
	const rows = events.flatMap( ( event ) =>
		mapTimelineEvent(
			event,
			disputeOrder,
			onRefund,
			refundDialogId,
			isRefundDialogOpen
		)
	);

	if ( ! rows.length ) {
		return null;
	}

	return (
		<section className="woocommerce-woopayments-overview-card">
			<h3>{ __( 'Timeline', 'woocommerce' ) }</h3>
			<ol className="woocommerce-woopayments-money-movement__timeline">
				{ rows.map( ( row, index ) => (
					<li key={ index }>
						<span>{ row.message }</span>
						{ !! row.body?.length && (
							<ul>
								{ row.body.map( ( line, bodyIndex ) => (
									<li key={ bodyIndex }>{ line }</li>
								) ) }
							</ul>
						) }
						{ row.date && (
							<time>{ formatTimelineDate( row.date ) }</time>
						) }
					</li>
				) ) }
			</ol>
		</section>
	);
};
