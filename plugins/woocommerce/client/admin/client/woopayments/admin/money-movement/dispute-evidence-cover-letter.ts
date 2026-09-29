/* eslint-disable @wordpress/i18n-hyphenated-range -- Client 11.1.0 cover letter copy says "7 - 10 business days"; kept verbatim. */
/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { WooPaymentsDispute } from './types';
import {
	getRecommendedDocumentFields,
	getRecommendedShippingDocumentFields,
	needsShipping,
	type DocumentEvidenceField,
	type EvidenceField,
} from './dispute-evidence-fields';

type CoverLetterMerchantDetails = {
	merchantName?: string;
	merchantAddress?: string;
	merchantEmail?: string;
	merchantPhone?: string;
};

type DisputeCoverLetterInput = CoverLetterMerchantDetails & {
	dispute: WooPaymentsDispute;
	bankName?: string;
	today?: string;
	productType?: string;
	evidence?: Partial< Record< EvidenceField, string > > &
		Record< string, string | undefined >;
	refundStatus?: string;
	duplicateStatus?: string;
};

type CoverLetterData = {
	caseNumber: string;
	transactionId: string;
	transactionDate: string;
	customerName: string;
	product: string;
	orderDate: string;
	deliveryDate: string;
	refundAmount: string;
	refundStatus?: string;
	duplicateStatus?: string;
};

type WooPaymentsSupportSettings = {
	account_business_support_email?: unknown;
	account_business_support_phone?: unknown;
};

type StoreAddressSettings = {
	woocommerce_default_country?: string;
	woocommerce_store_address?: string;
	woocommerce_store_address_2?: string;
	woocommerce_store_city?: string;
	woocommerce_store_postcode?: string;
};

const getNonEmptyString = ( value: unknown ) =>
	typeof value === 'string' && value ? value : undefined;

/**
 * Merchant details for the cover letter header, from the same sources client 11.1.0 reads:
 * the site title and store address in `wcSettings` (cover-letter-generator.ts:11-51) and the
 * support email and phone from the payments settings store (new-evidence/index.tsx:132).
 *
 * @param settings WooPayments settings from the `wc/payments/settings` store.
 */
export const getCoverLetterMerchantDetails = (
	settings?: WooPaymentsSupportSettings | null
): CoverLetterMerchantDetails => {
	const general: StoreAddressSettings =
		window.wcSettings?.admin?.preloadSettings?.general || {};
	const [ storeCountry, storeState ] = (
		general.woocommerce_default_country || ':'
	).split( ':' );

	return {
		merchantName:
			window.wcSettings?.siteTitle ||
			__( '<Your Business Name>', 'woocommerce' ),
		merchantAddress: [
			general.woocommerce_store_address || '',
			general.woocommerce_store_address_2 || '',
			general.woocommerce_store_city || '',
			storeState || '',
			general.woocommerce_store_postcode || '',
			storeCountry || '',
		]
			.filter( Boolean )
			.join( ', ' ),
		merchantEmail: getNonEmptyString(
			settings?.account_business_support_email
		),
		merchantPhone: getNonEmptyString(
			settings?.account_business_support_phone
		),
	};
};

const getEvidenceValue = (
	dispute: WooPaymentsDispute,
	evidence: DisputeCoverLetterInput[ 'evidence' ],
	field: DocumentEvidenceField
) => {
	const value = evidence?.[ field ] || dispute.evidence?.[ field ];

	return typeof value === 'string' ? value : '';
};

const getCharge = ( dispute: WooPaymentsDispute ) =>
	typeof dispute.charge === 'object' ? dispute.charge : undefined;

const getChargeId = ( dispute: WooPaymentsDispute ) => {
	if ( typeof dispute.charge === 'string' ) {
		return dispute.charge;
	}

	return (
		dispute.charge?.id ||
		dispute.charge_id ||
		dispute.transaction_id ||
		dispute.payment_intent ||
		__( '<Transaction ID>', 'woocommerce' )
	);
};

const formatDate = (
	value: string | number | undefined,
	placeholder: string
) => {
	if ( value === undefined || value === '' ) {
		return placeholder;
	}

	const date =
		typeof value === 'number'
			? new Date( value * 1000 )
			: new Date( value );

	if ( Number.isNaN( date.getTime() ) ) {
		return String( value );
	}

	return new Intl.DateTimeFormat( 'en-US', {
		month: 'short',
		day: 'numeric',
		year: 'numeric',
	} ).format( date );
};

const getProduct = (
	dispute: WooPaymentsDispute,
	evidence: DisputeCoverLetterInput[ 'evidence' ]
) =>
	evidence?.product_description ||
	getCharge( dispute )
		?.level3?.line_items?.map( ( item ) => item.product_description )
		.filter( Boolean )
		.join( ', ' ) ||
	__( '<Product>', 'woocommerce' );

const getAttachmentLines = ( {
	dispute,
	productType,
	evidence,
	refundStatus,
	duplicateStatus,
}: DisputeCoverLetterInput ) => {
	const recommendedDocuments = getRecommendedDocumentFields( {
		reason: dispute.reason,
		productType,
		refundStatus,
		duplicateStatus,
		enhancedEligibilityTypes: dispute.enhanced_eligibility_types,
		evidence: dispute.evidence,
	} );
	const shippingDocuments = needsShipping( dispute.reason, productType )
		? getRecommendedShippingDocumentFields( dispute.reason, productType )
		: [];
	const labelsByField = [
		...recommendedDocuments,
		...shippingDocuments,
	].reduce< Partial< Record< DocumentEvidenceField, string > > >(
		( labels, document ) => ( {
			...labels,
			[ document.key ]: document.label,
		} ),
		{}
	);
	const uploadedLabels = Object.entries( labelsByField )
		.filter( ( [ field ] ) =>
			getEvidenceValue(
				dispute,
				evidence,
				field as DocumentEvidenceField
			)
		)
		.map( ( [ , label ] ) => label || '' )
		.filter( Boolean );
	const formatAttachment = ( label: string, index: number ) =>
		sprintf(
			/* translators: %1$s: label, %2$s: attachment letter */
			__( '• %1$s (Attachment %2$s)', 'woocommerce' ),
			label,
			String.fromCharCode( 65 + index )
		);

	if ( ! uploadedLabels.length ) {
		return [
			formatAttachment(
				__( '<Attachment description>', 'woocommerce' ),
				0
			),
			formatAttachment(
				__( '<Attachment description>', 'woocommerce' ),
				1
			),
		].join( '\n' );
	}

	return uploadedLabels.map( formatAttachment ).join( '\n' );
};

// Client 11.1.0 cover-letter-generator.ts:680-1043.
const getOpening = ( data: CoverLetterData ) =>
	sprintf(
		/* translators: %1$s: case number, %2$s: transaction ID, %3$s: transaction date */
		__(
			'We are submitting evidence in response to chargeback #%1$s for transaction #%2$s on %3$s.',
			'woocommerce'
		),
		data.caseNumber,
		data.transactionId,
		data.transactionDate
	);

const getDocumentation = ( attachmentsList: string ) =>
	`${ __(
		'To support our case, we are providing the following documentation:',
		'woocommerce'
	) }
${ attachmentsList }`;

const getRequestUs = () =>
	__(
		'Based on this information, we respectfully request that the chargeback be reversed. Please let us know if any further details are required.',
		'woocommerce'
	);

const generateBodyUnrecognized = (
	data: CoverLetterData,
	attachmentsList: string
) => `${ getOpening( data ) }

${ sprintf(
	/* translators: %1$s: customer name, %2$s: product, %3$s: order date */
	__(
		'Our records indicate that the customer and legitimate cardholder, %1$s, ordered %2$s on %3$s.',
		'woocommerce'
	),
	data.customerName,
	data.product,
	data.orderDate
) }

${ getDocumentation( attachmentsList ) }

${ __(
	'Based on this information, we respectfully request that the chargeback be reversed. Please let me know if any further details are required.',
	'woocommerce'
) }`;

const generateBodyCreditNotProcessed = (
	data: CoverLetterData,
	attachmentsList: string
) => {
	if ( data.refundStatus === 'refund_has_been_issued' ) {
		return `${ getOpening( data ) }

${ sprintf(
	/* translators: %1$s: customer name, %2$s: order date, %3$s: refund amount */
	__(
		"Our records indicate that the customer, %1$s, was refunded on %2$s for the amount of %3$s. The refund was processed through our payment provider and should be visible on the customer's statement within 7 - 10 business days.",
		'woocommerce'
	),
	data.customerName,
	data.orderDate,
	data.refundAmount
) }

${ getDocumentation( attachmentsList ) }

${ getRequestUs() }`;
	}

	return `${ getOpening( data ) }
${ __(
	'The customer requested a refund outside of the eligible window outlined in our refund policy, which was clearly presented on the website and on the order confirmation.',
	'woocommerce'
) }

${ getDocumentation( attachmentsList ) }

${ getRequestUs() }`;
};

const generateBodyGeneral = (
	data: CoverLetterData,
	attachmentsList: string
) => `${ getOpening( data ) }

${ sprintf(
	/* translators: %1$s: customer name, %2$s: product, %3$s: order date, %4$s: delivery date */
	__(
		'Our records indicate that the customer, %1$s, ordered %2$s on %3$s and received it on %4$s.',
		'woocommerce'
	),
	data.customerName,
	data.product,
	data.orderDate,
	data.deliveryDate
) }

${ getDocumentation( attachmentsList ) }

${ getRequestUs() }`;

const generateBodyProductUnacceptable = (
	data: CoverLetterData,
	attachmentsList: string
) => `${ getOpening( data ) }

${ sprintf(
	/* translators: %1$s: customer name, %2$s: product, %3$s: order date */
	__(
		'Our records indicate that the customer, %1$s, ordered %2$s on %3$s. The product matched the description provided at the time of sale, and we did not receive any indication from the customer that it was defective or not as described.',
		'woocommerce'
	),
	data.customerName,
	data.product,
	data.orderDate
) }

${ getDocumentation( attachmentsList ) }

${ getRequestUs() }`;

const generateBodySubscriptionCanceled = (
	data: CoverLetterData,
	attachmentsList: string
) => `${ getOpening( data ) }

${ sprintf(
	/* translators: %1$s: customer name, %2$s: product */
	__(
		"Our records indicate that the customer, %1$s, subscribed to %2$s and was billed according to the terms accepted at the time of signup. The customer's account remained active and no cancellation was recorded prior to the billing date.",
		'woocommerce'
	),
	data.customerName,
	data.product
) }

${ getDocumentation( attachmentsList ) }

${ getRequestUs() }`;

const generateBodyDuplicate = (
	data: CoverLetterData,
	attachmentsList: string
) => {
	if ( data.duplicateStatus === 'is_duplicate' ) {
		return `${ getOpening( data ) }
${ sprintf(
	/* translators: %1$s: order date, %2$s: refund amount */
	__(
		"Our records indicate that this charge was a duplicate of a previous transaction. A refund has already been issued to the customer on %1$s for the amount of %2$s. This refund should be visible on the customer's statement within 7 - 10 business days.",
		'woocommerce'
	),
	data.orderDate,
	data.refundAmount
) }

${ getDocumentation( attachmentsList ) }

${ getRequestUs() }`;
	}

	return `${ getOpening( data ) }
${ sprintf(
	/* translators: %1$s: case number, %2$s: transaction ID */
	__(
		'Our records show that the customer placed two distinct orders: %1$s and %2$s. Both transactions were legitimate, fulfilled independently, and are not duplicates.',
		'woocommerce'
	),
	data.caseNumber,
	data.transactionId
) }

${ getDocumentation( attachmentsList ) }

${ getRequestUs() }`;
};

// Client 11.1.0 cover-letter-generator.ts:1045-1062; product_not_received shares the general body.
const BODY_GENERATORS: Record<
	string,
	( data: CoverLetterData, attachmentsList: string ) => string
> = {
	product_not_received: generateBodyGeneral,
	credit_not_processed: generateBodyCreditNotProcessed,
	product_unacceptable: generateBodyProductUnacceptable,
	subscription_canceled: generateBodySubscriptionCanceled,
	duplicate: generateBodyDuplicate,
	fraudulent: generateBodyUnrecognized,
	unrecognized: generateBodyUnrecognized,
};

export const generateDisputeCoverLetter = ( {
	dispute,
	merchantName = __( '<Your Business Name>', 'woocommerce' ),
	merchantAddress = '',
	merchantEmail = __( '<business@email.com>', 'woocommerce' ),
	merchantPhone = __( '<Business Phone Number>', 'woocommerce' ),
	bankName = __( '<Bank Name>', 'woocommerce' ),
	today = formatDate( Math.floor( Date.now() / 1000 ), '' ),
	productType,
	evidence = {},
	refundStatus,
	duplicateStatus,
}: DisputeCoverLetterInput ): string => {
	const charge = getCharge( dispute );
	const data: CoverLetterData = {
		caseNumber:
			dispute.id ||
			dispute.dispute_id ||
			__( '<Case Number>', 'woocommerce' ),
		transactionId: getChargeId( dispute ),
		transactionDate: formatDate(
			dispute.created,
			__( '<Transaction Date>', 'woocommerce' )
		),
		customerName:
			charge?.billing_details?.name ||
			__( '<Customer Name>', 'woocommerce' ),
		product: getProduct( dispute, evidence ),
		orderDate: formatDate(
			charge?.created,
			__( '<Order Date>', 'woocommerce' )
		),
		deliveryDate: formatDate(
			evidence.shipping_date,
			__( '<Delivery/Service Date>', 'woocommerce' )
		),
		refundAmount: dispute.amount
			? `${ ( dispute.amount / 100 ).toFixed(
					2
			  ) } ${ dispute.currency?.toUpperCase() }`
			: __( '[Refund Amount]', 'woocommerce' ),
		refundStatus,
		duplicateStatus,
	};
	const attachmentsList = getAttachmentLines( {
		dispute,
		productType,
		evidence,
		refundStatus,
		duplicateStatus,
	} );
	const generateBody =
		BODY_GENERATORS[ dispute.reason || '' ] || generateBodyGeneral;

	return `${ merchantName }
${ merchantAddress }
${ merchantEmail }
${ merchantPhone }
${ today }

${ sprintf(
	/* translators: %s: acquiring bank name */
	__( 'To: %s', 'woocommerce' ),
	bankName
) }
${ sprintf(
	/* translators: %s: case number */
	__( 'Subject: Chargeback Dispute – Case #%s', 'woocommerce' ),
	data.caseNumber
) }

${ __( 'Dear Dispute Resolution Team,', 'woocommerce' ) }

${ generateBody( data, attachmentsList ) }

${ __( 'Thank you,', 'woocommerce' ) }
${ merchantName }`;
};
