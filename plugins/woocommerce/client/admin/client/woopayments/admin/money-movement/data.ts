/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { encodePathSegment } from '../../path-segment';
import type {
	WooPaymentsAuthorization,
	WooPaymentsAuthorizationActionResponse,
	WooPaymentsAuthorizationQuery,
	WooPaymentsAuthorizationsSummary,
	WooPaymentsDispute,
	WooPaymentsDisputeFile,
	WooPaymentsFraudOutcomeQuery,
	WooPaymentsListResponse,
	WooPaymentsMoneyMovementQuery,
	WooPaymentsCharge,
	WooPaymentsPaymentIntent,
	WooPaymentsRefundRequest,
	WooPaymentsRefundResponse,
	WooPaymentsReaderChargeSummaryResponse,
	WooPaymentsTransaction,
	WooPaymentsTimelineResponse,
} from './types';
import { buildPathWithQuery } from './utils';
import { getWooPaymentsExportRecipient } from './export';
import {
	buildSettledTransactionsApiPath,
	serializeWooPaymentsAuthorizationsQuery,
} from './query';

const PAYMENTS_PATH = '/wc/v3/payments';

const buildAuthorizationsPath = (
	path: string,
	query: WooPaymentsAuthorizationQuery = {}
) => {
	const queryString = serializeWooPaymentsAuthorizationsQuery( query );

	return queryString ? `${ path }?${ queryString }` : path;
};

export const getWooPaymentsTransactions = (
	query: WooPaymentsMoneyMovementQuery = {}
): Promise< WooPaymentsListResponse< WooPaymentsTransaction > > =>
	apiFetch< WooPaymentsListResponse< WooPaymentsTransaction > >( {
		path: buildSettledTransactionsApiPath(
			`${ PAYMENTS_PATH }/transactions`,
			query
		),
		method: 'GET',
	} );

export const getWooPaymentsTransactionsSummary = (
	query: WooPaymentsMoneyMovementQuery = {}
): Promise< Record< string, unknown > > =>
	apiFetch< Record< string, unknown > >( {
		path: buildSettledTransactionsApiPath(
			`${ PAYMENTS_PATH }/transactions/summary`,
			query
		),
		method: 'GET',
	} );

export const getWooPaymentsTransaction = async (
	transactionId: string
): Promise< WooPaymentsTransaction > =>
	apiFetch< WooPaymentsTransaction >( {
		path: `${ PAYMENTS_PATH }/transactions/${ encodePathSegment(
			transactionId
		) }`,
		method: 'GET',
	} );

export const getWooPaymentsCharge = async (
	chargeId: string
): Promise< WooPaymentsCharge > =>
	apiFetch< WooPaymentsCharge >( {
		path: `${ PAYMENTS_PATH }/charges/${ encodePathSegment( chargeId ) }`,
		method: 'GET',
	} );

export const getWooPaymentsPaymentIntent = async (
	paymentIntentId: string
): Promise< WooPaymentsPaymentIntent > =>
	apiFetch< WooPaymentsPaymentIntent >( {
		path: `${ PAYMENTS_PATH }/payment_intents/${ encodePathSegment(
			paymentIntentId
		) }`,
		method: 'GET',
	} );

export const getWooPaymentsTimeline = async (
	timelineId: string
): Promise< WooPaymentsTimelineResponse > =>
	apiFetch< WooPaymentsTimelineResponse >( {
		path: `${ PAYMENTS_PATH }/timeline/${ encodePathSegment(
			timelineId
		) }`,
		method: 'GET',
	} );

export const getWooPaymentsReaderChargeSummary = async (
	transactionId: string,
	options: { signal?: AbortSignal } = {}
): Promise< WooPaymentsReaderChargeSummaryResponse > =>
	apiFetch< WooPaymentsReaderChargeSummaryResponse >( {
		path: `${ PAYMENTS_PATH }/readers/charges/${ encodePathSegment(
			transactionId
		) }`,
		method: 'GET',
		...( options.signal ? { signal: options.signal } : {} ),
	} );

export const getWooPaymentsTransactionSearch = (
	searchTerm: string
): Promise< Array< { label: string } > > =>
	apiFetch< Array< { label: string } > >( {
		path: buildPathWithQuery( `${ PAYMENTS_PATH }/transactions/search`, {
			search_term: searchTerm,
		} ),
		method: 'GET',
	} );

export const requestWooPaymentsTransactionsExport = (
	query: WooPaymentsMoneyMovementQuery = {}
): Promise< Record< string, unknown > > =>
	apiFetch< Record< string, unknown > >( {
		path: buildSettledTransactionsApiPath(
			`${ PAYMENTS_PATH }/transactions/download`,
			{ ...query, ...getWooPaymentsExportRecipient() }
		),
		method: 'POST',
	} );

export const getWooPaymentsTransactionsExportUrl = async (
	exportId: string
): Promise< Record< string, unknown > > =>
	apiFetch< Record< string, unknown > >( {
		path: `${ PAYMENTS_PATH }/transactions/download/${ encodePathSegment(
			exportId
		) }`,
		method: 'GET',
	} );

export const getWooPaymentsAuthorizations = (
	query: WooPaymentsAuthorizationQuery = {}
): Promise< WooPaymentsListResponse< WooPaymentsAuthorization > > =>
	apiFetch< WooPaymentsListResponse< WooPaymentsAuthorization > >( {
		path: buildAuthorizationsPath(
			`${ PAYMENTS_PATH }/authorizations`,
			query
		),
		method: 'GET',
	} );

export const getWooPaymentsAuthorizationsSummary = (
	query: WooPaymentsAuthorizationQuery = {}
): Promise< WooPaymentsAuthorizationsSummary > =>
	apiFetch< WooPaymentsAuthorizationsSummary >( {
		path: buildAuthorizationsPath(
			`${ PAYMENTS_PATH }/authorizations/summary`,
			query
		),
		method: 'GET',
	} );

export const getWooPaymentsAuthorization = async (
	paymentIntentId: string
): Promise< WooPaymentsAuthorization > =>
	apiFetch< WooPaymentsAuthorization >( {
		path: `${ PAYMENTS_PATH }/authorizations/${ encodePathSegment(
			paymentIntentId
		) }`,
		method: 'GET',
	} );

export const captureWooPaymentsAuthorization = async (
	orderId: number | string,
	paymentIntentId: string
): Promise< WooPaymentsAuthorizationActionResponse > =>
	apiFetch< WooPaymentsAuthorizationActionResponse >( {
		path: `${ PAYMENTS_PATH }/orders/${ encodePathSegment(
			String( orderId )
		) }/capture_authorization`,
		method: 'POST',
		data: {
			payment_intent_id: paymentIntentId,
		},
	} );

export const cancelWooPaymentsAuthorization = async (
	orderId: number | string,
	paymentIntentId: string
): Promise< WooPaymentsAuthorizationActionResponse > =>
	apiFetch< WooPaymentsAuthorizationActionResponse >( {
		path: `${ PAYMENTS_PATH }/orders/${ encodePathSegment(
			String( orderId )
		) }/cancel_authorization`,
		method: 'POST',
		data: {
			payment_intent_id: paymentIntentId,
		},
	} );

export const refundWooPaymentsCharge = ( {
	chargeId,
	amount,
	reason,
	orderId,
}: WooPaymentsRefundRequest ): Promise< WooPaymentsRefundResponse > =>
	apiFetch< WooPaymentsRefundResponse >( {
		path: `${ PAYMENTS_PATH }/refund`,
		method: 'POST',
		data: {
			charge_id: chargeId,
			amount,
			reason,
			order_id: orderId,
		},
	} );

export const getWooPaymentsFraudOutcomeTransactions = (
	query: WooPaymentsFraudOutcomeQuery = {}
): Promise< WooPaymentsListResponse< WooPaymentsTransaction > > =>
	apiFetch< WooPaymentsListResponse< WooPaymentsTransaction > >( {
		path: buildPathWithQuery(
			`${ PAYMENTS_PATH }/transactions/fraud-outcomes`,
			query
		),
		method: 'GET',
	} );

export const getWooPaymentsFraudOutcomeTransactionsSummary = (
	query: WooPaymentsFraudOutcomeQuery = {}
): Promise< Record< string, unknown > > =>
	apiFetch< Record< string, unknown > >( {
		path: buildPathWithQuery(
			`${ PAYMENTS_PATH }/transactions/fraud-outcomes/summary`,
			query
		),
		method: 'GET',
	} );

export const getWooPaymentsFraudOutcomeTransactionSearch = (
	status: string,
	searchTerm: string
): Promise< Array< { key: string; label: string } > > =>
	apiFetch< Array< { key: string; label: string } > >( {
		path: buildPathWithQuery(
			`${ PAYMENTS_PATH }/transactions/fraud-outcomes/search`,
			{
				status,
				search_term: searchTerm,
			}
		),
		method: 'GET',
	} );

export const getWooPaymentsFraudOutcomeTransactionsExport = (
	query: WooPaymentsFraudOutcomeQuery = {}
): Promise< WooPaymentsListResponse< WooPaymentsTransaction > > =>
	apiFetch< WooPaymentsListResponse< WooPaymentsTransaction > >( {
		path: buildPathWithQuery(
			`${ PAYMENTS_PATH }/transactions/fraud-outcomes/download`,
			query
		),
		method: 'GET',
	} );

export const getWooPaymentsDisputes = (
	query: WooPaymentsMoneyMovementQuery = {}
): Promise< WooPaymentsListResponse< WooPaymentsDispute > > =>
	apiFetch< WooPaymentsListResponse< WooPaymentsDispute > >( {
		path: buildPathWithQuery( `${ PAYMENTS_PATH }/disputes`, query ),
		method: 'GET',
	} );

export const getWooPaymentsDisputesSummary = (
	query: WooPaymentsMoneyMovementQuery = {}
): Promise< Record< string, unknown > > =>
	apiFetch< Record< string, unknown > >( {
		path: buildPathWithQuery(
			`${ PAYMENTS_PATH }/disputes/summary`,
			query
		),
		method: 'GET',
	} );

export const getWooPaymentsDispute = async (
	disputeId: string
): Promise< WooPaymentsDispute > =>
	apiFetch< WooPaymentsDispute >( {
		path: `${ PAYMENTS_PATH }/disputes/${ encodePathSegment( disputeId ) }`,
		method: 'GET',
	} );

export const requestWooPaymentsDisputesExport = (
	query: WooPaymentsMoneyMovementQuery = {}
): Promise< Record< string, unknown > > =>
	apiFetch< Record< string, unknown > >( {
		path: buildPathWithQuery( `${ PAYMENTS_PATH }/disputes/download`, {
			...query,
			...getWooPaymentsExportRecipient(),
		} ),
		method: 'POST',
	} );

export const getWooPaymentsDisputesExportUrl = async (
	exportId: string
): Promise< Record< string, unknown > > =>
	apiFetch< Record< string, unknown > >( {
		path: `${ PAYMENTS_PATH }/disputes/download/${ encodePathSegment(
			exportId
		) }`,
		method: 'GET',
	} );

export const updateWooPaymentsDispute = async (
	disputeId: string,
	data: {
		evidence: Record< string, unknown >;
		submit: boolean;
		metadata: Record< string, unknown >;
	}
): Promise< WooPaymentsDispute > =>
	apiFetch< WooPaymentsDispute >( {
		path: `${ PAYMENTS_PATH }/disputes/${ encodePathSegment( disputeId ) }`,
		method: 'POST',
		data,
	} );

export const uploadWooPaymentsDisputeFile = (
	body: FormData
): Promise< WooPaymentsDisputeFile > =>
	apiFetch< WooPaymentsDisputeFile >( {
		path: `${ PAYMENTS_PATH }/file`,
		method: 'POST',
		body,
	} );

export const getWooPaymentsDisputeFileDetails = async (
	fileId: string
): Promise< WooPaymentsDisputeFile > =>
	apiFetch< WooPaymentsDisputeFile >( {
		path: `${ PAYMENTS_PATH }/file/${ encodePathSegment(
			fileId
		) }/details`,
		method: 'GET',
	} );

export const closeWooPaymentsDispute = async (
	disputeId: string
): Promise< WooPaymentsDispute > =>
	apiFetch< WooPaymentsDispute >( {
		path: `${ PAYMENTS_PATH }/disputes/${ encodePathSegment(
			disputeId
		) }/close`,
		method: 'POST',
	} );
