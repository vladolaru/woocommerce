/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

// Client 11.1.0 `disputes/new-evidence/types.ts:139-196`.
export type RecommendationOutcome = 'could_help' | 'keep_doing';
export type RecommendationUrgency = 'critical' | 'positive' | 'tip';

type FieldCountPredicate = {
	keys: string[];
	min?: number;
	max?: number;
};

export type Recommendation = {
	id: string;
	title: string;
	body: string;
	urgency: RecommendationUrgency;
	when: {
		outcome: RecommendationOutcome;
		reasonIn: string[];
		productTypeIn?: string[];
		requireProvided?: FieldCountPredicate;
		requireMissing?: FieldCountPredicate;
	};
	lift?: number;
	suppressOthers?: boolean;
	retired?: boolean;
};

/*
 * Client 11.1.0 `disputes/new-evidence/recommendation-catalog.ts`, copied as is: the merchant-facing
 * recommendations of the dispute outcome view. Ids are Tracks join keys, so they never change.
 */

// Every evidence key the wizard collects from the merchant. c15/c12 gate on
// NON_COVER_LETTER_EVIDENCE_KEYS (derived below); keep this in sync with the
// wizard or they misfire.
//
// Excludes always-present fields that would otherwise stop c15 from firing:
// auto-populated ones (customer_purchase_ip, customer_name,
// customer_email_address, billing_address) and product_description (wizard
// pre-fills it). Mirrors constants/high-impact-fields.ts; guarded in
// recommendation-catalog.test.ts.
//
// Also omits fields the wizard does not collect yet: service_date and
// refund_refusal_explanation (clusters 8 and 4 are deferred), and
// duplicate_charge_explanation (cluster 7 gates on the documentation instead).
const WIZARD_SUBMITTABLE_EVIDENCE_KEYS = [
	'customer_communication',
	'receipt',
	'shipping_documentation',
	'shipping_tracking_number',
	'shipping_carrier',
	'shipping_date',
	'shipping_address',
	'service_documentation',
	'access_activity_log',
	'duplicate_charge_documentation',
	'refund_policy',
	'cancellation_policy',
	'cancellation_rebuttal',
	'customer_signature',
	'uncategorized_file',
	'uncategorized_text',
];

// Every wizard key except the auto-generated cover letter. c12 uses it to
// coach merchants who submitted some evidence; c15 uses it so the default
// cover letter doesn't mask a no-evidence submission.
const NON_COVER_LETTER_EVIDENCE_KEYS = WIZARD_SUBMITTABLE_EVIDENCE_KEYS.filter(
	( key ) => key !== 'uncategorized_text'
);

export const RECOMMENDATIONS_CATALOG: Recommendation[] = [
	// ============ CLUSTER 1: shipping evidence ============
	{
		id: 'c1-shipping-evidence-strong',
		urgency: 'positive',
		title: __( 'Strong shipping evidence', 'woocommerce' ),
		body: __(
			'Your tracking number and carrier info helped demonstrate delivery.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [ 'product_not_received' ],
			productTypeIn: [ 'physical_product' ],
			requireProvided: {
				keys: [ 'shipping_tracking_number', 'shipping_carrier' ],
				min: 2,
			},
		},
	},
	{
		id: 'c1-shipping-tracking-add',
		urgency: 'critical',
		title: __( 'Add shipping tracking for every order', 'woocommerce' ),
		body: __(
			'Shipping tracking that shows delivery is among the strongest evidence for product-not-received disputes.',
			'woocommerce'
		),
		when: {
			outcome: 'could_help',
			reasonIn: [ 'product_not_received' ],
			productTypeIn: [ 'physical_product' ],
			requireMissing: { keys: [ 'shipping_tracking_number' ] },
		},
	},
	{
		id: 'c1-shipping-evidence-strengthen',
		urgency: 'tip',
		title: __( 'Strengthen your shipping evidence', 'woocommerce' ),
		body: __(
			'Adding carrier info or a delivery date alongside tracking gives a fuller picture for physical goods disputes.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [ 'product_not_received' ],
			productTypeIn: [ 'physical_product' ],
			requireProvided: { keys: [ 'shipping_tracking_number' ] },
			requireMissing: {
				keys: [
					'shipping_carrier',
					'shipping_date',
					'shipping_address',
					'shipping_documentation',
				],
			},
		},
	},

	// ============ CLUSTER 2: receipt ============
	{
		id: 'c2-receipt-provided',
		urgency: 'positive',
		title: __( 'Clear proof of purchase', 'woocommerce' ),
		body: __( 'A receipt added support to your defense.', 'woocommerce' ),
		when: {
			outcome: 'keep_doing',
			reasonIn: [
				'general',
				'product_not_received',
				'duplicate',
				'credit_not_processed',
				'product_unacceptable',
			],
			requireProvided: { keys: [ 'receipt' ] },
		},
	},
	{
		id: 'c2-receipt-include',
		urgency: 'critical',
		title: __( 'Include the order receipt', 'woocommerce' ),
		body: __(
			'A clear receipt is one of the more reliable pieces of evidence across dispute types.',
			'woocommerce'
		),
		when: {
			outcome: 'could_help',
			reasonIn: [
				'general',
				'product_not_received',
				'duplicate',
				'credit_not_processed',
				'product_unacceptable',
			],
			requireMissing: { keys: [ 'receipt' ] },
		},
	},
	{
		id: 'c2-receipt-include-tip',
		urgency: 'tip',
		title: __( 'Include a receipt with your evidence', 'woocommerce' ),
		body: __(
			'Receipts tend to strengthen evidence packages across most dispute types, even when you win without one.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [
				'general',
				'product_not_received',
				'duplicate',
				'credit_not_processed',
				'product_unacceptable',
			],
			requireMissing: { keys: [ 'receipt' ] },
		},
	},

	// ============ CLUSTER 3: customer communication ============
	{
		id: 'c3-communication-provided',
		urgency: 'positive',
		title: __( 'Customer communication on file', 'woocommerce' ),
		body: __(
			'Your correspondence with the customer added helpful context.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [
				'product_not_received',
				'fraudulent',
				'product_unacceptable',
				'credit_not_processed',
				'general',
			],
			requireProvided: { keys: [ 'customer_communication' ] },
		},
	},
	{
		id: 'c3-communication-include',
		urgency: 'critical',
		title: __( 'Include customer correspondence', 'woocommerce' ),
		body: __(
			'Emails or messages with the customer can add helpful context to your defense.',
			'woocommerce'
		),
		when: {
			outcome: 'could_help',
			reasonIn: [
				'product_not_received',
				'fraudulent',
				'product_unacceptable',
				'credit_not_processed',
				'general',
			],
			requireMissing: { keys: [ 'customer_communication' ] },
		},
	},
	{
		id: 'c3-communication-consider',
		urgency: 'tip',
		title: __(
			'Consider attaching customer correspondence',
			'woocommerce'
		),
		body: __(
			'Relevant correspondence tends to help across dispute types where the conversation supports your position.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [
				'product_not_received',
				'fraudulent',
				'product_unacceptable',
				'credit_not_processed',
				'general',
			],
			requireMissing: { keys: [ 'customer_communication' ] },
		},
	},

	// ============ CLUSTER 4: refund refusal explanation ============
	// Deferred: the wizard has no `refund_refusal_explanation` input, so a
	// positive could never fire and the missing-field coaching would always
	// dead-end. Restore the provided/critical/tip entries once the wizard
	// collects the field; refund_policy (cluster 5) covers these disputes
	// in the meantime.

	// ============ CLUSTER 5: refund policy ============
	{
		id: 'c5-refund-policy-provided',
		urgency: 'positive',
		title: __( 'Clear refund policy', 'woocommerce' ),
		body: __(
			'Your refund policy gave context for how returns work in your store.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [
				'product_unacceptable',
				'credit_not_processed',
				'duplicate',
				'subscription_canceled',
			],
			requireProvided: { keys: [ 'refund_policy' ] },
		},
	},
	{
		id: 'c5-refund-policy-publish-won',
		urgency: 'tip',
		title: __( 'Publish a clear refund policy', 'woocommerce' ),
		body: __(
			'A published refund policy gives customers context for how returns work in your store, and ties together your other refund-related evidence.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [
				'product_unacceptable',
				'credit_not_processed',
				'duplicate',
				'subscription_canceled',
			],
			requireMissing: { keys: [ 'refund_policy' ] },
		},
	},
	{
		id: 'c5-refund-policy-publish-lost',
		urgency: 'tip',
		title: __( 'Publish a clear refund policy', 'woocommerce' ),
		body: __(
			'A published refund policy gives customers context for how returns work in your store, and ties together your other refund-related evidence.',
			'woocommerce'
		),
		when: {
			outcome: 'could_help',
			reasonIn: [
				'product_unacceptable',
				'credit_not_processed',
				'duplicate',
				'subscription_canceled',
			],
			requireMissing: { keys: [ 'refund_policy' ] },
		},
	},

	// ============ CLUSTER 6: cancellation policy ============
	// `min: 2` entries need both cancellation_policy and cancellation_rebuttal
	// in the same state; the "exactly-one" tips use `min: 1, max: 1`.
	{
		id: 'c6-cancellation-provided',
		urgency: 'positive',
		title: __( 'Cancellation policy on record', 'woocommerce' ),
		body: __(
			'Your cancellation policy and supporting records helped clarify the subscription terms.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [ 'subscription_canceled' ],
			requireProvided: {
				keys: [ 'cancellation_policy', 'cancellation_rebuttal' ],
				min: 2,
			},
		},
	},
	{
		id: 'c6-cancellation-document',
		urgency: 'critical',
		title: __( 'Document your cancellation terms', 'woocommerce' ),
		body: __(
			'Cancellation policies, terms shown at checkout, and cancellation records are central to subscription disputes.',
			'woocommerce'
		),
		when: {
			outcome: 'could_help',
			reasonIn: [ 'subscription_canceled' ],
			requireMissing: {
				keys: [ 'cancellation_policy', 'cancellation_rebuttal' ],
				min: 2,
			},
		},
	},
	{
		id: 'c6-cancellation-add-exactly-one',
		urgency: 'tip',
		title: __( 'Add cancellation documentation', 'woocommerce' ),
		body: __(
			'Documented cancellation terms and supporting records help defend subscription disputes.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [ 'subscription_canceled' ],
			requireProvided: {
				keys: [ 'cancellation_policy', 'cancellation_rebuttal' ],
				min: 1,
				max: 1,
			},
		},
	},
	{
		id: 'c6-cancellation-add-exactly-one-lost',
		urgency: 'tip',
		title: __( 'Add cancellation documentation', 'woocommerce' ),
		body: __(
			'Documented cancellation terms and supporting records help defend subscription disputes.',
			'woocommerce'
		),
		when: {
			outcome: 'could_help',
			reasonIn: [ 'subscription_canceled' ],
			requireProvided: {
				keys: [ 'cancellation_policy', 'cancellation_rebuttal' ],
				min: 1,
				max: 1,
			},
		},
	},
	{
		id: 'c6-cancellation-add-none-on-won',
		urgency: 'tip',
		title: __( 'Add cancellation documentation', 'woocommerce' ),
		body: __(
			'Documented cancellation terms and supporting records help defend subscription disputes.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [ 'subscription_canceled' ],
			requireMissing: {
				keys: [ 'cancellation_policy', 'cancellation_rebuttal' ],
				min: 2,
			},
		},
	},

	// ============ CLUSTER 7: duplicate charge ============
	// Gated on `duplicate_charge_documentation` only: the wizard does not
	// collect `duplicate_charge_explanation`, so the copy coaches on the
	// documentation the merchant can actually attach.
	{
		id: 'c7-duplicate-charge-explained',
		urgency: 'positive',
		title: __( 'Two charges, clearly documented', 'woocommerce' ),
		body: __(
			'Documentation distinguishing the two charges helped make your case.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [ 'duplicate' ],
			requireProvided: { keys: [ 'duplicate_charge_documentation' ] },
		},
	},
	{
		id: 'c7-duplicate-charge-explain',
		urgency: 'critical',
		title: __( 'Document the duplicate charge', 'woocommerce' ),
		body: __(
			'Documentation distinguishing the two charges is central evidence for duplicate disputes.',
			'woocommerce'
		),
		when: {
			outcome: 'could_help',
			reasonIn: [ 'duplicate' ],
			requireMissing: { keys: [ 'duplicate_charge_documentation' ] },
		},
	},

	// ============ CLUSTER 8: service date (non-physical only) ============
	// Deferred: the wizard has no `service_date` input. It collects
	// `shipping_date` for physical fraudulent disputes (cluster 8b), but
	// non-physical fraud has no fulfilment-date field, so a positive could
	// never fire and the missing-field coaching would always dead-end.
	// Restore once the wizard collects `service_date`. Mirrors
	// DISPUTE_HIGH_IMPACT_FIELDS.

	// ============ CLUSTER 8b: shipping date (fraudulent + physical only) ============
	// Parallel to Cluster 8 but keyed on `shipping_date` (physical fraudulent).
	// No Critical: per RiskOps, the shipping date ties the order to the
	// cardholder's address but doesn't prove who made the purchase.
	{
		id: 'c8b-shipping-date-provided',
		urgency: 'positive',
		title: __( 'Shipping date on record', 'woocommerce' ),
		body: __(
			"Documenting the shipping date tied this order to a verifiable event at the cardholder's address.",
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [ 'fraudulent' ],
			productTypeIn: [ 'physical_product' ],
			requireProvided: { keys: [ 'shipping_date' ] },
		},
	},
	{
		id: 'c8b-shipping-date-document',
		urgency: 'tip',
		title: __( 'Document the shipping date', 'woocommerce' ),
		body: __(
			"Including the shipping date ties an order to a verifiable event at the cardholder's address.",
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [ 'fraudulent' ],
			productTypeIn: [ 'physical_product' ],
			requireMissing: { keys: [ 'shipping_date' ] },
		},
	},
	{
		id: 'c8b-shipping-date-document-lost',
		urgency: 'tip',
		title: __( 'Document the shipping date', 'woocommerce' ),
		body: __(
			"Including the shipping date ties an order to a verifiable event at the cardholder's address.",
			'woocommerce'
		),
		when: {
			outcome: 'could_help',
			reasonIn: [ 'fraudulent' ],
			productTypeIn: [ 'physical_product' ],
			requireMissing: { keys: [ 'shipping_date' ] },
		},
	},

	// ============ CLUSTER 9: service documentation ============
	{
		id: 'c9-service-documentation-provided',
		urgency: 'positive',
		title: __( 'Proof of service delivered', 'woocommerce' ),
		body: __(
			'Service documentation helped establish that the service was delivered.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [
				'product_not_received',
				'product_unacceptable',
				'fraudulent',
			],
			productTypeIn: [
				'offline_service',
				'event',
				'booking_reservation',
			],
			requireProvided: { keys: [ 'service_documentation' ] },
		},
	},
	{
		id: 'c9-service-documentation-attach-won',
		urgency: 'tip',
		title: __( 'Attach proof the service was delivered', 'woocommerce' ),
		body: __(
			'Records showing the event, reservation, or service was completed help defend service-based disputes.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [
				'product_not_received',
				'product_unacceptable',
				'fraudulent',
			],
			productTypeIn: [
				'offline_service',
				'event',
				'booking_reservation',
			],
			requireMissing: { keys: [ 'service_documentation' ] },
		},
	},
	{
		id: 'c9-service-documentation-attach-lost',
		urgency: 'tip',
		title: __( 'Attach proof the service was delivered', 'woocommerce' ),
		body: __(
			'Records showing the event, reservation, or service was completed help defend service-based disputes.',
			'woocommerce'
		),
		when: {
			outcome: 'could_help',
			reasonIn: [
				'product_not_received',
				'product_unacceptable',
				'fraudulent',
			],
			productTypeIn: [
				'offline_service',
				'event',
				'booking_reservation',
			],
			requireMissing: { keys: [ 'service_documentation' ] },
		},
	},

	// ============ CLUSTER 10: access activity log ============
	{
		id: 'c10-access-log-provided',
		urgency: 'positive',
		title: __( 'Usage records on file', 'woocommerce' ),
		body: __(
			'Your access logs helped show the customer used the product.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [
				'product_unacceptable',
				'fraudulent',
				'product_not_received',
			],
			productTypeIn: [ 'digital_product_or_service' ],
			requireProvided: { keys: [ 'access_activity_log' ] },
		},
	},
	{
		id: 'c10-access-log-include',
		urgency: 'critical',
		title: __(
			'Include usage records for digital products',
			'woocommerce'
		),
		body: __(
			'Access logs showing the customer used the product are central evidence for digital product-quality disputes.',
			'woocommerce'
		),
		when: {
			outcome: 'could_help',
			reasonIn: [ 'product_unacceptable' ],
			productTypeIn: [ 'digital_product_or_service' ],
			requireMissing: { keys: [ 'access_activity_log' ] },
		},
	},
	{
		id: 'c10-access-log-add',
		urgency: 'tip',
		title: __( 'Add access logs to your evidence', 'woocommerce' ),
		body: __(
			'Usage records help defend digital product disputes by showing the customer accessed the product.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [
				'product_unacceptable',
				'fraudulent',
				'product_not_received',
			],
			productTypeIn: [ 'digital_product_or_service' ],
			requireMissing: { keys: [ 'access_activity_log' ] },
		},
	},

	// ============ CLUSTER 11a: customer signature (PNR + Physical) ============
	{
		id: 'c11a-signature-provided',
		urgency: 'positive',
		title: __( 'Signed delivery proof', 'woocommerce' ),
		body: __(
			'Signed delivery records helped confirm the customer received the product.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [ 'product_not_received' ],
			productTypeIn: [ 'physical_product' ],
			requireProvided: { keys: [ 'customer_signature' ] },
		},
	},
	{
		id: 'c11a-signature-include',
		urgency: 'critical',
		title: __( 'Include signed delivery proof', 'woocommerce' ),
		body: __(
			'Signed proof of delivery confirms the customer received the product, which is central to product-not-received disputes.',
			'woocommerce'
		),
		when: {
			outcome: 'could_help',
			reasonIn: [ 'product_not_received' ],
			productTypeIn: [ 'physical_product' ],
			requireMissing: { keys: [ 'customer_signature' ] },
		},
	},
	{
		id: 'c11a-signature-add',
		urgency: 'tip',
		title: __( 'Add signed delivery proof', 'woocommerce' ),
		body: __(
			'Where signed delivery records are available, they tend to strengthen physical goods dispute evidence.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [ 'product_not_received' ],
			productTypeIn: [ 'physical_product' ],
			requireMissing: { keys: [ 'customer_signature' ] },
		},
	},

	// ============ CLUSTER 11b: customer signature (CNP + Physical) ============
	{
		id: 'c11b-signature-provided',
		urgency: 'positive',
		title: __( 'Signed delivery proof (refund case)', 'woocommerce' ),
		body: __(
			'Signed delivery proof from the original order showed the customer received the product before disputing the refund.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [ 'credit_not_processed' ],
			productTypeIn: [ 'physical_product' ],
			requireProvided: { keys: [ 'customer_signature' ] },
		},
	},
	{
		id: 'c11b-signature-include',
		urgency: 'critical',
		title: __(
			'Include signed delivery proof from the original order',
			'woocommerce'
		),
		body: __(
			'For refund disputes on physical goods, signed delivery proof from the original order establishes the customer received the product before the dispute.',
			'woocommerce'
		),
		when: {
			outcome: 'could_help',
			reasonIn: [ 'credit_not_processed' ],
			productTypeIn: [ 'physical_product' ],
			requireMissing: { keys: [ 'customer_signature' ] },
		},
	},
	{
		id: 'c11b-signature-consider',
		urgency: 'tip',
		title: __( 'Consider attaching signed delivery proof', 'woocommerce' ),
		body: __(
			'Signed delivery records from the original order can support refund disputes on physical goods.',
			'woocommerce'
		),
		when: {
			outcome: 'keep_doing',
			reasonIn: [ 'credit_not_processed' ],
			productTypeIn: [ 'physical_product' ],
			requireMissing: { keys: [ 'customer_signature' ] },
		},
	},

	// ============ CLUSTER 12: cover letter (Tip only) ============
	// Fires when the merchant cleared the auto-generated cover letter
	// (uncategorized_text empty).
	{
		id: 'c12-cover-letter-include',
		urgency: 'tip',
		title: __( 'Include a cover letter with your evidence', 'woocommerce' ),
		body: __(
			'The cover letter is how you introduce your case and tie your evidence together.',
			'woocommerce'
		),
		when: {
			outcome: 'could_help',
			reasonIn: [
				'product_not_received',
				'fraudulent',
				'product_unacceptable',
				'credit_not_processed',
				'duplicate',
				'subscription_canceled',
				'general',
			],
			// Guard so c12 fires only when SOME evidence exists; else it would
			// render next to c15's "submit evidence" (suppression skips tips).
			requireProvided: { keys: NON_COVER_LETTER_EVIDENCE_KEYS },
			requireMissing: { keys: [ 'uncategorized_text' ] },
		},
	},

	// ============ CLUSTER 13: response-time timing ============
	// Deferred until `submitted_at` reaches the client: a positive (quick
	// response) and a tip (slow) join once the predicate language gains timing.

	// ============ CLUSTER 14: prior good history (Tip only) ============
	{
		id: 'c14-prior-history-mention',
		urgency: 'tip',
		title: __(
			'Mention prior good history with the customer',
			'woocommerce'
		),
		body: __(
			"Where possible, mention in your cover letter the customer's prior successful orders, and attach proof from their order history.",
			'woocommerce'
		),
		when: {
			outcome: 'could_help',
			reasonIn: [ 'fraudulent' ],
		},
	},

	// ============ CLUSTER 15: no evidence (Critical, suppresses others) ============
	{
		id: 'c15-no-evidence-submit',
		urgency: 'critical',
		title: __(
			'Submit evidence with your dispute response',
			'woocommerce'
		),
		body: __(
			"Without evidence to weigh against the customer's claim, disputes generally default in the customer's favor.",
			'woocommerce'
		),
		when: {
			outcome: 'could_help',
			reasonIn: [
				'product_not_received',
				'fraudulent',
				'product_unacceptable',
				'credit_not_processed',
				'duplicate',
				'subscription_canceled',
				'general',
				'bank_cannot_process',
				'check_returned',
				'customer_initiated',
				'debit_not_authorized',
				'incorrect_account_details',
				'insufficient_funds',
				'noncompliant',
				'unrecognized',
			],
			// Fires when every non-cover-letter wizard key is missing. The
			// cover letter is auto-generated and submitted by default
			// (index.tsx), so counting it would stop c15 firing for a merchant
			// who sent no real evidence. suppressOthers leaves one message.
			requireProvided: {
				keys: NON_COVER_LETTER_EVIDENCE_KEYS,
				max: 0,
			},
		},
		suppressOthers: true,
	},
];
