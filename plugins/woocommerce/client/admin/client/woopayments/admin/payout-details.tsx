/**
 * External dependencies
 */
import { Button } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import type { ReactNode } from 'react';
import { useLocation } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { getWooPaymentsDeposit } from './overview/data';
import type { WooPaymentsDeposit } from './overview/types';
import {
	formatPayoutDate,
	formatPayoutStatus,
	formatWooPaymentsAmount,
} from './overview/utils';
import type { WooPaymentsMoneyMovementQuery } from './money-movement/types';
import { buildMoneyMovementRoutePath } from './money-movement/query';
import { getErrorMessage } from './money-movement/utils';
import { WooPaymentsTransactionsList } from './money-movement/transactions-list';
import { getSettingsPaymentsProviderRouteUrl } from './utils';
import { WooPaymentsTestModeNotice } from './test-mode-notice';
import './style.scss';

const INSTANT_PAYOUTS_DOCS_URL =
	'https://woocommerce.com/document/woopayments/payouts/instant-payouts/#request-an-instant-payout';

const SummaryRow = ( {
	label,
	value,
}: {
	label: string;
	value: ReactNode;
} ) => (
	<div>
		<dt>{ label }</dt>
		<dd>{ value }</dd>
	</div>
);

export const WooPaymentsPayoutDetailsPage = () => {
	const [ payout, setPayout ] = useState< WooPaymentsDeposit | null >( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ errorMessage, setErrorMessage ] = useState< string | null >( null );
	const location = useLocation();
	const payoutId = new URLSearchParams( location.search ).get( 'id' ) || '';
	// The list keeps its paging, sorting and search in this page's URL, next to the payout ID.
	const buildPayoutTransactionsRoute = useCallback(
		( query: WooPaymentsMoneyMovementQuery ) => {
			const { deposit_id: _depositId, ...listQuery } = query;
			const route = buildMoneyMovementRoutePath(
				'/woopayments/payouts/details',
				listQuery
			);

			return `${ route }${
				route.includes( '?' ) ? '&' : '?'
			}id=${ encodeURIComponent( payoutId ) }`;
		},
		[ payoutId ]
	);
	useEffect( () => {
		let isMounted = true;

		const loadPayoutDetails = async () => {
			if ( ! payoutId ) {
				setErrorMessage(
					__( 'A payout ID is required.', 'woocommerce' )
				);
				setIsLoading( false );
				return;
			}

			setIsLoading( true );

			try {
				const nextPayout = await getWooPaymentsDeposit( payoutId );

				if ( isMounted ) {
					setPayout( nextPayout );
					setErrorMessage( null );
				}
			} catch ( error ) {
				if ( isMounted ) {
					setErrorMessage(
						getErrorMessage(
							error,
							__(
								'Unable to load WooPayments payout details.',
								'woocommerce'
							)
						)
					);
				}
			} finally {
				if ( isMounted ) {
					setIsLoading( false );
				}
			}
		};

		void loadPayoutDetails();

		return () => {
			isMounted = false;
		};
	}, [ payoutId ] );

	const copyBankReferenceId = async () => {
		if ( ! payout?.bank_reference_key ) {
			return;
		}

		try {
			if ( ! navigator.clipboard?.writeText ) {
				throw new Error( 'Clipboard API is unavailable.' );
			}

			await navigator.clipboard.writeText( payout.bank_reference_key );
			// Announce through a single channel: the imperative speak() call.
			// The shared status live region below is reserved for the
			// load/loaded/error lifecycle to avoid a duplicate announcement.
			speak( __( 'Bank reference ID copied.', 'woocommerce' ), 'polite' );
		} catch ( error ) {
			speak(
				__(
					'Unable to copy bank reference ID to clipboard.',
					'woocommerce'
				),
				'polite'
			);
		}
	};

	const loadingMessage: string = __(
		'Loading payout details…',
		'woocommerce'
	);
	let liveStatusMessage: string = __(
		'Payout details loaded.',
		'woocommerce'
	);

	if ( errorMessage ) {
		liveStatusMessage = errorMessage;
	} else if ( isLoading ) {
		liveStatusMessage = loadingMessage;
	}

	const isWithdrawal = payout?.type === 'withdrawal';
	const isInstantPayout = payout?.automatic === false;
	const payoutLabel = isWithdrawal
		? __( 'withdrawal', 'woocommerce' )
		: __( 'payout', 'woocommerce' );
	const payoutTitle = isWithdrawal
		? __( 'Withdrawal details', 'woocommerce' )
		: __( 'Payout details', 'woocommerce' );
	const payoutIdLabel = isWithdrawal
		? __( 'Withdrawal ID', 'woocommerce' )
		: __( 'Payout ID', 'woocommerce' );
	const payoutTransactionsTitle = isWithdrawal
		? __( 'Withdrawal transactions', 'woocommerce' )
		: __( 'Payout transactions', 'woocommerce' );
	const allTransactionsUrl =
		payout &&
		getSettingsPaymentsProviderRouteUrl(
			`/woopayments/transactions?deposit_id=${ encodeURIComponent(
				payout.id
			) }`
		);
	const bankReferenceId = payout?.bank_reference_key;

	return (
		<section
			className="woocommerce-woopayments-money-movement"
			aria-busy={ isLoading }
		>
			{ /* Client 11.1.0 deposits/details/index.tsx:317. */ }
			<WooPaymentsTestModeNotice currentPage="deposits" isDetailsView />
			<a
				href={ getSettingsPaymentsProviderRouteUrl(
					'/woopayments/payouts'
				) }
			>
				{ __( 'Back to payout history', 'woocommerce' ) }
			</a>
			<h2>{ payoutTitle }</h2>
			<p
				className="screen-reader-text"
				role={ errorMessage ? 'alert' : 'status' }
				aria-live={ errorMessage ? 'assertive' : 'polite' }
			>
				{ liveStatusMessage }
			</p>
			{ isLoading && (
				<p className="woocommerce-woopayments-money-movement__status">
					{ loadingMessage }
				</p>
			) }
			{ errorMessage && (
				<p className="woocommerce-woopayments-money-movement__status">
					{ errorMessage }
				</p>
			) }
			{ payout && ! errorMessage && (
				<>
					<dl className="woocommerce-woopayments-money-movement__details">
						<SummaryRow
							label={ payoutIdLabel }
							value={ payout.id }
						/>
						<SummaryRow
							label={ __( 'Dispatch date', 'woocommerce' ) }
							value={ formatPayoutDate( payout ) }
						/>
						<SummaryRow
							label={ __( 'Status', 'woocommerce' ) }
							value={ formatPayoutStatus( payout.status ) }
						/>
						<SummaryRow
							label={ __( 'Amount', 'woocommerce' ) }
							value={ formatWooPaymentsAmount(
								payout.amount,
								payout.currency
							) }
						/>
						<SummaryRow
							label={ __( 'Bank account', 'woocommerce' ) }
							value={
								payout.bankAccount ||
								__( 'Not available', 'woocommerce' )
							}
						/>
						<SummaryRow
							label={ __( 'Bank reference ID', 'woocommerce' ) }
							value={
								bankReferenceId ? (
									<span className="woocommerce-woopayments-money-movement__copyable-value">
										<span>{ bankReferenceId }</span>
										<Button
											variant="secondary"
											onClick={ copyBankReferenceId }
											aria-label={ __(
												'Copy bank reference ID to clipboard',
												'woocommerce'
											) }
										>
											{ __( 'Copy', 'woocommerce' ) }
										</Button>
									</span>
								) : (
									__( 'Not available', 'woocommerce' )
								)
							}
						/>
					</dl>
					{ ( payout.failure_message || payout.failure_code ) && (
						<p className="woocommerce-woopayments-money-movement__notice">
							<strong>
								{ __( 'Failure reason:', 'woocommerce' ) }
							</strong>{ ' ' }
							{ payout.failure_message || payout.failure_code }
						</p>
					) }
					<section className="woocommerce-woopayments-overview-card">
						<h3>{ payoutTransactionsTitle }</h3>
						{ isInstantPayout ? (
							<p className="woocommerce-woopayments-money-movement__notice">
								{ __(
									"We're unable to show transaction history on instant payouts.",
									'woocommerce'
								) }{ ' ' }
								<a href={ INSTANT_PAYOUTS_DOCS_URL }>
									{ __( 'Learn more', 'woocommerce' ) }
								</a>
							</p>
						) : (
							<>
								{ /* Client 11.1.0 deposits/details/index.tsx:356: the transactions list, scoped to the payout. */ }
								<WooPaymentsTransactionsList
									depositId={ payout.id }
									buildRoute={ buildPayoutTransactionsRoute }
								/>
								{ allTransactionsUrl && (
									<p className="woocommerce-woopayments-money-movement__footer-actions">
										<a href={ allTransactionsUrl }>
											{ sprintf(
												/* translators: %s: payout or withdrawal. */
												__(
													'View all transactions in this %s',
													'woocommerce'
												),
												payoutLabel
											) }
										</a>
									</p>
								) }
							</>
						) }
					</section>
				</>
			) }
		</section>
	);
};

export default WooPaymentsPayoutDetailsPage;
