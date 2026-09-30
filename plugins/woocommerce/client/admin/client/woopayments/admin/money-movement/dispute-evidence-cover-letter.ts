/* eslint-disable @wordpress/i18n-hyphenated-range -- Client 11.1.0 cover letter copy says "7 - 10 business days"; kept verbatim. */
/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import type { WooPaymentsDispute } from './types';
import type { EvidenceField } from './dispute-evidence-fields';

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

	// Client 11.1.0 `formatMerchantAddress()`: WooCommerce's formatted store address, else the joined fields.
	const formattedStoreAddress = getNonEmptyString(
		(
			window.wcSettings?.admin as
				| { woopaymentsSettings?: { formattedStoreAddress?: unknown } }
				| undefined
		 )?.woopaymentsSettings?.formattedStoreAddress
	);

	return {
		merchantName:
			window.wcSettings?.siteTitle ||
			__( '<Your Business Name>', 'woocommerce' ),
		merchantAddress:
			formattedStoreAddress ||
			[
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
	field: string
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

// Client 11.1.0 `formatDateTimeFromTimestamp( ..., { separator: ', ' } )`: site formats, read as UTC.
const formatDate = (
	value: string | number | undefined,
	placeholder: string,
	includeTime = false
) => {
	if ( value === undefined || value === '' || value === null ) {
		return placeholder;
	}

	const date =
		typeof value === 'number'
			? new Date( value * 1000 )
			: new Date( value );

	if ( Number.isNaN( date.getTime() ) ) {
		return String( value );
	}

	const { formats } = getDateSettings();

	return dateI18n(
		includeTime ? `${ formats.date }, ${ formats.time }` : formats.date,
		date.toISOString(),
		undefined
	);
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

// Client 11.1.0 cover-letter-generator.ts:67-650 `generateAttachments()`: which evidence documents the
// letter lists, under which label and in which order, by reason, product type and refund or duplicate status.
type AttachmentRule = {
	reasons: string[];
	label?: string;
	order?: number;
	productTypes?: string[];
	refundStatuses?: string[];
};

const STANDARD_ATTACHMENTS: Array< {
	key: string;
	label: string;
	labelForReasons?: Array< {
		reasons: string[];
		label: string;
		productTypes?: string[];
		refundStatuses?: string[];
	} >;
	labelForStatus?: { status: string; label: string };
	onlyForReasons?: string[];
	onlyForProductTypes?: string[];
	excludeWhen?: ( reason: string, status?: string ) => boolean;
	order?: number;
	orderForReasons?: Array< {
		reasons: string[];
		order: number;
		productTypes?: string[];
		refundStatuses?: string[];
	} >;
} > = [
	{
		key: 'receipt',
		label: __( 'Order receipt', 'woocommerce' ),
		labelForReasons: [
			{
				// For non-physical product types credit_not_processed, RECEIPT is "Refund receipt".
				// For physical_product, RECEIPT stays "Order receipt" (REFUND_RECEIPT_DOCUMENTATION is "Refund receipt").
				reasons: [ 'credit_not_processed' ],
				label: __( 'Refund receipt', 'woocommerce' ),
				productTypes: [
					'booking_reservation',
					'digital_product_or_service',
					'offline_service',
					'event',
					'other',
				],
				refundStatuses: [ 'refund_has_been_issued' ],
			},
		],
	},
	{
		// For duplicate disputes:
		// - is_duplicate: shows as "Refund receipt" (REFUND_RECEIPT_DOCUMENTATION maps to this)
		// - is_not_duplicate: shows as "Any additional receipts"
		// For credit_not_processed + physical_product + refund_has_been_issued:
		// - shows as "Refund receipt" (REFUND_RECEIPT_DOCUMENTATION)
		key: 'duplicate_charge_documentation',
		label: __( 'Any additional receipts', 'woocommerce' ),
		onlyForReasons: [ 'duplicate', 'credit_not_processed' ],
		labelForStatus: {
			status: 'is_duplicate',
			label: __( 'Refund receipt', 'woocommerce' ),
		},
		labelForReasons: [
			{
				reasons: [ 'credit_not_processed' ],
				label: __( 'Refund receipt', 'woocommerce' ),
				refundStatuses: [ 'refund_has_been_issued' ],
				productTypes: [ 'physical_product' ],
			},
		],
	},
	{
		// For fraudulent disputes, this shows as "Prior undisputed transaction history"
		// and should appear before Customer communication.
		key: 'access_activity_log',
		label: __( 'Prior undisputed transaction history', 'woocommerce' ),
		onlyForReasons: [ 'fraudulent' ],
		labelForReasons: [
			{
				reasons: [ 'fraudulent' ],
				label: __( 'Login or usage records', 'woocommerce' ),
				productTypes: [ 'digital_product_or_service' ],
			},
		],
	},
	{
		key: 'customer_communication',
		label: __( 'Customer communication', 'woocommerce' ),
		labelForReasons: [
			{
				// For most product types with credit_not_processed,
				// CUSTOMER_COMMUNICATION is repurposed as "Other documents". For physical_product,
				// it keeps the default "Customer communication" label since the matrix includes
				// it explicitly with its proper label.
				reasons: [ 'credit_not_processed' ],
				label: __( 'Other documents', 'woocommerce' ),
				productTypes: [
					'booking_reservation',
					'digital_product_or_service',
					'offline_service',
					'event',
					'other',
				],
				refundStatuses: [
					'refund_was_not_owed',
					'refund_has_been_issued',
				],
			},
		],
		// When repurposed as "Other documents", it should appear last
		orderForReasons: [
			{
				reasons: [ 'credit_not_processed' ],
				order: 100,
				productTypes: [
					'booking_reservation',
					'digital_product_or_service',
					'offline_service',
					'event',
					'other',
				],
				refundStatuses: [
					'refund_was_not_owed',
					'refund_has_been_issued',
				],
			},
			{
				// For product_unacceptable with physical_product, customer communication
				// should appear after customer's signature (index 4) to match the UI order
				reasons: [ 'product_unacceptable' ],
				order: 5,
				productTypes: [ 'physical_product' ],
			},
		],
	},
	{
		key: 'customer_signature',
		label: __( "Customer's signature", 'woocommerce' ),
		// Customer's signature is only shown in the UI for physical products
		onlyForProductTypes: [ 'physical_product' ],
	},
	{
		key: 'refund_policy',
		label: __( 'Refund policy', 'woocommerce' ),
		// For subscription_canceled, refund policy should appear after cancellation logs (index 9).
		// For duplicate, refund policy should appear after proof of active subscription (index 8).
		orderForReasons: [
			{
				reasons: [ 'subscription_canceled', 'duplicate' ],
				order: 10,
			},
		],
	},
	{
		key: 'shipping_documentation',
		label: __( 'Proof of shipping', 'woocommerce' ),
		labelForReasons: [
			{
				reasons: [ 'credit_not_processed' ],
				label: __( 'Return tracking', 'woocommerce' ),
				refundStatuses: [ 'refund_has_been_issued' ],
				productTypes: [ 'physical_product', 'other' ],
			},
		],
		// For credit_not_processed with refund_has_been_issued, return tracking should
		// appear after refund receipt (index 1) but before customer communication (index 3)
		orderForReasons: [
			{
				reasons: [ 'credit_not_processed' ],
				order: 2,
				refundStatuses: [ 'refund_has_been_issued' ],
				productTypes: [ 'physical_product', 'other' ],
			},
		],
	},
	{
		key: 'service_documentation',
		label: __( 'Item condition', 'woocommerce' ),
		labelForReasons: [
			{
				// For product_not_received disputes with booking_reservation product type
				reasons: [ 'product_not_received' ],
				label: __(
					'Reservation or booking confirmation',
					'woocommerce'
				),
				productTypes: [ 'booking_reservation' ],
			},
			{
				// For product_not_received disputes with offline_service product type
				reasons: [ 'product_not_received' ],
				label: __( 'Proof of service completion', 'woocommerce' ),
				productTypes: [ 'offline_service' ],
			},
			{
				// For product_not_received disputes with event product type
				reasons: [ 'product_not_received' ],
				label: __( 'Attendance confirmation', 'woocommerce' ),
				productTypes: [ 'event' ],
			},
			{
				// For product_not_received disputes with other product type
				reasons: [ 'product_not_received' ],
				label: __( 'Service completion records', 'woocommerce' ),
				productTypes: [ 'other' ],
			},
			{
				// For product_unacceptable disputes with booking_reservation/event product type
				reasons: [ 'product_unacceptable' ],
				label: __( 'Event or booking documentation', 'woocommerce' ),
				productTypes: [ 'booking_reservation', 'event' ],
			},
			{
				// For product_unacceptable disputes with physical_product type
				reasons: [ 'product_unacceptable' ],
				label: __( "Item's condition", 'woocommerce' ),
				productTypes: [ 'physical_product' ],
			},
			{
				// For product_unacceptable disputes with digital_product_or_service/offline_service type
				reasons: [ 'product_unacceptable' ],
				label: __( 'Proof of delivered service', 'woocommerce' ),
				productTypes: [
					'digital_product_or_service',
					'offline_service',
				],
			},
			{
				// For fraudulent disputes with digital_product_or_service type,
				// SERVICE_DOCUMENTATION is repurposed as "Prior undisputed transaction history"
				// because ACCESS_ACTIVITY_LOG is used for "Login or usage records".
				reasons: [ 'fraudulent' ],
				label: __(
					'Prior undisputed transaction history',
					'woocommerce'
				),
				productTypes: [ 'digital_product_or_service' ],
			},
			{
				// For credit_not_processed × physical_product × refund_was_not_owed,
				// SERVICE_DOCUMENTATION is used as "Other documents" since
				// UNCATEGORIZED_FILE is used for "Proof of acceptance".
				reasons: [ 'credit_not_processed' ],
				label: __( 'Other documents', 'woocommerce' ),
				refundStatuses: [ 'refund_was_not_owed' ],
				productTypes: [ 'physical_product' ],
			},
		],
		// For product_unacceptable with booking_reservation/digital_product_or_service/offline_service/event, this should appear first (before Order receipt)
		orderForReasons: [
			{
				reasons: [ 'product_unacceptable' ],
				order: -1,
				productTypes: [
					'booking_reservation',
					'digital_product_or_service',
					'offline_service',
					'event',
				],
			},
			{
				// For fraudulent digital, "Prior undisputed transaction history" should appear
				// before Customer communication (index 3), so use order 2 (ties broken by arrayIndex 7 > 2).
				reasons: [ 'fraudulent' ],
				order: 2,
				productTypes: [ 'digital_product_or_service' ],
			},
			{
				// For credit_not_processed refund_was_not_owed, Other documents should appear last
				reasons: [ 'credit_not_processed' ],
				order: 100,
				refundStatuses: [ 'refund_was_not_owed' ],
				productTypes: [ 'physical_product' ],
			},
			{
				// For product_not_received non-physical/non-digital types, documentation should appear
				// after Order receipt (index 0) but before Customer communication (index 3).
				reasons: [ 'product_not_received' ],
				order: 1,
				productTypes: [ 'offline_service', 'event', 'other' ],
			},
		],
	},
	{
		// For non-fraudulent disputes, "Subscription logs" appears in its original position.
		// For duplicate disputes, relabeled as "Proof of active subscription".
		// For digital_product_or_service, relabeled as "Login or usage records".
		key: 'access_activity_log',
		label: __( 'Subscription logs', 'woocommerce' ),
		excludeWhen: ( reason: string ) => reason === 'fraudulent',
		labelForReasons: [
			{
				reasons: [ 'duplicate' ],
				label: __( 'Proof of active subscription', 'woocommerce' ),
			},
			{
				reasons: [
					'product_not_received',
					'product_unacceptable',
					'subscription_canceled',
				],
				label: __( 'Login or usage records', 'woocommerce' ),
				productTypes: [ 'digital_product_or_service' ],
			},
		],
		orderForReasons: [
			{
				reasons: [ 'product_not_received', 'product_unacceptable' ],
				order: 1,
				productTypes: [ 'digital_product_or_service' ],
			},
		],
	},
	{
		key: 'cancellation_rebuttal',
		label: __( 'Cancellation logs', 'woocommerce' ),
		onlyForReasons: [
			'subscription_canceled',
			'product_not_received',
			'credit_not_processed',
		],
		// For product_not_received disputes, this field is labeled "Cancellation confirmation"
		labelForReasons: [
			{
				reasons: [ 'product_not_received' ],
				label: __( 'Cancellation confirmation', 'woocommerce' ),
			},
		],
		// For subscription_canceled with digital_product_or_service, cancellation logs
		// should appear before Customer communication (order 2)
		orderForReasons: [
			{
				reasons: [ 'subscription_canceled' ],
				order: 2,
				productTypes: [ 'digital_product_or_service' ],
			},
		],
	},
	{
		key: 'cancellation_policy',
		label: __( 'Cancellation policy', 'woocommerce' ),
		// For subscription_canceled and duplicate disputes, this field is labeled "Terms of service"
		labelForReasons: [
			{
				reasons: [ 'subscription_canceled', 'duplicate' ],
				label: __( 'Terms of service', 'woocommerce' ),
			},
			{
				reasons: [ 'product_unacceptable' ],
				label: __( 'Terms of service', 'woocommerce' ),
				productTypes: [ 'other' ],
			},
		],
	},
	{
		key: 'uncategorized_file',
		label: __( 'Other documents', 'woocommerce' ),
		labelForReasons: [
			{
				reasons: [ 'credit_not_processed' ],
				label: __( 'Proof of acceptance', 'woocommerce' ),
				refundStatuses: [ 'refund_was_not_owed' ],
			},
		],
		// When used as "Proof of acceptance", it should appear first
		orderForReasons: [
			{
				reasons: [ 'credit_not_processed' ],
				order: -1,
				refundStatuses: [ 'refund_was_not_owed' ],
			},
		],
	},
];

const matchesRule = (
	entry: AttachmentRule,
	reason: string,
	productType?: string,
	refundStatus?: string
) =>
	entry.reasons.includes( reason ) &&
	( ! entry.productTypes ||
		( !! productType && entry.productTypes.includes( productType ) ) ) &&
	( ! entry.refundStatuses ||
		( !! refundStatus && entry.refundStatuses.includes( refundStatus ) ) );

const getAttachmentLines = ( {
	dispute,
	productType,
	evidence,
	refundStatus,
	duplicateStatus,
}: DisputeCoverLetterInput ) => {
	const reason = dispute.reason || '';
	const resolved: Array< {
		displayLabel: string;
		arrayIndex: number;
		sortOrder: number;
	} > = [];

	STANDARD_ATTACHMENTS.forEach(
		(
			{
				key,
				label,
				labelForReasons,
				labelForStatus,
				onlyForReasons,
				onlyForProductTypes,
				excludeWhen,
				order,
				orderForReasons,
			},
			index
		) => {
			if ( onlyForReasons && ! onlyForReasons.includes( reason ) ) {
				return;
			}
			if (
				onlyForProductTypes &&
				( ! productType ||
					! onlyForProductTypes.includes( productType ) )
			) {
				return;
			}
			if ( excludeWhen?.( reason, duplicateStatus ) ) {
				return;
			}
			if ( ! getEvidenceValue( dispute, evidence, key ) ) {
				return;
			}

			let displayLabel = label;
			if ( labelForStatus && duplicateStatus === labelForStatus.status ) {
				displayLabel = labelForStatus.label;
			} else {
				const match = labelForReasons?.find( ( entry ) =>
					matchesRule( entry, reason, productType, refundStatus )
				);
				displayLabel = match?.label ?? displayLabel;
			}

			const orderMatch = orderForReasons?.find( ( entry ) =>
				matchesRule( entry, reason, productType, refundStatus )
			);

			resolved.push( {
				displayLabel,
				arrayIndex: index,
				sortOrder: orderMatch?.order ?? order ?? index,
			} );
		}
	);

	resolved.sort( ( a, b ) =>
		a.sortOrder !== b.sortOrder
			? a.sortOrder - b.sortOrder
			: a.arrayIndex - b.arrayIndex
	);

	const formatAttachment = ( label: string, index: number ) =>
		sprintf(
			/* translators: %1$s: label, %2$s: attachment letter */
			__( '• %1$s (Attachment %2$s)', 'woocommerce' ),
			label,
			String.fromCharCode( 65 + index )
		);

	if ( ! resolved.length ) {
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

	return resolved
		.map( ( { displayLabel }, index ) =>
			formatAttachment( displayLabel, index )
		)
		.join( '\n' );
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
			__( '<Transaction Date>', 'woocommerce' ),
			true
		),
		customerName:
			charge?.billing_details?.name ||
			__( '<Customer Name>', 'woocommerce' ),
		product: getProduct( dispute, evidence ),
		orderDate: formatDate(
			charge?.created,
			__( '<Order Date>', 'woocommerce' ),
			true
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
