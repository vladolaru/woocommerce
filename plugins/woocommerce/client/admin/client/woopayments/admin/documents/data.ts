/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { getWooPaymentsSettingsBootstrap } from '../../settings/bootstrap';
import { normalizeDateFiltersForApi } from '../money-movement/query';
import type { WooPaymentsMoneyMovementQuery } from '../money-movement/types';
import {
	DOCUMENT_LIST_QUERY_PARAM_ORDER,
	DOCUMENT_SUMMARY_QUERY_PARAM_ORDER,
	serializeDocumentsQuery,
} from './query';
import type {
	WooPaymentsDocumentsAccountResponse,
	WooPaymentsDocumentsListResponse,
	WooPaymentsDocumentsQuery,
	WooPaymentsDocumentsSummary,
	WooPaymentsVatDetails,
	WooPaymentsVatValidationResponse,
} from './types';

const ACCOUNT_PATH = '/wc-admin/settings/payments/woopayments/account';
const PAYMENTS_PATH = '/wc/v3/payments';
const DOCUMENTS_PATH = `${ PAYMENTS_PATH }/documents`;
const VAT_PATH = `${ PAYMENTS_PATH }/vat`;

const buildPathWithQuery = (
	path: string,
	query: WooPaymentsDocumentsQuery,
	paramOrder: readonly string[]
) => {
	// Client 11.1.0 `data/documents/resolvers.js:26-30`: dates go as the start or end of the merchant's day.
	const queryString = serializeDocumentsQuery(
		normalizeDateFiltersForApi(
			query as WooPaymentsMoneyMovementQuery
		) as WooPaymentsDocumentsQuery,
		paramOrder
	);

	return queryString ? `${ path }?${ queryString }` : path;
};

const getWpApiSettings = () =>
	(
		globalThis as typeof globalThis & {
			wpApiSettings?: {
				root?: string;
				nonce?: string;
			};
		}
	 ).wpApiSettings;

/**
 * The Documents account facts, from the admin preload as client 11.1.0 has them in `wcpaySettings.accountStatus`, or from
 * the account route when the page was not preloaded.
 */
export const getWooPaymentsDocumentsAccount =
	(): Promise< WooPaymentsDocumentsAccountResponse > => {
		const preloaded = getWooPaymentsSettingsBootstrap().accountDocuments;
		if ( preloaded && typeof preloaded === 'object' ) {
			return Promise.resolve( {
				documents: preloaded,
			} as WooPaymentsDocumentsAccountResponse );
		}

		return apiFetch< WooPaymentsDocumentsAccountResponse >( {
			path: ACCOUNT_PATH,
			method: 'GET',
		} );
	};

export const getWooPaymentsDocuments = (
	query: WooPaymentsDocumentsQuery = {}
): Promise< WooPaymentsDocumentsListResponse > =>
	apiFetch< WooPaymentsDocumentsListResponse >( {
		path: buildPathWithQuery(
			DOCUMENTS_PATH,
			query,
			DOCUMENT_LIST_QUERY_PARAM_ORDER
		),
		method: 'GET',
	} );

export const getWooPaymentsDocumentsSummary = (
	query: WooPaymentsDocumentsQuery = {}
): Promise< WooPaymentsDocumentsSummary > =>
	apiFetch< WooPaymentsDocumentsSummary >( {
		path: buildPathWithQuery(
			`${ DOCUMENTS_PATH }/summary`,
			query,
			DOCUMENT_SUMMARY_QUERY_PARAM_ORDER
		),
		method: 'GET',
	} );

export const buildWooPaymentsDocumentUrl = ( documentId: string ) => {
	const apiSettings = getWpApiSettings();
	const root =
		apiSettings?.root ||
		( typeof window !== 'undefined' && window.location?.origin
			? `${ window.location.origin }/wp-json/`
			: '/wp-json/' );
	const url = new URL(
		`${ root.replace(
			/\/$/,
			''
		) }${ DOCUMENTS_PATH }/${ encodeURIComponent( documentId ) }`
	);

	if ( apiSettings?.nonce ) {
		url.searchParams.set( '_wpnonce', apiSettings.nonce );
	}

	return url.toString();
};

export const validateWooPaymentsVatNumber = (
	vatNumber: string
): Promise< WooPaymentsVatValidationResponse > =>
	apiFetch< WooPaymentsVatValidationResponse >( {
		path: `${ VAT_PATH }/${ encodeURIComponent( vatNumber ) }`,
		method: 'GET',
	} );

export const saveWooPaymentsVatDetails = (
	details: WooPaymentsVatDetails
): Promise< WooPaymentsVatDetails > => {
	// Leave the VAT number out when the merchant has none, as the client does: the route only accepts a string.
	const { vat_number: vatNumber, ...businessDetails } = details;

	return apiFetch< WooPaymentsVatDetails >( {
		path: VAT_PATH,
		method: 'POST',
		data: vatNumber === null ? businessDetails : details,
	} );
};
