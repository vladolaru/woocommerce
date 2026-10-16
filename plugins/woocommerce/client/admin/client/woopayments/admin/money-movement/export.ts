/**
 * External dependencies
 */
import { dispatch } from '@wordpress/data';
import { useCallback, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getWooPaymentsSettingsBootstrap } from '../../settings/bootstrap';

type WooPaymentsExportResponse = Record< string, unknown >;

type RunWooPaymentsExportOptions = {
	requestExport: () => Promise< WooPaymentsExportResponse >;
	getExportUrl: ( exportId: string ) => Promise< WooPaymentsExportResponse >;
	triggerDownload?: ( downloadUrl: string ) => void;
	onSuccess?: () => void;
	onError?: ( details: { reason: 'request' | 'timeout' } ) => void;
	maxAttempts?: number;
	pollDelayMs?: number;
	signal?: AbortSignal;
};

type WooPaymentsExportList = 'transactions' | 'disputes' | 'payouts' | 'fees';

type ExportSettingsWindow = typeof window & {
	wcSettings?: { locale?: { userLocale?: string } };
};

const getNotices = () =>
	dispatch( 'core/notices' ) as unknown as {
		createSuccessNotice: ( text: string ) => void;
		createErrorNotice: ( text: string ) => void;
	};

const getCurrentUserEmail = () => {
	const email = getWooPaymentsSettingsBootstrap().currentUserEmail;

	return typeof email === 'string' ? email : '';
};

/**
 * The address and locale the platform uses to email an export that is not ready in time. Client 11.1.0 sends
 * `wcpaySettings.currentUserEmail` and `wcSettings.locale.userLocale` with every list export request
 * (`transactions/list/index.tsx:602-603`, `disputes/index.tsx:353-355`, `deposits/list/index.tsx:222-223`).
 */
export const getWooPaymentsExportRecipient = () => {
	const userEmail = getCurrentUserEmail();
	const locale = ( window as ExportSettingsWindow ).wcSettings?.locale
		?.userLocale;

	return {
		...( userEmail ? { user_email: userEmail } : {} ),
		...( locale ? { locale } : {} ),
	};
};

const hasValue = ( value: unknown ) =>
	Array.isArray( value ) ? value.length > 0 : !! value;

/**
 * Each list's large-export confirmation: the row count from which an unfiltered export asks first, and the
 * filters that skip the question. Client 11.1.0 `transactions/list/index.tsx:651-683`,
 * `disputes/index.tsx:384-403`, `deposits/list/index.tsx:248-267` and `reports/fees-export-button.tsx:33,128-153`.
 */
const LARGE_EXPORT_CONFIRMATIONS: Record<
	WooPaymentsExportList,
	{
		threshold: number;
		filters: string[];
		getMessage: ( totalRows: number ) => string;
	}
> = {
	transactions: {
		threshold: 10000,
		filters: [
			'date_after',
			'date_before',
			'date_between',
			'source_is',
			'source_is_not',
			'search',
			'type_is',
			'type_is_not',
			'channel_is',
			'channel_is_not',
			'customer_country_is',
			'customer_country_is_not',
			'risk_level_is',
			'risk_level_is_not',
			'source_device_is',
			'source_device_is_not',
		],
		getMessage: ( totalRows ) =>
			sprintf(
				/* translators: %d: number of transactions to export. */
				__(
					"You are about to export %d transactions. If you'd like to reduce the size of your export, you can use one or more filters. Would you like to continue?",
					'woocommerce'
				),
				totalRows
			),
	},
	disputes: {
		threshold: 1000,
		filters: [
			'date_before',
			'date_after',
			'date_between',
			'status_is',
			'status_is_not',
		],
		getMessage: ( totalRows ) =>
			sprintf(
				/* translators: %d: number of disputes to export. */
				__(
					"You are about to export %d disputes. If you'd like to reduce the size of your export, you can use one or more filters. Would you like to continue?",
					'woocommerce'
				),
				totalRows
			),
	},
	payouts: {
		threshold: 1000,
		filters: [
			'date_before',
			'date_after',
			'date_between',
			'status_is',
			'status_is_not',
			'store_currency_is',
		],
		getMessage: ( totalRows ) =>
			sprintf(
				/* translators: %d: number of payouts to export. */
				__(
					"You are about to export %d deposits. If you'd like to reduce the size of your export, you can use one or more filters. Would you like to continue?",
					'woocommerce'
				),
				totalRows
			),
	},
	fees: {
		threshold: 10000,
		filters: [
			'date_after',
			'date_before',
			'date_between',
			'payment_method_type',
			'type',
			'order_id',
			'deposit_id',
			'search',
		],
		getMessage: ( totalRows ) =>
			sprintf(
				/* translators: %d: number of fees to export. */
				__(
					"You are about to export %d fees. If you'd like to reduce the size of your export, you can use one or more filters. Would you like to continue?",
					'woocommerce'
				),
				totalRows
			),
	},
};

/**
 * Whether a list export may start: filtered and smaller exports start at once, larger unfiltered ones ask first.
 *
 * @param list      The list being exported.
 * @param totalRows The list's row count from its summary.
 * @param query     The list's current query.
 */
export const confirmWooPaymentsExport = (
	list: WooPaymentsExportList,
	totalRows: number,
	query: Record< string, unknown >
) => {
	const { threshold, filters, getMessage } =
		LARGE_EXPORT_CONFIRMATIONS[ list ];

	return (
		filters.some( ( key ) => hasValue( query[ key ] ) ) ||
		totalRows < threshold ||
		// eslint-disable-next-line no-alert -- Client 11.1.0 asks with a browser confirmation.
		window.confirm( getMessage( totalRows ) )
	);
};

const wait = ( delayMs: number ) =>
	new Promise( ( resolve ) => {
		window.setTimeout( resolve, delayMs );
	} );

const getForcedDownloadUrl = ( downloadUrl: string ) => {
	const separator = downloadUrl.includes( '?' ) ? '&' : '?';

	return `${ downloadUrl }${ separator }force_download=true`;
};

export const triggerWooPaymentsExportDownload = ( downloadUrl: string ) => {
	const anchor = document.createElement( 'a' );

	anchor.href = downloadUrl;
	anchor.download = '';
	anchor.rel = 'noopener noreferrer';
	anchor.style.display = 'none';
	document.body.appendChild( anchor );
	anchor.click();
	anchor.remove();
};

/**
 * Requests a CSV export and polls for the file, like client 11.1.0 `hooks/use-report-export.ts` with the
 * list handlers' snackbar: the merchant is told at once that the file downloads or arrives by email, a failed
 * check is retried, and only a failed request shows an error. Up to five checks, one second apart; after
 * that the file is left to the email.
 *
 * @param options                 The export options.
 * @param options.requestExport   Starts the export; resolves with its `export_id`.
 * @param options.getExportUrl    Checks an export; resolves with its `status` and `download_url`.
 * @param options.triggerDownload Downloads the ready file.
 * @param options.onSuccess       Called once the file downloads.
 * @param options.onError         Called when the request fails or the file is not ready in time.
 * @param options.maxAttempts     How many times to check.
 * @param options.pollDelayMs     The wait before each check.
 * @param options.signal          Stops the checks once aborted, as when the page unmounts.
 */
export const runWooPaymentsExport = async ( {
	requestExport,
	getExportUrl,
	triggerDownload = triggerWooPaymentsExportDownload,
	onSuccess,
	onError,
	maxAttempts = 5,
	pollDelayMs = 1000,
	signal,
}: RunWooPaymentsExportOptions ): Promise< void > => {
	// The client starts the request, then raises the snackbar without waiting for it.
	const exportRequest = requestExport().then(
		( response ) => ( { response } ),
		() => ( { response: undefined } )
	);

	getNotices().createSuccessNotice(
		sprintf(
			/* translators: %s: the email address the export is sent to. */
			__(
				'We’re processing your export. 🎉 The file will download automatically and be emailed to %s.',
				'woocommerce'
			),
			getCurrentUserEmail()
		)
	);

	const { response: exportResponse } = await exportRequest;

	if ( ! exportResponse ) {
		onError?.( { reason: 'request' } );
		getNotices().createErrorNotice(
			__( 'There was a problem generating your export.', 'woocommerce' )
		);

		return;
	}

	const exportId = exportResponse.export_id;

	if ( typeof exportId !== 'string' || ! exportId ) {
		onError?.( { reason: 'request' } );

		return;
	}

	for ( let attempt = 1; attempt <= maxAttempts; attempt++ ) {
		await wait( pollDelayMs );

		if ( signal?.aborted ) {
			return;
		}

		let urlResponse: WooPaymentsExportResponse = {};

		try {
			urlResponse = await getExportUrl( exportId );
		} catch {
			urlResponse = {};
		}

		const downloadUrl = urlResponse?.download_url;

		if (
			urlResponse?.status === 'success' &&
			typeof downloadUrl === 'string' &&
			downloadUrl
		) {
			triggerDownload( getForcedDownloadUrl( downloadUrl ) );
			onSuccess?.();

			return;
		}
	}

	onError?.( { reason: 'timeout' } );
};

/**
 * Runs list exports that stop checking for the file when the component unmounts, like client 11.1.0
 * `hooks/use-report-export.ts:45-52`, so a check left running cannot download or notify on another page.
 */
export const useWooPaymentsExport = () => {
	const controllerRef = useRef< AbortController | null >( null );

	useEffect( () => {
		const controller = new AbortController();
		controllerRef.current = controller;

		return () => controller.abort();
	}, [] );

	return useCallback(
		( options: Omit< RunWooPaymentsExportOptions, 'signal' > ) =>
			runWooPaymentsExport( {
				...options,
				signal: controllerRef.current?.signal,
			} ),
		[]
	);
};
