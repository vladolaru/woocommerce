/**
 * External dependencies
 */
import { Button } from '@wordpress/components';
import { dateI18n } from '@wordpress/date';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf, TranslatableText } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import type { MouseEvent as ReactMouseEvent, ReactNode } from 'react';

/**
 * Internal dependencies
 */
import type { WooPaymentsTimelineEvent } from './types';
import type { WooPaymentsDisputeOrder } from './dispute-utils';
import {
	formatAmount,
	formatDisputeReasonLabel,
	formatExplicitCurrency,
	formatLabel,
} from './utils';
import {
	composeFxString,
	formatExplicitMoney,
	formatMoney,
	getCapturedDetails,
	getEnvelopeDepositImpact,
	getNumber,
	getRecord,
	getString,
	getTransactionDetails,
	isFxEvent,
} from './transaction-timeline-fees';
import { getSettingsPaymentsProviderRouteUrl } from '../utils';
import { getWooPaymentsAmountFromMinorUnits } from '../../currency';

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

// Client 11.1.0 timeline/mappings.ts paymentFailureMapping.
const paymentFailureMessages: Record< string, string > = {
	card_declined: __( 'The card was declined by the bank', 'woocommerce' ),
	expired_card: __( 'The card has expired', 'woocommerce' ),
	incorrect_cvc: __( 'The security code is incorrect', 'woocommerce' ),
	incorrect_number: __( 'The card number is incorrect', 'woocommerce' ),
	incorrect_zip: __( 'The postal code is incorrect', 'woocommerce' ),
	invalid_cvc: __( 'The security code is invalid', 'woocommerce' ),
	invalid_expiry_month: __(
		'The expiration month is invalid',
		'woocommerce'
	),
	invalid_expiry_year: __( 'The expiration year is invalid', 'woocommerce' ),
	invalid_number: __( 'The card number is invalid', 'woocommerce' ),
	processing_error: __(
		'An error occurred while processing the card',
		'woocommerce'
	),
	authentication_required: __(
		'The payment requires authentication',
		'woocommerce'
	),
	insufficient_funds: __(
		'The card has insufficient funds to complete the purchase',
		'woocommerce'
	),
};

// Client 11.1.0 timeline/mappings.ts fraudOutcomeRulesetMapping.
const fraudOutcomeRulesetMessages: Record<
	string,
	Record< string, string >
> = {
	review: {
		avs_verification: __(
			'Place in review if the AVS verification fails',
			'woocommerce'
		),
		address_mismatch: __(
			'Place in review if the shipping address country differs from the billing address country',
			'woocommerce'
		),
		international_ip_address: __(
			'Place in review if the country resolved from customer IP is not listed in your selling countries',
			'woocommerce'
		),
		ip_address_mismatch: __(
			'Place in review if the order originates from a country different from the shipping address country',
			'woocommerce'
		),
		order_items_threshold: __(
			'Place in review if the items count is not in your defined range',
			'woocommerce'
		),
		purchase_price_threshold: __(
			'Place in review if the purchase price is not in your defined range',
			'woocommerce'
		),
	},
	block: {
		avs_verification: __(
			'Block if the AVS verification fails',
			'woocommerce'
		),
		address_mismatch: __(
			'Block if the shipping address differs from the billing address',
			'woocommerce'
		),
		international_ip_address: __(
			'Block if the country resolved from customer IP is not listed in your selling countries',
			'woocommerce'
		),
		ip_address_mismatch: __(
			'Block if the order originates from a country different from the shipping address country',
			'woocommerce'
		),
		order_items_threshold: __(
			'Block if the items count is not in your defined range',
			'woocommerce'
		),
		purchase_price_threshold: __(
			'Block if the purchase price is not in your defined range',
			'woocommerce'
		),
	},
};

// Dispute reasons the client's disputes/strings.ts `reasons` map knows; others get the generic headline.
const knownDisputeReasons = new Set( [
	'bank_cannot_process',
	'check_returned',
	'credit_not_processed',
	'customer_initiated',
	'debit_not_authorized',
	'duplicate',
	'fraudulent',
	'general',
	'incorrect_account_details',
	'insufficient_funds',
	'product_not_received',
	'product_unacceptable',
	'subscription_canceled',
	'unrecognized',
	'noncompliant',
] );

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

	if ( typeof message !== 'string' ) {
		return (
			<>
				{ message }
				{ ` · ${ sprintf(
					/* translators: 1: dispute position, 2: total disputes on the charge. */
					__( 'Dispute %1$d of %2$d', 'woocommerce' ),
					ordinal,
					disputeOrder.total
				) }` }
			</>
		);
	}

	return sprintf(
		/* translators: 1: timeline message, 2: dispute position, 3: total disputes on the charge. */
		__( '%1$s · Dispute %2$d of %3$d', 'woocommerce' ),
		message,
		ordinal,
		disputeOrder.total
	);
};

const getStatusChangeMessage = ( status: string ) =>
	sprintf(
		/* translators: %s: new payment status. */
		__( 'Payment status changed to %s.', 'woocommerce' ),
		status
	);

type PayoutDirection = 'added' | 'deducted' | 'subtracted';

const getFuturePayoutTemplate = ( direction: PayoutDirection ) => {
	switch ( direction ) {
		case 'added':
			/* translators: %s: formatted amount. */
			return __( '%s will be added to a future payout.', 'woocommerce' );
		case 'subtracted':
			/* translators: %s: formatted amount. */
			return __(
				'%s will be subtracted from a future payout.',
				'woocommerce'
			);
		default:
			/* translators: %s: formatted amount. */
			return __(
				'%s will be deducted from a future payout.',
				'woocommerce'
			);
	}
};

const getLinkedPayoutTemplate = ( direction: PayoutDirection ) => {
	switch ( direction ) {
		case 'added':
			/* translators: 1: formatted amount, 2: payout arrival date. */
			return __(
				'%1$s was added to your <a>%2$s payout</a>.',
				'woocommerce'
			);
		case 'subtracted':
			/* translators: 1: formatted amount, 2: payout arrival date. */
			return __(
				'%1$s was subtracted from your <a>%2$s payout</a>.',
				'woocommerce'
			);
		default:
			/* translators: 1: formatted amount, 2: payout arrival date. */
			return __(
				'%1$s was deducted from your <a>%2$s payout</a>.',
				'woocommerce'
			);
	}
};

// Client getDepositTimelineItem() and getFinancingPaydownTimelineItem() headlines.
const getPayoutMessage = (
	event: WooPaymentsTimelineEvent,
	amount: string,
	direction: PayoutDirection
) => {
	const deposit = getRecord( event.deposit );
	const depositId = getString( deposit, 'id' );
	const arrivalDate = getNumber( deposit, 'arrival_date' );

	if ( ! depositId || ! arrivalDate ) {
		return sprintf( getFuturePayoutTemplate( direction ), amount );
	}

	return createInterpolateElement(
		sprintf(
			getLinkedPayoutTemplate( direction ),
			amount,
			formatTimelineDate( arrivalDate )
		),
		{
			a: (
				// eslint-disable-next-line jsx-a11y/anchor-has-content -- Content is interpolated.
				<a
					href={ getSettingsPaymentsProviderRouteUrl(
						`/woopayments/payouts/details?id=${ encodeURIComponent(
							depositId
						) }`
					) }
				/>
			),
		}
	);
};

const getTimelineUserName = ( event: WooPaymentsTimelineEvent ) =>
	typeof event.user?.username === 'string' ? event.user.username : '';

const getFallbackMessage = ( event: WooPaymentsTimelineEvent ) => {
	if ( event.message ) {
		return event.message;
	}

	const userName = getTimelineUserName( event );
	const userId = event.user?.id;
	const isManualFraudOutcome =
		event.type === 'fraud_outcome_manual_approve' ||
		event.type === 'fraud_outcome_manual_block';

	// Client getManualFraudOutcomeTimelineItem(): the user name links to their profile.
	if (
		isManualFraudOutcome &&
		userName &&
		( typeof userId === 'number' || typeof userId === 'string' )
	) {
		const template =
			event.type === 'fraud_outcome_manual_block'
				? /* translators: %s: user name, <a>: link to the user. */
				  __( 'Payment was blocked by <a>%s</a>', 'woocommerce' )
				: /* translators: %s: user name, <a>: link to the user. */
				  __( 'Payment was approved by <a>%s</a>', 'woocommerce' );

		return createInterpolateElement( sprintf( template, userName ), {
			a: (
				// eslint-disable-next-line jsx-a11y/anchor-has-content -- Content is interpolated.
				<a
					href={ addQueryArgs( 'user-edit.php', {
						user_id: userId,
					} ) }
				/>
			),
		} );
	}

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

const refundReasonLabels: Record< string, string > = {
	duplicate: __( 'Duplicate', 'woocommerce' ),
	fraudulent: __( 'Fraudulent', 'woocommerce' ),
	requested_by_customer: __( 'Requested by customer', 'woocommerce' ),
};

// Mirrors the client's refund FX line (map-events.js composeFXString and formatFX).
const getRefundFx = ( event: WooPaymentsTimelineEvent ) => {
	const details = ( event.transaction_details || {} ) as Record<
		string,
		unknown
	>;
	const from = getString( details, 'customer_currency' );
	const to = getString( details, 'store_currency' );
	const fromAmount = getAmount( details, 'customer_amount' );
	const toAmount = Math.abs( getAmount( details, 'store_amount' ) ?? NaN );

	if ( ! from || ! to || from === to || ! fromAmount || ! toAmount ) {
		return undefined;
	}

	const toMajor = getWooPaymentsAmountFromMinorUnits;
	const rate =
		toMajor( toAmount, to ) / toMajor( Math.abs( fromAmount ), from );
	const unit = toMajor( 100, from ) === 1 ? 100 : 1;
	// Client 11.1.0 `map-events.js:943-946` and `formatFX()`: both amounts with the explicit currency code.
	const payout = formatExplicitCurrency( toAmount, to );
	const formattedRate = rate
		.toFixed( rate < 1 ? 6 : 5 )
		.replace( /\.?0+$/, '' );

	return {
		payout,
		line: `${ formatExplicitCurrency(
			unit,
			from,
			true
		) } → ${ formattedRate } ${ to.toUpperCase() }: ${ payout }`,
	};
};

const getRefundBody = ( event: WooPaymentsTimelineEvent ) => {
	const reason = getString( event, 'reason' );
	const arn = getString( event, 'acquirer_reference_number' );

	return [
		getRefundFx( event )?.line,
		arn && event.acquirer_reference_number_status === 'available'
			? sprintf(
					/* translators: %s: acquirer reference number. */
					__( 'Acquirer Reference Number (ARN) %s', 'woocommerce' ),
					arn
			  )
			: null,
		reason
			? createBodyLine(
					__( 'Reason', 'woocommerce' ),
					refundReasonLabels[ reason ] ?? reason
			  )
			: null,
	].filter( Boolean );
};

const getRefundPayoutMessage = ( event: WooPaymentsTimelineEvent ) => {
	const refunded = getAmount( event, 'amount_refunded', 'amount' );
	const amount =
		getRefundFx( event )?.payout ??
		( refunded !== undefined &&
			formatExplicitCurrency( refunded, getCurrency( event ) ) );

	if ( ! amount ) {
		return null;
	}

	return getPayoutMessage( event, amount, 'deducted' );
};

// Client buildAutomaticFraudOutcomeRuleset(): one line per rule that did not allow the payment.
// Allowed rules have no message, so the lookup drops them.
const getFraudOutcomeRulesetLines = ( event: WooPaymentsTimelineEvent ) =>
	Object.entries( getRecord( event.ruleset_results ) ?? {} )
		.map(
			( [ rule, ruleStatus ] ) =>
				fraudOutcomeRulesetMessages[ String( ruleStatus ) ]?.[ rule ]
		)
		.filter( Boolean );

// Client dispute_needs_response, dispute_won and dispute_lost payout lines.
const getDisputeDepositImpact = ( event: WooPaymentsTimelineEvent ) => {
	const impact = getEnvelopeDepositImpact( event );

	if ( impact ) {
		return formatExplicitMoney(
			impact.amount,
			impact.currency || getCurrency( event )
		);
	}

	const amount = getNumber( event, 'amount' );

	return amount === undefined
		? undefined
		: formatExplicitMoney(
				Math.abs( amount ) + Math.abs( getNumber( event, 'fee' ) ?? 0 ),
				getCurrency( event )
		  );
};

const getDisputeLostHeadline = (
	event: WooPaymentsTimelineEvent,
	bankName?: string
) => {
	let headline: string;

	if ( event.reason === 'noncompliant' ) {
		headline = __(
			"<strong>Dispute lost.</strong> Visa reviewed the evidence and decided in the customer's favor.",
			'woocommerce'
		);
	} else if ( bankName ) {
		headline = sprintf(
			/* translators: %s: customer's bank name. */
			__(
				"<strong>Dispute lost.</strong> Your customer's bank, <strong>%s</strong>, reviewed the evidence and decided in the customer's favor.",
				'woocommerce'
			),
			bankName
		);
	} else {
		headline = __(
			"<strong>Dispute lost.</strong> Your customer's bank reviewed the evidence and decided in the customer's favor.",
			'woocommerce'
		);
	}

	return createInterpolateElement( headline, { strong: <strong /> } );
};

const getNetworkCostRow = (
	event: WooPaymentsTimelineEvent,
	date?: string | number
): TimelineDisplayEvent | undefined => {
	const networkCost = getRecord( event.network_cost );
	const amount = getNumber( networkCost, 'amount' );
	const currency = getString( networkCost, 'currency' );

	if ( amount === undefined || ! currency ) {
		return undefined;
	}

	// Client 11.1.0 `map-events.js:1169-1176`.
	const formattedAmount = formatExplicitMoney( amount, currency );
	const isCrossCurrency =
		getString( event, 'currency' )?.toLowerCase() !==
		currency.toLowerCase();

	return {
		message: getPayoutMessage(
			event,
			isCrossCurrency
				? sprintf(
						/* translators: %s: formatted network cost amount. */
						__( '%s in your account currency', 'woocommerce' ),
						formattedAmount
				  )
				: formattedAmount,
			'deducted'
		),
		body: [
			event.reason === 'noncompliant'
				? __(
						'Network costs associated with resolving Visa compliance disputes.',
						'woocommerce'
				  )
				: __( 'Network cost for the dispute.', 'woocommerce' ),
		],
		date,
	};
};

// `isExplicit` follows the client's `stringWithAmount( ..., true )` and `formatExplicitCurrency()` headlines.
const createAmountMessage = (
	template: TranslatableText< `${ string }%s${ string }` >,
	event: WooPaymentsTimelineEvent,
	amountKeys: string[],
	isExplicit = false
) => {
	const amount = getAmount( event, ...amountKeys );

	if ( amount === undefined ) {
		return undefined;
	}

	const format = isExplicit ? formatExplicitCurrency : formatAmount;

	return sprintf( template, format( amount, getCurrency( event ) ) );
};

const mapTimelineEvent = (
	event: WooPaymentsTimelineEvent,
	disputeOrder?: WooPaymentsDisputeOrder,
	onRefund?: ( opener: HTMLElement ) => void,
	refundDialogId?: string,
	isRefundDialogOpen = false,
	bankName?: string
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
				[ 'amount_authorized', 'amount' ],
				true
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
				[ 'amount_authorized', 'amount' ],
				true
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
				[ 'amount_captured', 'amount' ],
				true
			);
			const captured = getCapturedDetails( event );

			return [
				{
					message: __(
						'Payment status changed to Paid.',
						'woocommerce'
					),
					date,
				},
				...( captured.net
					? [
							{
								message: getPayoutMessage(
									event,
									captured.net,
									'added'
								),
								date,
							},
					  ]
					: [] ),
				{
					message: message || getFallbackMessage( event ),
					body: captured.body,
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
				[ 'amount_refunded', 'amount' ],
				true
			);
			const payoutMessage = getRefundPayoutMessage( event );

			return [
				{
					message: sprintf(
						/* translators: %s: new payment status. */
						__( 'Payment status changed to %s.', 'woocommerce' ),
						type === 'full_refund'
							? __( 'Refunded', 'woocommerce' )
							: __( 'Partial refund', 'woocommerce' )
					),
					date,
				},
				...( payoutMessage
					? [ { message: payoutMessage, date } ]
					: [] ),
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
				[ 'amount_refunded', 'amount' ],
				true
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
			const reason = getString( event, 'reason' );
			const failureMessage =
				reason &&
				Object.prototype.hasOwnProperty.call(
					paymentFailureMessages,
					reason
				)
					? paymentFailureMessages[ reason ]
					: __( 'The payment was declined', 'woocommerce' );
			const amount = getAmount( event, 'amount' );

			return [
				{
					message: getStatusChangeMessage(
						__( 'Failed', 'woocommerce' )
					),
					date,
				},
				{
					message:
						amount === undefined
							? __( 'Payment failed.', 'woocommerce' )
							: sprintf(
									/* translators: 1: payment amount, 2: failure reason message. */
									__(
										'A payment of %1$s failed: %2$s.',
										'woocommerce'
									),
									formatExplicitMoney(
										amount,
										getCurrency( event )
									),
									failureMessage
							  ),
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
				[ 'amount' ]
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
		case 'dispute_needs_response': {
			const reason = getString( event, 'reason' );
			const reasonHeadline =
				reason && knownDisputeReasons.has( reason )
					? sprintf(
							/* translators: %s: dispute reason. */
							__( 'Payment disputed as %s.', 'woocommerce' ),
							formatDisputeReasonLabel( reason )
					  )
					: __( 'Payment disputed', 'woocommerce' );
			const amount = getNumber( event, 'amount' );
			const fee = getNumber( event, 'fee' );
			const details = getTransactionDetails( event );
			const disputedAmount = isFxEvent( event )
				? formatMoney(
						getNumber( details, 'customer_amount' ) ?? 0,
						getString( details, 'customer_currency' )
				  )
				: formatMoney( amount ?? 0, getCurrency( event ) );
			const payoutRow: TimelineDisplayEvent =
				amount === undefined
					? {
							message: __(
								'No funds have been withdrawn yet.',
								'woocommerce'
							),
							body: [
								__(
									"The cardholder's bank is requesting more information to decide whether to return these funds to the cardholder.",
									'woocommerce'
								),
							],
							date,
					  }
					: {
							message: getPayoutMessage(
								event,
								getDisputeDepositImpact( event ) ?? '',
								'deducted'
							),
							body: [
								sprintf(
									/* translators: %s: disputed amount. */
									__( 'Disputed amount: %s', 'woocommerce' ),
									disputedAmount
								),
								composeFxString( event ),
								fee === undefined
									? undefined
									: sprintf(
											/* translators: %s: dispute fee. */
											__( 'Fee: %s', 'woocommerce' ),
											formatMoney(
												fee,
												getCurrency( event )
											)
									  ),
							].filter( Boolean ),
							date,
					  };

			return [
				{
					message: qualifyDisputeMessage(
						getStatusChangeMessage(
							__( 'Disputed: Needs response', 'woocommerce' )
						),
						event,
						disputeOrder
					),
					date,
				},
				payoutRow,
				{
					message: qualifyDisputeMessage(
						reasonHeadline,
						event,
						disputeOrder
					),
					date,
				},
			];
		}
		case 'dispute_in_review':
			return [
				{
					message: qualifyDisputeMessage(
						getStatusChangeMessage(
							__( 'Disputed: In review', 'woocommerce' )
						),
						event,
						disputeOrder
					),
					date,
				},
				{
					message: qualifyDisputeMessage(
						__( 'Challenge evidence submitted.', 'woocommerce' ),
						event,
						disputeOrder
					),
					date,
				},
			];
		case 'dispute_won': {
			const amount = getNumber( event, 'amount' );
			const depositImpact = getDisputeDepositImpact( event );

			return [
				{
					message: qualifyDisputeMessage(
						getStatusChangeMessage(
							__( 'Disputed: Won', 'woocommerce' )
						),
						event,
						disputeOrder
					),
					date,
				},
				...( amount !== undefined && depositImpact
					? [
							{
								message: getPayoutMessage(
									event,
									depositImpact,
									'added'
								),
								body: [
									sprintf(
										/* translators: %s: reversed dispute amount. */
										__(
											'Dispute reversal: %s',
											'woocommerce'
										),
										formatMoney(
											amount,
											getCurrency( event )
										)
									),
									sprintf(
										/* translators: %s: refunded dispute fee. */
										__( 'Fee refund: %s', 'woocommerce' ),
										formatMoney(
											Math.abs(
												getNumber( event, 'fee' ) ?? 0
											),
											getCurrency( event )
										)
									),
								],
								date,
							},
					  ]
					: [] ),
				{
					message: qualifyDisputeMessage(
						__(
							'Dispute won! The bank ruled in your favor.',
							'woocommerce'
						),
						event,
						disputeOrder
					),
					date,
				},
			];
		}
		case 'dispute_lost': {
			const networkCostRow = getNetworkCostRow( event, date );

			return [
				...( networkCostRow ? [ networkCostRow ] : [] ),
				{
					message: qualifyDisputeMessage(
						getStatusChangeMessage(
							__( 'Disputed: Lost', 'woocommerce' )
						),
						event,
						disputeOrder
					),
					date,
				},
				{
					message: qualifyDisputeMessage(
						getDisputeLostHeadline( event, bankName ),
						event,
						disputeOrder
					),
					date,
				},
			];
		}
		case 'dispute_warning_closed':
			return [
				{
					message: qualifyDisputeMessage(
						__(
							'Dispute inquiry closed. The bank chose not to pursue this dispute.',
							'woocommerce'
						),
						event,
						disputeOrder
					),
					date,
				},
			];
		case 'dispute_charge_refunded':
			return [
				{
					message: qualifyDisputeMessage(
						__(
							'The disputed charge has been refunded.',
							'woocommerce'
						),
						event,
						disputeOrder
					),
					date,
				},
			];
		case 'financing_paydown': {
			const amount = getNumber( event, 'amount' );
			const loanId = getString( event, 'loan_id' );

			if ( amount === undefined ) {
				return [ { message: getFallbackMessage( event ), date } ];
			}

			return [
				{
					message: getPayoutMessage(
						event,
						formatMoney( Math.abs( amount ), getCurrency( event ) ),
						'subtracted'
					),
					body: loanId
						? [
								createInterpolateElement(
									sprintf(
										/* translators: %s: loan ID. */
										__(
											'Loan repayment: <a>Loan %s</a>',
											'woocommerce'
										),
										loanId
									),
									{
										a: (
											// eslint-disable-next-line jsx-a11y/anchor-has-content -- Content is interpolated.
											<a
												href={ getSettingsPaymentsProviderRouteUrl(
													`/woopayments/transactions?loan_id_is=${ encodeURIComponent(
														loanId
													) }`
												) }
											/>
										),
									}
								),
						  ]
						: undefined,
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
		case 'fraud_outcome_review':
			return [
				{
					message: __(
						'Payment was screened by your fraud filters and placed in review.',
						'woocommerce'
					),
					body: getFraudOutcomeRulesetLines( event ),
					date,
				},
			];
		case 'fraud_outcome_block':
			return [
				{
					message: __(
						'Payment was screened by your fraud filters and blocked.',
						'woocommerce'
					),
					body: getFraudOutcomeRulesetLines( event ),
					date,
				},
			];
		// The platform records allowed screenings too; the client timeline has no line for them.
		case 'fraud_outcome_allow':
			return [];
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
	bankName,
}: {
	events: WooPaymentsTimelineEvent[];
	disputeOrder?: WooPaymentsDisputeOrder;
	onRefund?: ( opener: HTMLElement ) => void;
	refundDialogId?: string;
	isRefundDialogOpen?: boolean;
	/** Customer's bank, named in the lost dispute headline. */
	bankName?: string;
} ) => {
	const rows = events.flatMap( ( event ) =>
		mapTimelineEvent(
			event,
			disputeOrder,
			onRefund,
			refundDialogId,
			isRefundDialogOpen,
			bankName
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
