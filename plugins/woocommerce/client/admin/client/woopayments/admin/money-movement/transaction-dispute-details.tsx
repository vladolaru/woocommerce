/**
 * External dependencies
 */
import {
	Button,
	CardFooter,
	CheckboxControl,
	ExternalLink,
	Flex,
	FlexItem,
	Modal,
	Notice,
	Tooltip,
} from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import {
	createInterpolateElement,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { dateI18n } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, backup, lock, pencil } from '@wordpress/icons';
import { recordEvent } from '@woocommerce/tracks';
import NoticeOutlineIcon from 'gridicons/dist/notice-outline';
import moment from 'moment';
import type { ElementType, MouseEvent, ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { closeWooPaymentsDispute } from './data';
import {
	ACTIONABLE_DISPUTE_STATUSES,
	isVisaComplianceDispute,
} from './dispute-evidence-fields';
import { getEffectiveDisputeFee } from './dispute-utils';
import type {
	WooPaymentsBillingDetails,
	WooPaymentsDispute,
	WooPaymentsTransaction,
} from './types';
import {
	formatDisputeReasonLabel,
	formatExplicitCurrency,
	formatSiteDateTime,
	getBankName,
	getDisputeId,
	getErrorMessage,
} from './utils';
import {
	getSettingsPaymentsProviderRouteUrl,
	handleSettingsPaymentsProviderRouteClick,
} from '../utils';
import { HelpPopover } from '../overview/components/help-popover';
import {
	DisputeSteps,
	InquirySteps,
	NonCompliantDisputeSteps,
	NotDefendableInquirySteps,
} from './dispute-steps';

const RESPONDING_TO_DISPUTES_DOC_URL =
	'https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/#responding';
const PAYMENT_INQUIRIES_DOC_URL =
	'https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/#inquiries';
const VISA_COMPLIANCE_DISPUTES_DOC_URL =
	'https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/#visa-compliance-disputes';

const DISPUTE_FEES_DOC_URL =
	'https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/#fees';
const DISPUTED_AMOUNTS_DOC_URL =
	'https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/#amounts';
const MONITOR_DISPUTE_STATUS_DOC_URL =
	'https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/#monitor-status';
const PREVENTING_DISPUTES_DOC_URL =
	'https://woocommerce.com/document/woopayments/fraud-and-disputes/preventing-disputes/';

// Client disputes/strings.ts reason claims, with dispute-notice.tsx's fallback.
const DISPUTE_CLAIMS: Record< string, string > = {
	credit_not_processed: __(
		'The cardholder claims a credit was not processed.',
		'woocommerce'
	),
	duplicate: __(
		'The cardholder claims this is a duplicate transaction.',
		'woocommerce'
	),
	fraudulent: __(
		'The cardholder claims this is an unauthorized transaction.',
		'woocommerce'
	),
	product_not_received: __(
		'The cardholder claims they did not receive the product.',
		'woocommerce'
	),
	product_unacceptable: __(
		'The cardholder claims the product was unacceptable.',
		'woocommerce'
	),
	subscription_canceled: __(
		'The cardholder claims a subscription was canceled.',
		'woocommerce'
	),
	noncompliant: __(
		'Your customer’s bank claims this payment violates Visa’s rules.',
		'woocommerce'
	),
};

const getDisputeChallengeRoute = ( disputeId: string ) =>
	`/woopayments/disputes/challenge?id=${ encodeURIComponent( disputeId ) }`;

const getDisputeChallengeUrl = ( disputeId: string ) =>
	getSettingsPaymentsProviderRouteUrl(
		getDisputeChallengeRoute( disputeId )
	);
const REFUND_GUIDANCE_ID_PREFIX =
	'woocommerce-woopayments-transaction-dispute-refund-guidance';
const HEADING_ID_PREFIX =
	'woocommerce-woopayments-transaction-dispute-details-heading';

const isAwaitingResponse = ( status?: string ) =>
	ACTIONABLE_DISPUTE_STATUSES.some(
		( actionableStatus ) => actionableStatus === status
	);

const isInquiry = ( status?: string ) => !! status?.startsWith( 'warning' );

const hasSubmittedEvidence = ( dispute: WooPaymentsDispute ) =>
	!! (
		dispute.evidence_details?.has_evidence ||
		dispute.metadata?.__evidence_submitted_at
	);

// Client 11.1.0 `utils/date-time.ts` `formatDateTimeFromTimestamp()`: the site date format.
const formatDisputeMetadataDate = ( timestamp: unknown ) =>
	timestamp ? formatSiteDateTime( Number( timestamp ), false ) : '-';

// Client 11.1.0 dispute-resolution-footer.tsx:38-98, 128-216, 363-403 and 433-461.
const getResolvedStatusDescription = (
	dispute: WooPaymentsDispute,
	bankName?: string
): { message: string; docUrl: string; docLabel: string } | null => {
	const submittedAt = formatDisputeMetadataDate(
		dispute.metadata?.__evidence_submitted_at
	);
	const isVisa = isVisaComplianceDispute(
		dispute.reason,
		dispute.enhanced_eligibility_types
	);

	switch ( dispute.status ) {
		case 'under_review': {
			let lead = sprintf(
				/* translators: %1$s: date the evidence was submitted. */
				__(
					"<strong>The customer's bank is currently reviewing the evidence you submitted on %1$s.</strong>",
					'woocommerce'
				),
				submittedAt
			);
			if ( isVisa ) {
				lead = sprintf(
					/* translators: %1$s: date the evidence was submitted. */
					__(
						'<strong>Visa is currently reviewing the evidence you submitted on %1$s.</strong>',
						'woocommerce'
					),
					submittedAt
				);
			} else if ( bankName ) {
				lead = sprintf(
					/* translators: %1$s: customer's bank name, %2$s: date the evidence was submitted. */
					__(
						"<strong>The customer's bank, %1$s, is currently reviewing the evidence you submitted on %2$s.</strong>",
						'woocommerce'
					),
					bankName,
					submittedAt
				);
			}
			return {
				message: `${ lead } ${ __(
					"This process can sometimes take more than 60 days — we'll let you know once a decision has been made.",
					'woocommerce'
				) }`,
				docUrl: MONITOR_DISPUTE_STATUS_DOC_URL,
				docLabel: __(
					'Learn more about monitoring dispute status.',
					'woocommerce'
				),
			};
		}
		case 'won': {
			const closedAt = formatDisputeMetadataDate(
				dispute.metadata?.__dispute_closed_at
			);
			let lead = sprintf(
				/* translators: %1$s: date the dispute closed. */
				__(
					"<strong>Good news — you've won this dispute! The customer's bank reached this decision on %1$s.</strong>",
					'woocommerce'
				),
				closedAt
			);
			if ( isVisa ) {
				lead = sprintf(
					/* translators: %1$s: date the dispute closed. */
					__(
						"<strong>Good news — you've won this dispute! Visa reached this decision on %1$s.</strong>",
						'woocommerce'
					),
					closedAt
				);
			} else if ( bankName ) {
				lead = sprintf(
					/* translators: %1$s: customer's bank name, %2$s: date the dispute closed. */
					__(
						"<strong>Good news — you've won this dispute! The customer's bank, %1$s, reached this decision on %2$s.</strong>",
						'woocommerce'
					),
					bankName,
					closedAt
				);
			}
			return {
				message: `${ lead } ${ __(
					'Your account has been credited with the disputed amount and fee.',
					'woocommerce'
				) }`,
				docUrl: PREVENTING_DISPUTES_DOC_URL,
				docLabel: __(
					'Learn more about preventing disputes.',
					'woocommerce'
				),
			};
		}
		case 'warning_under_review':
			return {
				message: bankName
					? sprintf(
							/* translators: %1$s: date the evidence was submitted, %2$s: customer's bank name. */
							__(
								'You submitted evidence for this inquiry on %1$s. <strong>%2$s</strong> is reviewing the case, which can take 120 days or more. You will be alerted when they make their final decision.',
								'woocommerce'
							),
							submittedAt,
							bankName
					  )
					: sprintf(
							/* translators: %s: date the evidence was submitted. */
							__(
								'You submitted evidence for this inquiry on %s. The <strong>cardholder’s bank</strong> is reviewing the case, which can take 120 days or more. You will be alerted when they make their final decision.',
								'woocommerce'
							),
							submittedAt
					  ),
				docUrl: PAYMENT_INQUIRIES_DOC_URL,
				docLabel: __( 'Learn more.', 'woocommerce' ),
			};
		case 'warning_closed':
			return {
				message: sprintf(
					/* translators: %s: date the inquiry closed. */
					__( 'This inquiry was closed on %s.', 'woocommerce' ),
					formatDisputeMetadataDate(
						dispute.metadata?.__dispute_closed_at
					)
				),
				docUrl: PREVENTING_DISPUTES_DOC_URL,
				docLabel: __(
					'Learn more about preventing disputes.',
					'woocommerce'
				),
			};
		default:
			return null;
	}
};

// Mirrors the client's DisputeLostFooter copy (dispute-resolution-footer.tsx).
const LostDisputeDescription = ( {
	dispute,
	bankName,
}: {
	dispute: WooPaymentsDispute;
	bankName?: string;
} ) => {
	const metadata = dispute.metadata || {};
	const closedAt = formatDisputeMetadataDate( metadata.__dispute_closed_at );
	const fee = getEffectiveDisputeFee( dispute );
	let prefix = sprintf(
		/* translators: %1$s: date the dispute closed. */
		__(
			'This dispute was lost on %1$s due to non-response.',
			'woocommerce'
		),
		closedAt
	);

	if ( metadata.__evidence_submitted_at ) {
		if (
			dispute.reason === 'noncompliant' ||
			dispute.enhanced_eligibility_types?.includes( 'visa_compliance' )
		) {
			prefix = sprintf(
				/* translators: %1$s: date the dispute closed. */
				__(
					"<strong>Unfortunately, you've lost this dispute. Visa reached this decision on %1$s.</strong>",
					'woocommerce'
				),
				closedAt
			);
		} else if ( bankName ) {
			prefix = sprintf(
				/* translators: %1$s: customer's bank name, %2$s: date the dispute closed. */
				__(
					"<strong>Unfortunately, you've lost this dispute. The customer's bank, %1$s, reached this decision on %2$s.</strong>",
					'woocommerce'
				),
				bankName,
				closedAt
			);
		} else {
			prefix = sprintf(
				/* translators: %1$s: date the dispute closed. */
				__(
					"<strong>Unfortunately, you've lost this dispute. The customer's bank reached this decision on %1$s.</strong>",
					'woocommerce'
				),
				closedAt
			);
		}
	} else if ( metadata.__closed_by_merchant === '1' ) {
		prefix = sprintf(
			/* translators: %1$s: date the dispute closed. */
			__(
				'<strong>You accepted this dispute on %1$s.</strong>',
				'woocommerce'
			),
			closedAt
		);
	}

	return (
		<>
			{ createInterpolateElement( prefix, { strong: <strong /> } ) }{ ' ' }
			{ fee
				? sprintf(
						/* translators: %1$s: formatted dispute fee. */
						__(
							'The %1$s fee has been deducted from your account, and the disputed amount has been returned to your customer.',
							'woocommerce'
						),
						formatExplicitCurrency( fee.amount, fee.currency )
				  )
				: __(
						'The disputed amount has been returned to your customer.',
						'woocommerce'
				  ) }{ ' ' }
			<ExternalLink
				href={ fee ? DISPUTE_FEES_DOC_URL : DISPUTED_AMOUNTS_DOC_URL }
			>
				{ fee
					? __( 'Learn more about dispute fees.', 'woocommerce' )
					: __(
							'Learn more about disputed amounts.',
							'woocommerce'
					  ) }
			</ExternalLink>
		</>
	);
};

const getDueDate = ( dispute: WooPaymentsDispute ) =>
	dispute.evidence_details?.due_by || dispute.evidence_due_by;

// Client 11.1.0 dispute-awaiting-response-details.tsx getLearnMoreDocsUrl() and getHelpLinkText().
const getDisputeDocumentation = (
	isInquiryStatus: boolean,
	isVisaCompliance: boolean
) => {
	if ( isInquiryStatus ) {
		return {
			href: PAYMENT_INQUIRIES_DOC_URL,
			label: __( 'Learn more about payment inquiries', 'woocommerce' ),
		};
	}

	if ( isVisaCompliance ) {
		return {
			href: VISA_COMPLIANCE_DISPUTES_DOC_URL,
			label: __(
				'Learn more about Visa compliance disputes',
				'woocommerce'
			),
		};
	}

	return {
		href: RESPONDING_TO_DISPUTES_DOC_URL,
		label: __( 'Learn more about responding to disputes', 'woocommerce' ),
	};
};

const DisputeDocumentationLink = ( {
	isInquiryStatus,
	isVisaCompliance,
	onClick,
}: {
	isInquiryStatus: boolean;
	isVisaCompliance: boolean;
	onClick: () => void;
} ) => {
	const { href, label } = getDisputeDocumentation(
		isInquiryStatus,
		isVisaCompliance
	);

	return (
		<div className="woocommerce-woopayments-dispute-pane__help-link">
			<ExternalLink onClick={ onClick } href={ href }>
				{ label }
			</ExternalLink>
		</div>
	);
};

// Client 11.1.0 `disputes/strings.ts` reason summaries, shown behind the Reason help icon.
const DISPUTE_REASON_SUMMARIES: Record< string, string > = {
	credit_not_processed: __(
		'The customer claims that the purchased product was returned or the transaction was otherwise canceled, but you have not yet provided a refund or credit.',
		'woocommerce'
	),
	duplicate: __(
		'The customer claims they were charged multiple times for the same product or service.',
		'woocommerce'
	),
	fraudulent: __(
		'This is the most common reason for a dispute, and happens when a cardholder claims that they didn’t authorize the payment. This can happen if the card was lost or stolen and used to make an unauthorized transaction. It can also happen if the cardholder doesn’t recognize the payment as it appears on the billing statement from their card issuer.',
		'woocommerce'
	),
	general: __(
		'This is an uncategorized dispute, so you should contact the customer for additional details to find out why the payment was disputed.',
		'woocommerce'
	),
	product_not_received: __(
		'The customer claims they did not receive the products or services purchased.',
		'woocommerce'
	),
	product_unacceptable: __(
		'The product or service was received but was defective, damaged, or not as described.',
		'woocommerce'
	),
	subscription_canceled: __(
		'The customer claims that you continued to charge them after a subscription was canceled.',
		'woocommerce'
	),
	unrecognized: __(
		'The customer doesn’t recognize the payment appearing on their card statement.',
		'woocommerce'
	),
	noncompliant: __(
		'This transaction is being reviewed under Visa’s network compliance rules.',
		'woocommerce'
	),
};

const DISPUTES_DOC_URL =
	'https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/';

const formatDisputeDate = ( value: unknown, format: string ) => {
	if ( ! value ) {
		return '–';
	}

	const date =
		typeof value === 'number' || /^\d+$/.test( String( value ) )
			? moment.unix( Number( value ) ).utc()
			: moment.utc( String( value ) );

	return date.isValid()
		? dateI18n( format, date.toISOString(), undefined )
		: '–';
};

// Client 11.1.0 `dispute-details/dispute-notice.tsx`: the urgent notice leading the pane.
const getDisputeNoticeText = (
	dispute: WooPaymentsDispute,
	paymentMethod?: string,
	bankName?: string
) => {
	const claim =
		DISPUTE_CLAIMS[ dispute.reason || '' ] ??
		__(
			'The cardholder claims this is an unauthorized charge.',
			'woocommerce'
		);
	const dueBy = formatDisputeDate(
		getDueDate( dispute ) ?? 0,
		'g:i A \\o\\n F j, Y'
	);

	if ( paymentMethod === 'klarna' && isInquiry( dispute.status ) ) {
		if ( dispute.reason === 'credit_not_processed' ) {
			return sprintf(
				/* translators: %s: the deadline, such as "11:59 PM on Aug 5, 2026". */
				__(
					"<strong>The customer has filed an inquiry through Klarna, reporting a return.</strong> This is a standard part of Klarna's returns process. Once you receive the item, issue the refund as usual. If it remains unresolved by %s, the inquiry may escalate to a dispute, which you can challenge with evidence. <link>Learn more about Klarna inquiries and disputes</link>",
					'woocommerce'
				),
				dueBy
			);
		}

		const klarnaReasonClauses: Record< string, string > = {
			fraudulent: __(
				'claiming this transaction was unauthorized',
				'woocommerce'
			),
			product_not_received: __(
				'claiming they did not receive the product',
				'woocommerce'
			),
			product_unacceptable: __(
				'claiming the product was unacceptable',
				'woocommerce'
			),
			duplicate: __(
				'claiming this transaction was duplicated',
				'woocommerce'
			),
		};

		return sprintf(
			/* translators: 1: why the customer filed the inquiry, 2: the deadline. */
			__(
				'<strong>The customer has filed an inquiry through Klarna, %1$s.</strong> You can resolve this by working it out with the customer directly or issuing a refund. If unresolved by %2$s, the inquiry may escalate to a dispute, which you can challenge with evidence.',
				'woocommerce'
			),
			klarnaReasonClauses[ dispute.reason || '' ] ??
				__( 'regarding this transaction', 'woocommerce' ),
			dueBy
		);
	}

	if ( isInquiry( dispute.status ) ) {
		return bankName
			? sprintf(
					/* translators: 1: the cardholder's claim, 2: the deadline, 3: the customer's bank. */
					__(
						"<strong>%1$s</strong> If you believe this is incorrect, you have until <strong>%2$s to submit evidence to your customer's bank, %3$s.</strong> Alternatively, you can issue a refund.",
						'woocommerce'
					),
					claim,
					dueBy,
					bankName
			  )
			: sprintf(
					/* translators: 1: the cardholder's claim, 2: the deadline. */
					__(
						"<strong>%1$s</strong> If you believe this is incorrect, you have until <strong>%2$s to submit evidence to your customer's bank.</strong> Alternatively, you can issue a refund.",
						'woocommerce'
					),
					claim,
					dueBy
			  );
	}

	if ( dispute.reason === 'noncompliant' ) {
		return bankName
			? sprintf(
					/* translators: 1: the customer's bank, 2: the deadline. */
					__(
						'Your customer’s bank, %1$s, claims this payment violates Visa’s rules. <strong>You can challenge the dispute by %2$s, or accept it.</strong> If you accept the dispute, you will forfeit the funds and pay the dispute fee. Challenging adds an additional $500 USD dispute fee that is only returned to you if you win.',
						'woocommerce'
					),
					bankName,
					dueBy
			  )
			: sprintf(
					/* translators: %s: the deadline. */
					__(
						'Your customer’s bank claims this payment violates Visa’s rules. <strong>You can challenge the dispute by %s, or accept it.</strong> If you accept the dispute, you will forfeit the funds and pay the dispute fee. Challenging adds an additional $500 USD dispute fee that is only returned to you if you win.',
						'woocommerce'
					),
					dueBy
			  );
	}

	return bankName
		? sprintf(
				/* translators: 1: the cardholder's claim, 2: the deadline, 3: the customer's bank. */
				__(
					"<strong>%1$s</strong> If you believe this is incorrect, you have until <strong>%2$s to challenge the dispute with your customer's bank, %3$s.</strong> If you accept the dispute, you will forfeit the funds and pay the dispute fee.",
					'woocommerce'
				),
				claim,
				dueBy,
				bankName
		  )
		: sprintf(
				/* translators: 1: the cardholder's claim, 2: the deadline. */
				__(
					"<strong>%1$s</strong> If you believe this is incorrect, you have until <strong>%2$s to challenge the dispute with your customer's bank.</strong> If you accept the dispute, you will forfeit the funds and pay the dispute fee.",
					'woocommerce'
				),
				claim,
				dueBy
		  );
};

// Client 11.1.0 `components/inline-notice`: the notice-outline gridicon, which takes a class name the shared typings omit.
const NoticeIcon = NoticeOutlineIcon as ElementType< { className?: string } >;

export const DisputeNotice = ( {
	dispute,
	paymentMethod,
	bankName,
}: {
	dispute: WooPaymentsDispute;
	paymentMethod?: string;
	bankName?: string;
} ) => (
	<Notice
		status="error"
		isDismissible={ false }
		className="woocommerce-woopayments-dispute-pane__notice"
	>
		<NoticeIcon className="woocommerce-woopayments-dispute-pane__notice-icon" />
		<span>
			{ createInterpolateElement(
				getDisputeNoticeText( dispute, paymentMethod, bankName ),
				{
					strong: <strong />,
					link: (
						<ExternalLink href="https://woocommerce.com/document/woopayments/payment-methods/buy-now-pay-later/#klarna-inquiries-returns">
							{ '' }
						</ExternalLink>
					),
				}
			) }
		</span>
	</Notice>
);

// Client 11.1.0 `dispute-details/dispute-due-by-date.tsx`.
const DisputeDueByDate = ( { dueBy }: { dueBy?: number } ) => {
	if ( ! dueBy ) {
		return <>–</>;
	}

	const daysRemaining = Math.floor(
		moment.unix( dueBy ).utc().diff( moment().utc(), 'days', true )
	);
	let remaining: string = __( '(Past due)', 'woocommerce' );

	if ( daysRemaining > 0 ) {
		remaining = sprintf(
			/* translators: %d: days left to respond. */
			_n(
				'(%d day left to respond)',
				'(%d days left to respond)',
				daysRemaining,
				'woocommerce'
			),
			daysRemaining
		);
	} else if ( daysRemaining === 0 ) {
		remaining = __( '(Last day today)', 'woocommerce' );
	}

	return (
		<span>
			{ formatDisputeDate( dueBy, 'F j, Y g:i A' ) }
			<span className="woocommerce-woopayments-dispute-pane__due-urgent">
				{ ` ${ remaining }` }
			</span>
		</span>
	);
};

// Client 11.1.0 `dispute-details/dispute-summary-row.tsx`.
export const DisputeSummaryRow = ( {
	dispute,
	extraItems = [],
}: {
	dispute: WooPaymentsDispute;
	/** Items after Respond By, such as the challenge page's Order. */
	extraItems?: Array< { title: string; content: ReactNode } >;
} ) => {
	const summary = DISPUTE_REASON_SUMMARIES[ dispute.reason || '' ];
	const items = [
		{
			title: __( 'Dispute Amount', 'woocommerce' ),
			content: formatExplicitCurrency( dispute.amount, dispute.currency ),
		},
		{
			title: __( 'Disputed On', 'woocommerce' ),
			content: formatDisputeDate( dispute.created, 'F j, Y' ),
		},
		{
			title: __( 'Reason', 'woocommerce' ),
			content: (
				<>
					{ formatDisputeReasonLabel( dispute.reason ) }
					{ summary && (
						<HelpPopover
							label={ __( 'Learn more', 'woocommerce' ) }
						>
							<p>
								{ summary }{ ' ' }
								<ExternalLink href={ DISPUTES_DOC_URL }>
									{ __( 'Learn more', 'woocommerce' ) }
								</ExternalLink>
							</p>
						</HelpPopover>
					) }
				</>
			),
		},
		{
			title: __( 'Respond By', 'woocommerce' ),
			content: (
				<DisputeDueByDate
					dueBy={ Number( getDueDate( dispute ) ) || 0 }
				/>
			),
		},
		...extraItems,
	];

	return (
		<dl className="woocommerce-woopayments-payment-summary__list woocommerce-woopayments-dispute-pane__summary">
			{ items.map( ( { title, content } ) => (
				<div key={ title }>
					<dt>{ title }</dt>
					<dd>{ content }</dd>
				</div>
			) ) }
		</dl>
	);
};

const RespondToDisputeActions = ( {
	dispute,
	onAccept,
	onIssueRefund,
	isAccepting,
	refundGuidanceId,
	paymentMethod,
	bankName,
	customer,
	chargeCreated,
}: {
	dispute: WooPaymentsDispute;
	onAccept: () => void;
	onIssueRefund?: () => void;
	isAccepting: boolean;
	refundGuidanceId: string;
	paymentMethod?: string;
	bankName?: string;
	customer?: WooPaymentsBillingDetails;
	chargeCreated?: number | string;
} ) => {
	const disputeId = getDisputeId( dispute );
	const isInquiryStatus = isInquiry( dispute.status );
	const isVisaCompliance = isVisaComplianceDispute(
		dispute.reason,
		dispute.enhanced_eligibility_types
	);
	// Client 11.1.0 `dispute-awaiting-response-details.tsx:185-189`: staged evidence means the fee was already acknowledged.
	const [
		isVisaComplianceConditionAccepted,
		setVisaComplianceConditionAccepted,
	] = useState( !! dispute.evidence_details?.has_evidence );
	const isChallengeDisabled =
		isVisaCompliance && ! isVisaComplianceConditionAccepted;
	const hasStagedEvidence = !! dispute.evidence_details?.has_evidence;
	// Client 11.1.0 `dispute-awaiting-response-details.tsx:275-281`: Klarna inquiries cannot be challenged.
	const isDefendable = ! ( paymentMethod === 'klarna' && isInquiryStatus );
	const stepsProps = { dispute, customer, chargeCreated, bankName };
	let steps = <DisputeSteps { ...stepsProps } />;
	if ( isInquiryStatus ) {
		steps = isDefendable ? (
			<InquirySteps { ...stepsProps } />
		) : (
			<NotDefendableInquirySteps { ...stepsProps } />
		);
	} else if ( isVisaCompliance ) {
		steps = <NonCompliantDisputeSteps />;
	}
	let challengeLabel: string = isInquiryStatus
		? __( 'Submit evidence', 'woocommerce' )
		: __( 'Challenge dispute', 'woocommerce' );
	if ( hasStagedEvidence ) {
		challengeLabel = __( 'Continue with challenge', 'woocommerce' );
	}
	// Client 11.1.0 `dispute-awaiting-response-details.tsx:251-256`.
	const disputeTracksProperties = {
		dispute_id: disputeId,
		dispute_status: dispute.status,
		dispute_reason: dispute.reason,
		on_page: 'transaction_details',
	};

	// Client 11.1.0 `dispute-awaiting-response-details.tsx:317-470`.
	return (
		<>
			<DisputeNotice
				dispute={ dispute }
				paymentMethod={ paymentMethod }
				bankName={ bankName }
			/>
			{ hasStagedEvidence && (
				<Notice status="info" isDismissible={ false }>
					<Icon icon={ pencil } size={ 20 } />
					{ __(
						"You initiated a challenge to this dispute. Click 'Continue with challenge' to proceed with your draft response.",
						'woocommerce'
					) }
				</Notice>
			) }
			<DisputeSummaryRow dispute={ dispute } />
			{ steps }
			<DisputeDocumentationLink
				isInquiryStatus={ isInquiryStatus }
				isVisaCompliance={ isVisaCompliance }
				onClick={ () =>
					recordEvent(
						'wcpay_dispute_help_link_clicked',
						disputeTracksProperties
					)
				}
			/>
			{ isVisaCompliance && (
				<CheckboxControl
					onChange={ setVisaComplianceConditionAccepted }
					checked={ isVisaComplianceConditionAccepted }
					label={ __(
						'By checking this box, you acknowledge that challenging this Visa compliance dispute incurs a $500 USD network fee, which will be refunded if you win the dispute.',
						'woocommerce'
					) }
					__nextHasNoMarginBottom
				/>
			) }
			<div className="woocommerce-woopayments-money-movement__dispute-actions">
				{ isDefendable && isChallengeDisabled && (
					<Button variant="primary" disabled>
						{ challengeLabel }
					</Button>
				) }
				{ isDefendable && ! isChallengeDisabled && (
					<a
						className="components-button is-primary"
						href={ getDisputeChallengeUrl( disputeId ) }
						onClick={ ( event ) => {
							recordEvent( 'wcpay_dispute_challenge_clicked', {
								dispute_id: disputeId,
								status: dispute.status,
							} );
							handleSettingsPaymentsProviderRouteClick(
								getDisputeChallengeRoute( disputeId )
							)( event );
						} }
					>
						{ challengeLabel }
					</a>
				) }
				{ isInquiryStatus ? (
					<Button
						variant={ isDefendable ? 'tertiary' : 'primary' }
						disabled={ ! onIssueRefund }
						accessibleWhenDisabled
						aria-describedby={
							onIssueRefund ? undefined : refundGuidanceId
						}
						onClick={
							onIssueRefund
								? () => {
										recordEvent(
											'wcpay_dispute_inquiry_refund_modal_view',
											disputeTracksProperties
										);
										onIssueRefund();
								  }
								: undefined
						}
					>
						{ __( 'Issue refund', 'woocommerce' ) }
					</Button>
				) : (
					<Button
						variant="tertiary"
						disabled={ isAccepting }
						accessibleWhenDisabled
						isBusy={ isAccepting }
						onClick={
							isAccepting
								? undefined
								: () => {
										recordEvent(
											'wcpay_dispute_accept_modal_view',
											disputeTracksProperties
										);
										onAccept();
								  }
						}
					>
						{ __( 'Accept dispute', 'woocommerce' ) }
					</Button>
				) }
				{ ! isDefendable && (
					<Tooltip
						text={ __(
							'Challenge available if the inquiry escalates to a dispute',
							'woocommerce'
						) }
					>
						<span
							className="woocommerce-woopayments-dispute-pane__challenge-disabled"
							tabIndex={ 0 }
							role="button"
							aria-disabled="true"
							aria-label={ __(
								'Challenge dispute — available if the inquiry escalates to a dispute',
								'woocommerce'
							) }
						>
							<Button
								variant="primary"
								disabled
								tabIndex={ -1 }
								aria-hidden="true"
							>
								{ __( 'Challenge dispute', 'woocommerce' ) }
							</Button>
						</span>
					</Tooltip>
				) }
			</div>
			{ isInquiryStatus && ! onIssueRefund && (
				// Static guidance that describes the disabled refund button, so it is not spoken on mount.
				<Notice
					status="warning"
					isDismissible={ false }
					spokenMessage={ null }
				>
					<span id={ refundGuidanceId }>
						{ __(
							'A full refund is not available for this transaction.',
							'woocommerce'
						) }
					</span>
				</Notice>
			) }
		</>
	);
};

const ResolvedDisputeActions = ( {
	dispute,
	bankName,
}: {
	dispute: WooPaymentsDispute;
	bankName?: string;
} ) => {
	const statusDescription = getResolvedStatusDescription( dispute, bankName );

	if ( ! statusDescription && dispute.status !== 'lost' ) {
		return null;
	}

	const disputeId = getDisputeId( dispute );
	const shouldShowSubmittedEvidenceLink =
		hasSubmittedEvidence( dispute ) ||
		dispute.status === 'under_review' ||
		dispute.status === 'won' ||
		dispute.status === 'warning_under_review';

	// Client 11.1.0 `dispute-resolution-footer.tsx`: the outcome as a footer of the summary card.
	return (
		<CardFooter
			className={ `woocommerce-woopayments-dispute-pane__footer${
				dispute.status === 'warning_under_review'
					? ' woocommerce-woopayments-dispute-pane__footer--primary'
					: ''
			}` }
		>
			<Flex justify="space-between">
				<FlexItem>
					{ statusDescription ? (
						<>
							{ createInterpolateElement(
								statusDescription.message,
								{
									strong: <strong />,
								}
							) }{ ' ' }
							<ExternalLink href={ statusDescription.docUrl }>
								{ statusDescription.docLabel }
							</ExternalLink>
						</>
					) : (
						<LostDisputeDescription
							dispute={ dispute }
							bankName={ bankName }
						/>
					) }
				</FlexItem>
				{ shouldShowSubmittedEvidenceLink && (
					<FlexItem className="woocommerce-woopayments-dispute-pane__footer-actions">
						<Button
							variant="secondary"
							href={ getDisputeChallengeUrl( disputeId ) }
							onClick={ ( event: MouseEvent< HTMLElement > ) => {
								recordEvent(
									'wcpay_view_submitted_evidence_clicked',
									{
										dispute_id: disputeId,
										status: dispute.status,
									}
								);
								handleSettingsPaymentsProviderRouteClick(
									getDisputeChallengeRoute( disputeId )
								)( event );
							} }
						>
							{ [ 'won', 'lost' ].includes( dispute.status || '' )
								? __( 'View dispute details', 'woocommerce' )
								: __(
										'View submitted evidence',
										'woocommerce'
								  ) }
						</Button>
					</FlexItem>
				) }
			</Flex>
		</CardFooter>
	);
};

export const WooPaymentsTransactionDisputeDetails = ( {
	transaction,
	dispute,
	ordinal,
	total,
	onIssueRefund,
}: {
	transaction: WooPaymentsTransaction;
	dispute: WooPaymentsDispute;
	ordinal: number;
	total: number;
	onIssueRefund?: () => void;
} ) => {
	const [ currentDispute, setCurrentDispute ] = useState<
		WooPaymentsDispute | undefined
	>( dispute );
	const [ isAcceptModalOpen, setIsAcceptModalOpen ] = useState( false );
	const [ isAccepting, setIsAccepting ] = useState( false );
	const [ shouldFocusDisputeDetails, setShouldFocusDisputeDetails ] =
		useState( false );
	const disputeHeadingRef = useRef< HTMLHeadingElement | null >( null );
	const disputeOutcomeRef = useRef< HTMLDivElement | null >( null );

	useEffect( () => {
		setCurrentDispute( dispute );
	}, [ dispute ] );

	useEffect( () => {
		if ( ! shouldFocusDisputeDetails || isAcceptModalOpen ) {
			return;
		}

		( disputeHeadingRef.current || disputeOutcomeRef.current )?.focus();
		setShouldFocusDisputeDetails( false );
	}, [ isAcceptModalOpen, shouldFocusDisputeDetails ] );

	if ( ! currentDispute ) {
		return null;
	}

	const disputeId = getDisputeId( currentDispute );
	const acceptFee = getEffectiveDisputeFee( currentDispute );
	const idSuffix = `${ ordinal }-${ disputeId || 'unknown' }`.replace(
		/[^a-zA-Z0-9_-]/g,
		'-'
	);
	const headingId = `${ HEADING_ID_PREFIX }-${ idSuffix }`;
	const refundGuidanceId = `${ REFUND_GUIDANCE_ID_PREFIX }-${ idSuffix }`;
	const bankName = getBankName( transaction.payment_method_details );
	const isAwaitingResponseStatus = isAwaitingResponse(
		currentDispute.status
	);
	const closeAcceptModal = () => {
		// Client 11.1.0 `dispute-awaiting-response-details.tsx:243-249`: no closing while the accept request runs.
		if ( isAccepting ) {
			return;
		}
		setIsAcceptModalOpen( false );
	};
	const handleAcceptDispute = async () => {
		recordEvent( 'wcpay_dispute_accept_click', {
			dispute_id: disputeId,
			dispute_status: currentDispute.status,
			dispute_reason: currentDispute.reason,
			on_page: 'transaction_details',
		} );
		setIsAccepting( true );

		try {
			const closedDispute = await closeWooPaymentsDispute( disputeId );
			setCurrentDispute( {
				...currentDispute,
				...closedDispute,
			} );
			setIsAcceptModalOpen( false );
			// The modal stays open until the request ends, so focus moves to the outcome that replaces the pane.
			setShouldFocusDisputeDetails( true );
			dispatch( 'core/notices' ).createSuccessNotice(
				__( 'Dispute accepted.', 'woocommerce' )
			);
			recordEvent( 'wcpay_dispute_accepted', {
				dispute_id: disputeId,
			} );
		} catch ( error ) {
			dispatch( 'core/notices' ).createErrorNotice(
				getErrorMessage(
					error,
					__(
						'Unable to accept the WooPayments dispute.',
						'woocommerce'
					)
				)
			);
		} finally {
			setIsAccepting( false );
		}
	};

	// Client 11.1.0 `payment-details/summary/index.tsx:108-145` `DisputePane`, inside the summary card.
	return (
		<div className="woocommerce-woopayments-dispute-pane">
			{ total > 1 && (
				<p className="woocommerce-woopayments-money-movement__dispute-label">
					{ sprintf(
						/* translators: 1: dispute position, 2: total disputes on the charge. */
						__( 'Dispute %1$d of %2$d', 'woocommerce' ),
						ordinal,
						total
					) }
				</p>
			) }
			{ isAwaitingResponseStatus ? (
				<div
					className="woocommerce-woopayments-dispute-pane__details"
					role="group"
					aria-labelledby={ headingId }
				>
					<hr />
					<h2
						id={ headingId }
						ref={ disputeHeadingRef }
						tabIndex={ -1 }
					>
						{ __( 'Dispute details', 'woocommerce' ) }
					</h2>
					<div className="woocommerce-woopayments-money-movement__dispute-response">
						<RespondToDisputeActions
							dispute={ currentDispute }
							isAccepting={ isAccepting }
							refundGuidanceId={ refundGuidanceId }
							onAccept={ () => setIsAcceptModalOpen( true ) }
							onIssueRefund={ onIssueRefund }
							paymentMethod={
								transaction.payment_method_details?.type
							}
							bankName={ bankName }
							customer={ transaction.billing_details }
							chargeCreated={ transaction.created }
						/>
					</div>
				</div>
			) : (
				<div ref={ disputeOutcomeRef } tabIndex={ -1 }>
					<ResolvedDisputeActions
						dispute={ currentDispute }
						bankName={ bankName }
					/>
				</div>
			) }
			{ isAcceptModalOpen && (
				<Modal
					title={ __( 'Accept the dispute?', 'woocommerce' ) }
					onRequestClose={ closeAcceptModal }
				>
					<ul className="woocommerce-woopayments-money-movement__dispute-modal-lines">
						<li>
							<Icon icon={ backup } size={ 24 } />
							<span>
								{ createInterpolateElement(
									acceptFee
										? sprintf(
												/* translators: %s: dispute fee, <em>: emphasis HTML element. */
												__(
													'Accepting the dispute marks it as <em>Lost</em>. The disputed amount and the %s dispute fee will not be returned to you.',
													'woocommerce'
												),
												formatExplicitCurrency(
													acceptFee.amount,
													acceptFee.currency
												)
										  )
										: /* translators: <em>: emphasis HTML element. */
										  __(
												'Accepting the dispute marks it as <em>Lost</em>. The disputed amount will not be returned to you.',
												'woocommerce'
										  ),
									{ em: <em /> }
								) }
							</span>
						</li>
						<li>
							<Icon icon={ lock } size={ 24 } />
							<span>
								{ __(
									'This action is final and cannot be undone.',
									'woocommerce'
								) }
							</span>
						</li>
					</ul>
					<div className="woocommerce-woopayments-money-movement__dispute-modal-actions">
						<Button
							variant="tertiary"
							disabled={ isAccepting }
							accessibleWhenDisabled
							onClick={ closeAcceptModal }
						>
							{ __( 'Cancel', 'woocommerce' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							disabled={ isAccepting }
							accessibleWhenDisabled
							isBusy={ isAccepting }
							onClick={ handleAcceptDispute }
						>
							{ __( 'Accept dispute', 'woocommerce' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
};
