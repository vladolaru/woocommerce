/**
 * External dependencies
 */
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf, TranslatableText } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import CheckmarkIcon from 'gridicons/dist/checkmark';
import CrossIcon from 'gridicons/dist/cross';
import InfoOutlineIcon from 'gridicons/dist/info-outline';
import MinusIcon from 'gridicons/dist/minus';
import NoticeOutlineIcon from 'gridicons/dist/notice-outline';
import PlusIcon from 'gridicons/dist/plus';
import SyncIcon from 'gridicons/dist/sync';
import type {
	ElementType,
	MouseEvent as ReactMouseEvent,
	ReactElement,
	ReactNode,
} from 'react';

/**
 * Internal dependencies
 */
import type { WooPaymentsTimelineEvent } from './types';
import type { WooPaymentsDisputeOrder } from './dispute-utils';
import {
	formatAmount,
	formatDisputeReasonLabel,
	formatExplicitCurrency,
	formatSiteDateTime,
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
import {
	WooPaymentsTimelineList,
	type WooPaymentsTimelineItem,
} from './transaction-timeline-list';
import { getSettingsPaymentsProviderRouteUrl } from '../utils';
import { getWooPaymentsAmountFromMinorUnits } from '../../currency';
import './transaction-timeline.scss';

// Maps platform timeline events to core `Timeline` items the way client 11.1.0
// `payment-details/timeline/map-events.js` does: icons, headlines with the status in bold, and detail lines.

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

// Gridicons take a class name (the client's `is-success`, `is-warning` and `is-error` colours); the shared typings omit it.
const timelineIcon = ( Icon: ElementType, className?: string ) => {
	const Component = Icon as ElementType< { className?: string } >;

	return <Component className={ className } />;
};

// Client 11.1.0 `icons/shield-icon.tsx`, the automatic fraud review icon.
const ShieldIcon = ( { className }: { className?: string } ) => (
	<svg
		className={ className }
		xmlns="http://www.w3.org/2000/svg"
		width="18"
		height="18"
		viewBox="0 0 18 18"
		fill="none"
		aria-hidden="true"
	>
		<path
			fillRule="evenodd"
			clipRule="evenodd"
			d="M9 0.175781L15.75 3.24396V7.81781C15.75 11.7168 13.2458 15.4084 9.7147 16.573C9.25069 16.726 8.74931 16.726 8.2853 16.573C4.75416 15.4084 2.25 11.7168 2.25 7.81781V3.24396L9 0.175781ZM3.75 4.20983V7.81781C3.75 11.1307 5.89514 14.2052 8.75512 15.1485C8.914 15.2009 9.086 15.2009 9.24488 15.1485C12.1049 14.2052 14.25 11.1307 14.25 7.81781V4.20983L9 1.82347L3.75 4.20983Z"
			fill="white"
		/>
	</svg>
);

const getEventDate = ( event: WooPaymentsTimelineEvent ) => {
	const value = event.datetime ?? event.created;
	const timestamp =
		typeof value === 'number' && value < 10000000000 ? value * 1000 : value;

	return new Date( timestamp ?? NaN );
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

// Client `withDisputeQualifier()`: "Dispute N of M" when the charge has more than one dispute.
const withDisputeQualifier = (
	item: WooPaymentsTimelineItem,
	event: WooPaymentsTimelineEvent,
	disputeOrder?: WooPaymentsDisputeOrder
): WooPaymentsTimelineItem => {
	const disputeId = getString( event, 'dispute_id' );
	const ordinal = disputeId
		? disputeOrder?.orderById[ disputeId ]
		: undefined;

	if ( ! ordinal || ! disputeOrder || disputeOrder.total <= 1 ) {
		return item;
	}

	return {
		...item,
		headline: (
			<>
				{ item.headline }
				{ ` · ${ sprintf(
					/* translators: 1: dispute position, 2: total disputes on the charge. */
					__( 'Dispute %1$d of %2$d', 'woocommerce' ),
					ordinal,
					disputeOrder.total
				) }` }
			</>
		),
	};
};

const mainItem = (
	date: Date,
	headline: ReactNode,
	icon: ReactElement,
	body: ReactNode[] = []
): WooPaymentsTimelineItem => ( { date, headline, icon, body } );

// Client `getStatusChangeTimelineItem()`.
const statusItem = ( date: Date, status: string ) =>
	mainItem(
		date,
		createInterpolateElement(
			sprintf(
				/* translators: %s: new payment status, such as Authorized or Refunded. */
				__(
					'Payment status changed to <strong>%s</strong>.',
					'woocommerce'
				),
				status
			),
			{ strong: <strong /> }
		),
		timelineIcon( SyncIcon )
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
			formatSiteDateTime( arrivalDate, false )
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

// Client getDepositTimelineItem(): plus when the amount is added, minus when it is taken.
const payoutItem = (
	event: WooPaymentsTimelineEvent,
	date: Date,
	amount: string,
	direction: PayoutDirection,
	body: ReactNode[] = []
) =>
	mainItem(
		date,
		getPayoutMessage( event, amount, direction ),
		direction === 'added'
			? timelineIcon( PlusIcon )
			: timelineIcon( MinusIcon ),
		body
	);

// Client getManualFraudOutcomeTimelineItem(): the user name links to their profile.
const getManualFraudOutcomeHeadline = ( event: WooPaymentsTimelineEvent ) => {
	const isBlock = event.type === 'fraud_outcome_manual_block';
	const userName =
		typeof event.user?.username === 'string' ? event.user.username : '';
	const userId = event.user?.id;

	if (
		userName &&
		( typeof userId === 'number' || typeof userId === 'string' )
	) {
		const template = isBlock
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

	if ( userName ) {
		return isBlock
			? sprintf(
					/* translators: %s: user display name. */
					__( 'Payment was blocked by %s', 'woocommerce' ),
					userName
			  )
			: sprintf(
					/* translators: %s: user display name. */
					__( 'Payment was approved by %s', 'woocommerce' ),
					userName
			  );
	}

	return isBlock
		? __( 'Payment was blocked.', 'woocommerce' )
		: __( 'Payment was approved.', 'woocommerce' );
};

const createBodyLine = ( label: string, value: string ) =>
	value
		? sprintf(
				/* translators: 1: detail label, 2: detail value. */
				__( '%1$s: %2$s', 'woocommerce' ),
				label,
				value
		  )
		: null;

// Client `getRefundFailureReason()`: the failure reasons the client puts into words.
const refundFailureReasons: Record< string, string > = {
	expired_or_canceled_card: __(
		'the card being expired or canceled.',
		'woocommerce'
	),
	lost_or_stolen_card: __( 'the card being lost or stolen.', 'woocommerce' ),
	unknown: __( 'the card being lost or stolen.', 'woocommerce' ),
};

const getRefundTrackingLine = ( event: WooPaymentsTimelineEvent ) => {
	const arn = getString( event, 'acquirer_reference_number' );

	return arn && event.acquirer_reference_number_status === 'available'
		? sprintf(
				/* translators: %s: acquirer reference number. */
				__( 'Acquirer Reference Number (ARN) %s', 'woocommerce' ),
				arn
		  )
		: null;
};

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

	return [
		getRefundFx( event )?.line,
		getRefundTrackingLine( event ),
		reason
			? createBodyLine(
					__( 'Reason', 'woocommerce' ),
					refundReasonLabels[ reason ] ?? reason
			  )
			: null,
	].filter( Boolean );
};

const getRefundPayoutAmount = ( event: WooPaymentsTimelineEvent ) => {
	const refunded = getAmount( event, 'amount_refunded', 'amount' );
	const amount =
		getRefundFx( event )?.payout ??
		( refunded !== undefined &&
			formatExplicitCurrency( refunded, getCurrency( event ) ) );

	return amount || undefined;
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

const getNetworkCostItem = (
	event: WooPaymentsTimelineEvent,
	date: Date
): WooPaymentsTimelineItem | undefined => {
	const networkCost = getRecord( event.network_cost );
	const amount = getNumber( networkCost, 'amount' );
	const currency = getString( networkCost, 'currency' );

	if ( amount === undefined || ! currency ) {
		return undefined;
	}

	// Client 11.1.0 `map-events.js:1169-1205`.
	const formattedAmount = formatExplicitMoney( amount, currency );
	const isCrossCurrency =
		getString( event, 'currency' )?.toLowerCase() !==
		currency.toLowerCase();

	return payoutItem(
		event,
		date,
		isCrossCurrency
			? sprintf(
					/* translators: %s: formatted network cost amount. */
					__( '%s in your account currency', 'woocommerce' ),
					formattedAmount
			  )
			: formattedAmount,
		'deducted',
		[
			event.reason === 'noncompliant'
				? __(
						'Network costs associated with resolving Visa compliance disputes.',
						'woocommerce'
				  )
				: __( 'Network cost for the dispute.', 'woocommerce' ),
		]
	);
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
): WooPaymentsTimelineItem[] => {
	const date = getEventDate( event );
	const type = event.type || '';
	const qualify = ( item: WooPaymentsTimelineItem ) =>
		withDisputeQualifier( item, event, disputeOrder );

	switch ( type ) {
		case 'started':
			return [ statusItem( date, __( 'Started', 'woocommerce' ) ) ];
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
				statusItem( date, __( 'Authorized', 'woocommerce' ) ),
				...( message
					? [
							mainItem(
								date,
								message,
								timelineIcon( CheckmarkIcon, 'is-warning' )
							),
					  ]
					: [] ),
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
				statusItem(
					date,
					isExpired
						? __( 'Authorization expired', 'woocommerce' )
						: __( 'Authorization voided', 'woocommerce' )
				),
				...( message
					? [
							mainItem(
								date,
								message,
								isExpired
									? timelineIcon( CrossIcon, 'is-error' )
									: timelineIcon(
											CheckmarkIcon,
											'is-warning'
									  )
							),
					  ]
					: [] ),
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
				statusItem( date, __( 'Paid', 'woocommerce' ) ),
				...( captured.net
					? [ payoutItem( event, date, captured.net, 'added' ) ]
					: [] ),
				mainItem(
					date,
					message || __( 'Payment was charged.', 'woocommerce' ),
					timelineIcon( CheckmarkIcon, 'is-success' ),
					captured.body
				),
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
			const payoutAmount = getRefundPayoutAmount( event );

			return [
				statusItem(
					date,
					type === 'full_refund'
						? __( 'Refunded', 'woocommerce' )
						: __( 'Partial refund', 'woocommerce' )
				),
				...( payoutAmount
					? [ payoutItem( event, date, payoutAmount, 'deducted' ) ]
					: [] ),
				mainItem(
					date,
					message ||
						( type === 'full_refund'
							? __( 'Payment was refunded.', 'woocommerce' )
							: __(
									'Payment was partially refunded.',
									'woocommerce'
							  ) ),
					timelineIcon( CheckmarkIcon, 'is-success' ),
					getRefundBody( event )
				),
			];
		}
		case 'refund_failed': {
			const failureReason = getString( event, 'failure_reason' ) || '';
			const reasonText = refundFailureReasons[ failureReason ];
			const amount = getAmount( event, 'amount_refunded', 'amount' );
			const formattedAmount =
				amount === undefined
					? undefined
					: formatExplicitCurrency( amount, getCurrency( event ) );
			// The client words three failure reasons; other reasons keep the plain failure line and the reason.
			const headline =
				reasonText && formattedAmount
					? sprintf(
							/* translators: 1: formatted amount, 2: why the refund failed. */
							__(
								'%1$s refund was attempted but failed due to %2$s',
								'woocommerce'
							),
							formattedAmount,
							reasonText
					  )
					: createAmountMessage(
							/* translators: %s: formatted amount. */
							__( 'A refund of %s failed.', 'woocommerce' ),
							event,
							[ 'amount_refunded', 'amount' ],
							true
					  ) || __( 'Refund failed.', 'woocommerce' );

			return [
				mainItem(
					date,
					headline,
					timelineIcon( NoticeOutlineIcon, 'is-error' ),
					[
						getRefundTrackingLine( event ),
						reasonText
							? null
							: createBodyLine(
									__( 'Reason', 'woocommerce' ),
									failureReason
							  ),
					].filter( Boolean )
				),
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
				statusItem( date, __( 'Failed', 'woocommerce' ) ),
				mainItem(
					date,
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
					timelineIcon( CrossIcon, 'is-error' )
				),
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
			const payoutLine =
				amount === undefined
					? mainItem(
							date,
							__(
								'No funds have been withdrawn yet.',
								'woocommerce'
							),
							timelineIcon( InfoOutlineIcon ),
							[
								__(
									"The cardholder's bank is requesting more information to decide whether to return these funds to the cardholder.",
									'woocommerce'
								),
							]
					  )
					: payoutItem(
							event,
							date,
							getDisputeDepositImpact( event ) ?? '',
							'deducted',
							[
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
							].filter( Boolean )
					  );

			return [
				qualify(
					statusItem(
						date,
						__( 'Disputed: Needs response', 'woocommerce' )
					)
				),
				payoutLine,
				qualify(
					mainItem(
						date,
						reasonHeadline,
						timelineIcon( CrossIcon, 'is-error' )
					)
				),
			];
		}
		case 'dispute_in_review':
			return [
				qualify(
					statusItem(
						date,
						__( 'Disputed: In review', 'woocommerce' )
					)
				),
				qualify(
					mainItem(
						date,
						__( 'Challenge evidence submitted.', 'woocommerce' ),
						timelineIcon( CheckmarkIcon, 'is-success' )
					)
				),
			];
		case 'dispute_won': {
			const amount = getNumber( event, 'amount' );
			const depositImpact = getDisputeDepositImpact( event );

			return [
				qualify(
					statusItem( date, __( 'Disputed: Won', 'woocommerce' ) )
				),
				...( amount !== undefined && depositImpact
					? [
							payoutItem( event, date, depositImpact, 'added', [
								sprintf(
									/* translators: %s: reversed dispute amount. */
									__( 'Dispute reversal: %s', 'woocommerce' ),
									formatMoney( amount, getCurrency( event ) )
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
							] ),
					  ]
					: [] ),
				qualify(
					mainItem(
						date,
						__(
							'Dispute won! The bank ruled in your favor.',
							'woocommerce'
						),
						timelineIcon( NoticeOutlineIcon, 'is-success' )
					)
				),
			];
		}
		case 'dispute_lost': {
			const networkCostItem = getNetworkCostItem( event, date );

			return [
				...( networkCostItem ? [ networkCostItem ] : [] ),
				qualify(
					statusItem( date, __( 'Disputed: Lost', 'woocommerce' ) )
				),
				qualify(
					mainItem(
						date,
						getDisputeLostHeadline( event, bankName ),
						timelineIcon( CrossIcon, 'is-error' )
					)
				),
			];
		}
		case 'dispute_warning_closed':
			return [
				qualify(
					mainItem(
						date,
						__(
							'Dispute inquiry closed. The bank chose not to pursue this dispute.',
							'woocommerce'
						),
						timelineIcon( NoticeOutlineIcon, 'is-success' )
					)
				),
			];
		case 'dispute_charge_refunded':
			return [
				qualify(
					mainItem(
						date,
						__(
							'The disputed charge has been refunded.',
							'woocommerce'
						),
						timelineIcon( NoticeOutlineIcon, 'is-success' )
					)
				),
			];
		case 'financing_paydown': {
			const amount = getNumber( event, 'amount' );
			const loanId = getString( event, 'loan_id' );

			if ( amount === undefined ) {
				return [];
			}

			return [
				payoutItem(
					event,
					date,
					formatMoney( Math.abs( amount ), getCurrency( event ) ),
					'subtracted',
					loanId
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
						: []
				),
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
					statusItem(
						date,
						__( 'Early fraud warning resolved', 'woocommerce' )
					),
					mainItem(
						date,
						__(
							'This early fraud warning is no longer actionable.',
							'woocommerce'
						),
						timelineIcon( NoticeOutlineIcon ),
						[
							__(
								'The payment was refunded or disputed, so no further action is needed to avoid a dispute.',
								'woocommerce'
							),
							reportedReason,
						].filter( Boolean )
					),
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
				statusItem( date, __( 'Early fraud warning', 'woocommerce' ) ),
				mainItem(
					date,
					__(
						'Payment received an early fraud warning',
						'woocommerce'
					),
					timelineIcon( NoticeOutlineIcon, 'is-warning' ),
					[
						__(
							'The card issuer flagged this payment as likely fraudulent.',
							'woocommerce'
						),
						reportedReason,
						refundGuidance,
					].filter( Boolean )
				),
			];
		}
		case 'fraud_outcome_manual_approve':
			return [
				mainItem(
					date,
					getManualFraudOutcomeHeadline( event ),
					timelineIcon( CheckmarkIcon, 'is-success' )
				),
			];
		case 'fraud_outcome_manual_block':
			return [
				mainItem(
					date,
					getManualFraudOutcomeHeadline( event ),
					timelineIcon( CrossIcon, 'is-error' )
				),
			];
		case 'fraud_outcome_review':
			return [
				mainItem(
					date,
					__(
						'Payment was screened by your fraud filters and placed in review.',
						'woocommerce'
					),
					<ShieldIcon className="is-fraud-outcome-review" />,
					getFraudOutcomeRulesetLines( event )
				),
			];
		case 'fraud_outcome_block':
			return [
				mainItem(
					date,
					__(
						'Payment was screened by your fraud filters and blocked.',
						'woocommerce'
					),
					timelineIcon( CrossIcon, 'is-error' ),
					getFraudOutcomeRulesetLines( event )
				),
			];
		// Client 11.1.0 `map-events.js:1397-1398`: other event types, the allowed screenings included, show no line.
		default:
			return [];
	}
};

export const WooPaymentsTransactionTimeline = ( {
	events,
	disputeOrder,
	onRefund,
	refundDialogId,
	isRefundDialogOpen = false,
	bankName,
	hasError = false,
}: {
	events: WooPaymentsTimelineEvent[];
	disputeOrder?: WooPaymentsDisputeOrder;
	onRefund?: ( opener: HTMLElement ) => void;
	refundDialogId?: string;
	isRefundDialogOpen?: boolean;
	/** Customer's bank, named in the lost dispute headline. */
	bankName?: string;
	/** Whether the timeline failed to load. */
	hasError?: boolean;
} ) => {
	const items = events.flatMap( ( event ) =>
		mapTimelineEvent(
			event,
			disputeOrder,
			onRefund,
			refundDialogId,
			isRefundDialogOpen,
			bankName
		)
	);

	// Client 11.1.0 `payment-details/timeline/index.js:34-58`.
	return (
		<Card size="large">
			<CardHeader>
				<h2 className="woocommerce-woopayments-overview-card__title">
					{ __( 'Timeline', 'woocommerce' ) }
				</h2>
			</CardHeader>
			<CardBody>
				{ hasError ? (
					__( 'Error while loading timeline', 'woocommerce' )
				) : (
					<WooPaymentsTimelineList items={ items } />
				) }
			</CardBody>
		</Card>
	);
};
