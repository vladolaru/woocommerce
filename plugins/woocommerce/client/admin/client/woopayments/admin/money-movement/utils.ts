/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatWooPaymentsAmount } from '../overview/utils';
import { getPaymentMethodDefinition } from '../../settings/payment-method-definitions';
import type { WooPaymentsTransaction } from './types';

export const getErrorMessage = ( error: unknown, fallback: string ): string => {
	if ( error instanceof Error && error.message ) {
		return error.message;
	}

	if (
		error &&
		typeof error === 'object' &&
		'message' in error &&
		typeof error.message === 'string'
	) {
		return error.message;
	}

	return fallback;
};

export const buildPathWithQuery = (
	path: string,
	query: Record< string, unknown > = {}
) => {
	const params = new URLSearchParams();

	Object.entries( query ).forEach( ( [ key, value ] ) => {
		if ( value === undefined || value === null || value === '' ) {
			return;
		}

		if ( Array.isArray( value ) ) {
			value.forEach( ( item ) => params.append( key, String( item ) ) );
			return;
		}

		params.append( key, String( value ) );
	} );

	const queryString = params.toString();

	return queryString ? `${ path }?${ queryString }` : path;
};

export const getResourceId = ( item: {
	id?: string;
	transaction_id?: string;
	dispute_id?: string;
	charge_id?: string;
} ) =>
	item.id || item.transaction_id || item.dispute_id || item.charge_id || '';

export const getDisputeId = ( item: { id?: string; dispute_id?: string } ) =>
	item.dispute_id || item.id || '';

export const getChargeId = ( item: {
	id?: string;
	charge_id?: string;
	charge?: string | { id?: string };
} ) => {
	if ( typeof item.charge === 'string' ) {
		return item.charge;
	}

	return item.charge_id || item.charge?.id || item.id || '';
};

export const getTransactionDetailsRoute = ( item: {
	id?: string;
	transaction_id?: string;
	charge_id?: string;
	payment_intent_id?: string;
	payment_intent?: string;
	metadata?: Record< string, unknown >;
	type?: string;
	charge?:
		| string
		| {
				id?: string;
				payment_intent?: string;
				balance_transaction?: string | { id?: string };
		  };
} ) => {
	const charge = typeof item.charge === 'object' ? item.charge : undefined;
	const primaryId =
		item.payment_intent_id ||
		item.payment_intent ||
		charge?.payment_intent ||
		item.charge_id ||
		( typeof item.charge === 'string' ? item.charge : charge?.id ) ||
		item.id ||
		item.transaction_id ||
		'';
	const balanceTransaction = charge?.balance_transaction;
	const balanceTransactionId =
		typeof balanceTransaction === 'string'
			? balanceTransaction
			: balanceTransaction?.id;
	const transactionId =
		item.transaction_id ||
		balanceTransactionId ||
		( item.id?.startsWith( 'txn_' ) ? item.id : '' );
	const metadataChargeType = item.metadata?.charge_type;
	const transactionType =
		typeof metadataChargeType === 'string' ? metadataChargeType : item.type;

	return buildPathWithQuery( '/woopayments/transactions/details', {
		id: primaryId,
		transaction_id:
			transactionId && transactionId !== primaryId
				? transactionId
				: undefined,
		transaction_type: transactionType,
	} );
};

export const formatDate = ( value?: string | number ) => {
	if ( ! value ) {
		return '-';
	}

	const timestamp =
		typeof value === 'number' && value < 10000000000 ? value * 1000 : value;
	const date = new Date( timestamp );

	if ( Number.isNaN( date.getTime() ) ) {
		return '-';
	}

	return date.toLocaleDateString( undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
	} );
};

export const formatDateTime = ( value?: string | number ) => {
	if ( ! value ) {
		return '-';
	}

	const timestamp =
		typeof value === 'number' && value < 10000000000 ? value * 1000 : value;
	const date = new Date( timestamp );

	if ( Number.isNaN( date.getTime() ) ) {
		return '-';
	}

	return date.toLocaleString( undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
	} );
};

export const formatAmount = ( amount?: number, currency?: string ) =>
	typeof amount === 'number'
		? formatWooPaymentsAmount( amount, currency )
		: '-';

export const formatLabel = ( value?: string ) => {
	if ( ! value ) {
		return '-';
	}

	return value
		.replace( /_/g, ' ' )
		.replace( /^\w/, ( match ) => match.toUpperCase() );
};

const TRANSACTION_TYPE_LABELS: Record< string, string > = {
	charge: __( 'Charge', 'woocommerce' ),
	payment: __( 'Payment', 'woocommerce' ),
	payment_failure_refund: __( 'Payment failure refund', 'woocommerce' ),
	payment_refund: __( 'Payment refund', 'woocommerce' ),
	refund: __( 'Refund', 'woocommerce' ),
	refund_failure: __( 'Refund failure', 'woocommerce' ),
	dispute: __( 'Dispute', 'woocommerce' ),
	dispute_reversal: __( 'Dispute reversal', 'woocommerce' ),
	card_reader_fee: __( 'Reader fee', 'woocommerce' ),
	financing_payout: __( 'Loan disbursement', 'woocommerce' ),
	financing_paydown: __( 'Loan repayment', 'woocommerce' ),
	fee_refund: __( 'Fee refund', 'woocommerce' ),
	network_costs: __( 'Network costs', 'woocommerce' ),
};

const TRANSACTION_SOURCE_LABELS: Record< string, string > = {
	ach_credit_transfer: __( 'ACH credit transfer', 'woocommerce' ),
	ach_debit: __( 'ACH debit', 'woocommerce' ),
	acss_debit: __( 'ACSS debit', 'woocommerce' ),
	amazon_pay: __( 'Amazon Pay', 'woocommerce' ),
	amex: __( 'American Express', 'woocommerce' ),
	card: __( 'Card payment', 'woocommerce' ),
	card_present: __( 'In-person card payment', 'woocommerce' ),
	cartes_bancaires: __( 'Cartes Bancaires', 'woocommerce' ),
	diners: __( 'Diners Club', 'woocommerce' ),
	discover: __( 'Discover', 'woocommerce' ),
	eftpos_au: __( 'eftpos', 'woocommerce' ),
	giropay: __( 'Giropay', 'woocommerce' ),
	jcb: __( 'JCB', 'woocommerce' ),
	mastercard: __( 'Mastercard', 'woocommerce' ),
	p24: __( 'Przelewy24 (P24)', 'woocommerce' ),
	stripe_account: __( 'Stripe account', 'woocommerce' ),
	unionpay: __( 'UnionPay', 'woocommerce' ),
	visa: __( 'Visa', 'woocommerce' ),
};

const P24_BANK_LABELS: Record< string, string > = {
	alior_bank: 'Alior Bank',
	bank_millennium: 'Bank Millenium',
	bank_nowy_bfg_sa: 'Bank Nowy BFG S.A.',
	bank_pekao_sa: 'Bank PEKAO S.A',
	banki_spbdzielcze: 'Banki SpBdzielcze',
	blik: 'Blik via redirect',
	bnp_paribas: 'BNP Paribas',
	boz: 'BOZ',
	citi_handlowy: 'CitiHandlowy',
	credit_agricole: 'Credit Agricole',
	envelobank: 'EnveloBank',
	etransfer_pocztowy24: 'e-Transfer Poctowy24',
	getin_bank: 'Getin Bank',
	ideabank: 'IdeaBank',
	ing: 'ING',
	inteligo: 'inteligo',
	mbank_mtransfer: 'mBank-mtransfer',
	nest_przelew: 'Nest Przelew',
	noble_pay: 'Noble Pay',
	pbac_z_ipko: 'PBac z iPKO (PKO+BP)',
	plus_bank: 'Plus Bank',
	santander_przelew24: 'Santander-przelew24',
	tmobile_usbugi_bankowe: 'T-Mobile Usbugi Bankowe',
	toyota_bank: 'Toyota Bank',
	volkswagen_bank: 'Volkswagen Bank',
};

const MASKED_TRANSACTION_SOURCES = new Set( [
	'ach_credit_transfer',
	'ach_debit',
	'acss_debit',
	'amex',
	'au_becs_debit',
	'card',
	'card_present',
	'cartes_bancaires',
	'diners',
	'discover',
	'eftpos_au',
	'jcb',
	'mastercard',
	'sepa_debit',
	'unionpay',
	'visa',
] );

const TRANSACTION_TYPES_WITHOUT_PAYMENT_METHOD = new Set( [
	'card_reader_fee',
	'financing_payout',
	'financing_paydown',
	'network_costs',
] );

export const getTransactionListType = (
	transaction: WooPaymentsTransaction
) => {
	const metadataType = transaction.metadata?.charge_type;

	return typeof metadataType === 'string' && metadataType
		? metadataType
		: transaction.type || '';
};

export const getTransactionTypeLabel = ( value?: string ) =>
	value ? TRANSACTION_TYPE_LABELS[ value ] || formatLabel( value ) : '-';

export const getTransactionListAmount = (
	transaction: WooPaymentsTransaction
) =>
	getTransactionListType( transaction ) === 'card_reader_fee'
		? 0
		: transaction.amount;

export const getTransactionListFees = (
	transaction: WooPaymentsTransaction
) => {
	if ( getTransactionListType( transaction ) === 'card_reader_fee' ) {
		return transaction.amount;
	}

	return typeof transaction.fees === 'number'
		? transaction.fees * -1
		: undefined;
};

export const getTransactionListPaymentMethod = (
	transaction: WooPaymentsTransaction
) => {
	if (
		TRANSACTION_TYPES_WITHOUT_PAYMENT_METHOD.has(
			getTransactionListType( transaction )
		)
	) {
		return '-';
	}

	const source = transaction.source;

	if ( ! source ) {
		return '-';
	}

	const sourceLabel =
		TRANSACTION_SOURCE_LABELS[ source ] ||
		getPaymentMethodDefinition( source )?.label ||
		formatLabel( source );
	const identifier = transaction.source_identifier;

	if ( ! identifier ) {
		return sourceLabel;
	}

	if ( source === 'p24' ) {
		const bankLabel = P24_BANK_LABELS[ identifier ];

		return bankLabel
			? sprintf(
					/* translators: 1: payment method, 2: bank name. */
					__( '%1$s %2$s', 'woocommerce' ),
					sourceLabel,
					bankLabel
			  )
			: sourceLabel;
	}

	if ( MASKED_TRANSACTION_SOURCES.has( source ) ) {
		return sprintf(
			/* translators: 1: payment method, 2: account or card last four digits. */
			__( '%1$s •••• %2$s', 'woocommerce' ),
			sourceLabel,
			identifier
		);
	}

	return sprintf(
		/* translators: 1: payment method, 2: payment method identifier. */
		__( '%1$s %2$s', 'woocommerce' ),
		sourceLabel,
		identifier
	);
};

export const formatDisputeReasonLabel = ( reason?: string ) => {
	switch ( reason ) {
		case 'bank_cannot_process':
			return __( 'Bank cannot process', 'woocommerce' );
		case 'check_returned':
			return __( 'Check returned', 'woocommerce' );
		case 'credit_not_processed':
			return __( 'Credit not processed', 'woocommerce' );
		case 'customer_initiated':
			return __( 'Customer initiated', 'woocommerce' );
		case 'debit_not_authorized':
			return __( 'Debit not authorized', 'woocommerce' );
		case 'duplicate':
			return __( 'Duplicate', 'woocommerce' );
		case 'fraudulent':
			return __( 'Transaction unauthorized', 'woocommerce' );
		case 'incorrect_account_details':
			return __( 'Incorrect account details', 'woocommerce' );
		case 'insufficient_funds':
			return __( 'Insufficient funds', 'woocommerce' );
		case 'product_not_received':
			return __( 'Product not received', 'woocommerce' );
		case 'product_unacceptable':
			return __( 'Product unacceptable', 'woocommerce' );
		case 'subscription_canceled':
			return __( 'Subscription canceled', 'woocommerce' );
		case 'unrecognized':
			return __( 'Unrecognized', 'woocommerce' );
		case 'noncompliant':
			return __( 'Non-compliant', 'woocommerce' );
		default:
			return __( 'General', 'woocommerce' );
	}
};

export const getChargeChannelLabel = (
	paymentMethodType?: string,
	metadata: Record< string, unknown > = {},
	salesChannel?: string
) => {
	const explicitChannel =
		typeof salesChannel === 'string' ? salesChannel : undefined;
	const ippChannel =
		typeof metadata.ipp_channel === 'string'
			? metadata.ipp_channel
			: undefined;
	const channel = explicitChannel || ippChannel;

	if (
		paymentMethodType === 'card_present' ||
		paymentMethodType === 'interac_present' ||
		channel === 'mobile_pos' ||
		channel === 'in_person' ||
		channel === 'pos' ||
		channel === 'terminal'
	) {
		return channel === 'mobile_pos'
			? __( 'In-person (POS)', 'woocommerce' )
			: __( 'In-person', 'woocommerce' );
	}

	if ( channel && channel !== 'online' && channel !== 'online_store' ) {
		return formatLabel( channel );
	}

	return __( 'Online store', 'woocommerce' );
};
