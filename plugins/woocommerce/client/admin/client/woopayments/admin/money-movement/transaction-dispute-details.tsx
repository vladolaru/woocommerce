/**
 * External dependencies
 */
import { Button, CheckboxControl, Modal } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import {
	createInterpolateElement,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { recordEvent } from '@woocommerce/tracks';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { closeWooPaymentsDispute } from './data';
import {
	ACTIONABLE_DISPUTE_STATUSES,
	isVisaComplianceDispute,
} from './dispute-evidence-fields';
import { getEffectiveDisputeFee } from './dispute-utils';
import type { WooPaymentsDispute, WooPaymentsTransaction } from './types';
import {
	formatAmount,
	formatDate,
	formatDisputeReasonLabel,
	formatLabel,
	getBankName,
	getDisputeId,
	getErrorMessage,
} from './utils';
import { getSettingsPaymentsProviderRouteUrl } from '../utils';

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

const getDisputeStatusLabel = ( status?: string ) => {
	if ( isAwaitingResponse( status ) ) {
		return __( 'Response needed', 'woocommerce' );
	}

	return formatLabel( status );
};

const formatDisputeMetadataDate = ( timestamp: unknown ) =>
	timestamp ? formatDate( Number( timestamp ) ) : '-';

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
	const closedAt = metadata.__dispute_closed_at
		? formatDate( Number( metadata.__dispute_closed_at ) )
		: '-';
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
		<p>
			{ createInterpolateElement( prefix, { strong: <strong /> } ) }{ ' ' }
			{ fee
				? sprintf(
						/* translators: %1$s: formatted dispute fee. */
						__(
							'The %1$s fee has been deducted from your account, and the disputed amount has been returned to your customer.',
							'woocommerce'
						),
						formatAmount( fee.amount, fee.currency )
				  )
				: __(
						'The disputed amount has been returned to your customer.',
						'woocommerce'
				  ) }{ ' ' }
			<a href={ fee ? DISPUTE_FEES_DOC_URL : DISPUTED_AMOUNTS_DOC_URL }>
				{ fee
					? __( 'Learn more about dispute fees.', 'woocommerce' )
					: __(
							'Learn more about disputed amounts.',
							'woocommerce'
					  ) }
			</a>
		</p>
	);
};

const getCustomerLabel = (
	dispute: WooPaymentsDispute,
	transaction: WooPaymentsTransaction
) =>
	dispute.customer_name ||
	dispute.order?.customer_name ||
	transaction.customer_name ||
	dispute.customer_email ||
	dispute.order?.customer_email ||
	transaction.customer_email ||
	'';

const getDueDate = ( dispute: WooPaymentsDispute ) =>
	dispute.evidence_details?.due_by || dispute.evidence_due_by;

const DetailRow = ( { label, value }: { label: string; value: ReactNode } ) => {
	if ( value === undefined || value === null || value === '' ) {
		return null;
	}

	return (
		<div>
			<dt>{ label }</dt>
			<dd>{ value }</dd>
		</div>
	);
};

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
		<a onClick={ onClick } href={ href }>
			{ label }
		</a>
	);
};

// Client 11.1.0 dispute-steps.tsx NonCompliantDisputeSteps.
const VisaComplianceDisputeSteps = () => (
	<div className="woocommerce-woopayments-money-movement__dispute-steps">
		<h4>{ __( 'Steps you can take', 'woocommerce' ) }</h4>
		<p>
			{ __(
				'We recommend reviewing your options before responding by the deadline.',
				'woocommerce'
			) }
		</p>
		<ul>
			<li>
				<strong>
					{ __( 'Accepting the dispute', 'woocommerce' ) }
				</strong>
				<p>
					{ __(
						'Accepting the dispute means you’ll forfeit the funds, pay the standard dispute fee, and avoid the $500 USD Visa network fee.',
						'woocommerce'
					) }
				</p>
				<a href={ VISA_COMPLIANCE_DISPUTES_DOC_URL }>
					{ __( 'Learn more', 'woocommerce' ) }
				</a>
			</li>
			<li>
				<strong>
					{ __( 'Challenge the dispute', 'woocommerce' ) }
				</strong>
				<p>
					{ __(
						'Challenging the dispute will incur a $500 USD Visa network fee, which is charged when you submit evidence. This fee will be refunded if you win the dispute.',
						'woocommerce'
					) }
				</p>
				<a href={ VISA_COMPLIANCE_DISPUTES_DOC_URL }>
					{ __( 'Learn more', 'woocommerce' ) }
				</a>
			</li>
		</ul>
		<p className="woocommerce-woopayments-money-movement__notice">
			{ createInterpolateElement(
				__(
					'<strong>The outcome of this dispute will be determined by Visa.</strong> WooPayments has no influence over the decision and is not liable for any chargebacks.',
					'woocommerce'
				),
				{ strong: <strong /> }
			) }
		</p>
	</div>
);

const RespondToDisputeActions = ( {
	dispute,
	onAccept,
	onIssueRefund,
	isAccepting,
	refundGuidanceId,
}: {
	dispute: WooPaymentsDispute;
	onAccept: () => void;
	onIssueRefund?: () => void;
	isAccepting: boolean;
	refundGuidanceId: string;
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
	const challengeLabel = isInquiryStatus
		? __( 'Submit evidence', 'woocommerce' )
		: __( 'Challenge dispute', 'woocommerce' );
	// Client 11.1.0 `dispute-awaiting-response-details.tsx:251-256`.
	const disputeTracksProperties = {
		dispute_id: disputeId,
		dispute_status: dispute.status,
		dispute_reason: dispute.reason,
		on_page: 'transaction_details',
	};

	return (
		<>
			<p>
				<strong>
					{ DISPUTE_CLAIMS[ dispute.reason || '' ] ??
						__(
							'The cardholder claims this is an unauthorized charge.',
							'woocommerce'
						) }
				</strong>
			</p>
			<p>
				{ isInquiryStatus
					? __(
							'Submit evidence to respond to this payment inquiry, or issue a full refund before responding.',
							'woocommerce'
					  )
					: __(
							'Challenge the dispute with evidence, or accept it if you do not want to respond.',
							'woocommerce'
					  ) }
			</p>
			{ isVisaCompliance && ! isInquiryStatus && (
				<VisaComplianceDisputeSteps />
			) }
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
				{ isChallengeDisabled ? (
					<Button variant="primary" disabled>
						{ challengeLabel }
					</Button>
				) : (
					<a
						className="components-button is-primary"
						href={ getDisputeChallengeUrl( disputeId ) }
						onClick={ () =>
							recordEvent( 'wcpay_dispute_challenge_clicked', {
								dispute_id: disputeId,
								status: dispute.status,
							} )
						}
					>
						{ challengeLabel }
					</a>
				) }
				{ isInquiryStatus ? (
					<Button
						variant="secondary"
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
						variant="secondary"
						isDestructive
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
			</div>
			{ isInquiryStatus && ! onIssueRefund && (
				<p
					id={ refundGuidanceId }
					className="woocommerce-woopayments-money-movement__notice"
				>
					{ __(
						'A full refund is not available for this transaction.',
						'woocommerce'
					) }
				</p>
			) }
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
	const disputeId = getDisputeId( dispute );
	const shouldShowSubmittedEvidenceLink =
		hasSubmittedEvidence( dispute ) ||
		dispute.status === 'under_review' ||
		dispute.status === 'won' ||
		dispute.status === 'warning_under_review';
	const statusDescription = getResolvedStatusDescription( dispute, bankName );

	return (
		<>
			{ statusDescription && (
				<p>
					{ createInterpolateElement( statusDescription.message, {
						strong: <strong />,
					} ) }{ ' ' }
					<a href={ statusDescription.docUrl }>
						{ statusDescription.docLabel }
					</a>
				</p>
			) }
			{ dispute.status === 'lost' && (
				<LostDisputeDescription
					dispute={ dispute }
					bankName={ bankName }
				/>
			) }
			{ shouldShowSubmittedEvidenceLink && (
				<a
					href={ getDisputeChallengeUrl( disputeId ) }
					onClick={ () =>
						recordEvent( 'wcpay_view_submitted_evidence_clicked', {
							dispute_id: disputeId,
							status: dispute.status,
						} )
					}
				>
					{ [ 'won', 'lost' ].includes( dispute.status || '' )
						? __( 'View dispute details', 'woocommerce' )
						: __( 'View submitted evidence', 'woocommerce' ) }
				</a>
			) }
		</>
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
	const disputeResponseRef = useRef< HTMLDivElement | null >( null );
	const shouldRestoreFocusAfterAcceptRef = useRef( false );

	useEffect( () => {
		setCurrentDispute( dispute );
	}, [ dispute ] );

	useEffect( () => {
		if ( ! shouldFocusDisputeDetails || isAcceptModalOpen ) {
			return;
		}

		disputeHeadingRef.current?.focus();
		setShouldFocusDisputeDetails( false );
	}, [ isAcceptModalOpen, shouldFocusDisputeDetails ] );

	if ( ! currentDispute ) {
		return null;
	}

	const disputeId = getDisputeId( currentDispute );
	const idSuffix = `${ ordinal }-${ disputeId || 'unknown' }`.replace(
		/[^a-zA-Z0-9_-]/g,
		'-'
	);
	const headingId = `${ HEADING_ID_PREFIX }-${ idSuffix }`;
	const refundGuidanceId = `${ REFUND_GUIDANCE_ID_PREFIX }-${ idSuffix }`;
	const dueDate = getDueDate( currentDispute );
	const isAwaitingResponseStatus = isAwaitingResponse(
		currentDispute.status
	);
	const closeAcceptModal = () => {
		shouldRestoreFocusAfterAcceptRef.current = false;
		setIsAcceptModalOpen( false );
	};
	const shouldRestoreFocusAfterAccept = () => {
		if ( shouldRestoreFocusAfterAcceptRef.current ) {
			return true;
		}

		const ownerDocument =
			disputeResponseRef.current?.ownerDocument ||
			disputeHeadingRef.current?.ownerDocument;
		const activeElement = ownerDocument?.activeElement;

		return (
			! activeElement ||
			activeElement === ownerDocument?.body ||
			!! disputeResponseRef.current?.contains( activeElement )
		);
	};
	const handleAcceptDispute = async () => {
		recordEvent( 'wcpay_dispute_accept_click', {
			dispute_id: disputeId,
			dispute_status: currentDispute.status,
			dispute_reason: currentDispute.reason,
			on_page: 'transaction_details',
		} );
		shouldRestoreFocusAfterAcceptRef.current = true;
		setIsAccepting( true );

		try {
			const closedDispute = await closeWooPaymentsDispute( disputeId );
			const shouldRestoreFocus = shouldRestoreFocusAfterAccept();
			shouldRestoreFocusAfterAcceptRef.current = false;
			setCurrentDispute( {
				...currentDispute,
				...closedDispute,
			} );
			setIsAcceptModalOpen( false );
			if ( shouldRestoreFocus ) {
				setShouldFocusDisputeDetails( true );
			}
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
			shouldRestoreFocusAfterAcceptRef.current = false;
		} finally {
			setIsAccepting( false );
		}
	};

	return (
		<section
			className="woocommerce-woopayments-overview-card woocommerce-woopayments-money-movement__dispute-details"
			aria-labelledby={ headingId }
		>
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
			<h3 id={ headingId } ref={ disputeHeadingRef } tabIndex={ -1 }>
				{ __( 'Dispute details', 'woocommerce' ) }
			</h3>
			<dl className="woocommerce-woopayments-money-movement__details woocommerce-woopayments-money-movement__details--nested">
				<DetailRow
					label={ __( 'Dispute ID', 'woocommerce' ) }
					value={ disputeId }
				/>
				<DetailRow
					label={ __( 'Reason', 'woocommerce' ) }
					value={ formatDisputeReasonLabel( currentDispute.reason ) }
				/>
				<DetailRow
					label={ __( 'Status', 'woocommerce' ) }
					value={ getDisputeStatusLabel( currentDispute.status ) }
				/>
				<DetailRow
					label={ __( 'Response due', 'woocommerce' ) }
					value={ dueDate ? formatDate( dueDate ) : '' }
				/>
				<DetailRow
					label={ __( 'Amount', 'woocommerce' ) }
					value={ formatAmount(
						currentDispute.amount ?? transaction.amount,
						currentDispute.currency || transaction.currency
					) }
				/>
				<DetailRow
					label={ __( 'Customer', 'woocommerce' ) }
					value={ getCustomerLabel( currentDispute, transaction ) }
				/>
			</dl>
			<div
				ref={ disputeResponseRef }
				className="woocommerce-woopayments-money-movement__dispute-response"
			>
				{ isAwaitingResponseStatus ? (
					<RespondToDisputeActions
						dispute={ currentDispute }
						isAccepting={ isAccepting }
						refundGuidanceId={ refundGuidanceId }
						onAccept={ () => setIsAcceptModalOpen( true ) }
						onIssueRefund={ onIssueRefund }
					/>
				) : (
					<ResolvedDisputeActions
						dispute={ currentDispute }
						bankName={ getBankName(
							transaction.payment_method_details
						) }
					/>
				) }
			</div>
			{ isAcceptModalOpen && (
				<Modal
					title={ __( 'Accept the dispute?', 'woocommerce' ) }
					onRequestClose={ closeAcceptModal }
				>
					<p>
						{ sprintf(
							/* translators: %s: dispute ID. */
							__(
								'Accepting dispute %s marks it as lost. This action cannot be undone.',
								'woocommerce'
							),
							disputeId
						) }
					</p>
					<div className="woocommerce-woopayments-money-movement__dispute-modal-actions">
						<Button variant="tertiary" onClick={ closeAcceptModal }>
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
		</section>
	);
};
