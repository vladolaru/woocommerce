/**
 * External dependencies
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Notice,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { OrderStatus, SummaryListPlaceholder } from '@woocommerce/components';
import { dispatch } from '@wordpress/data';
import { copy } from '@wordpress/icons';
import clsx from 'clsx';
import NoticeOutlineIcon from 'gridicons/dist/notice-outline';
import type { ComponentType, ElementType, ReactNode } from 'react';
import { useLocation } from 'react-router-dom';

/**
 * Internal dependencies
 */
import '../../settings-payments/settings-payments-body.scss';
import { getWooPaymentsDeposit } from './overview/data';
import type { WooPaymentsDeposit } from './overview/types';
import {
	formatPayoutSiteDate,
	formatWooPaymentsAmount,
} from './overview/utils';
import { getPayoutStatusLabel } from './payout-status';
import type { WooPaymentsMoneyMovementQuery } from './money-movement/types';
import { buildMoneyMovementRoutePath } from './money-movement/query';
import {
	formatExplicitCurrency,
	getErrorMessage,
} from './money-movement/utils';
import { WooPaymentsTransactionsList } from './money-movement/transactions-list';
import { WooPaymentsTestModeNotice } from './test-mode-notice';
import './style.scss';
import './payout-details.scss';

// Client 11.1.0 `components/inline-notice`: the notice-outline gridicon; the shared typings omit its class name.
const ErrorIcon = NoticeOutlineIcon as ElementType< { className?: string } >;

// The component's generated types declare an `Object` return, which JSX rejects.
const StatusIndicator = OrderStatus as unknown as ComponentType< {
	className?: string;
	order: { status: string };
	orderStatusMap: Record< string, string >;
} >;

const INSTANT_PAYOUTS_DOCS_URL =
	'https://woocommerce.com/document/woopayments/payouts/instant-payouts/#request-an-instant-payout';

/**
 * Client 11.1.0 `deposits/strings.ts:37-147`: payout failure code to display string.
 */
export const payoutFailureMessages: Record< string, string > = {
	insufficient_funds: __(
		'Your account has insufficient funds to cover your negative balance.',
		'woocommerce'
	),
	bank_account_restricted: __(
		'The bank account has restrictions on either the type or number of transfers allowed. This normally indicates that the bank account is a savings or other non-checking account.',
		'woocommerce'
	),
	debit_not_authorized: __(
		'Debit transactions are not approved on your bank account. Bank accounts need to be set up for both credit and debit transfers.',
		'woocommerce'
	),
	invalid_card: __(
		'The card used was invalid. This usually means the card number is invalid or the account has been closed.',
		'woocommerce'
	),
	declined: __(
		'The bank has declined this transfer. Please contact the bank for more information.',
		'woocommerce'
	),
	invalid_transaction: __(
		'The transfer was refused by the issuing bank because this type of payment is not permitted for this card. Please contact the issuing bank for clarification.',
		'woocommerce'
	),
	refer_to_card_issuer: __(
		'The transfer was refused by the card issuer. Please contact the issuing bank for clarification.',
		'woocommerce'
	),
	unsupported_card: __(
		'The bank no longer supports transfers to this card.',
		'woocommerce'
	),
	lost_or_stolen_card: __(
		'The card used has been reported lost or stolen. Please contact the issuing bank for clarification.',
		'woocommerce'
	),
	invalid_issuer: __(
		'The issuer specified by the card number does not exist. Please verify card details.',
		'woocommerce'
	),
	expired_card: __(
		'The card used has expired. Please switch to a different card or payment method. Contact the issuing bank for clarification.',
		'woocommerce'
	),
	could_not_process: __(
		// The same failure code is used if processing is failed by the bank or Stripe.
		'The bank or the payment processor could not process this transfer.',
		'woocommerce'
	),
	invalid_account_number: __(
		'The bank account details on file are probably incorrect. While the routing number appears correct, the account number is invalid.',
		'woocommerce'
	),
	incorrect_account_holder_name: __(
		'The bank account holder name on file appears to be incorrect.',
		'woocommerce'
	),
	account_closed: __( 'The bank account has been closed.', 'woocommerce' ),
	no_account: __(
		'The bank account details on file are probably incorrect. No bank account could be located with those details.',
		'woocommerce'
	),
	exceeds_amount_limit: __(
		'The card issuer has declined the transaction as it will exceed the card limit. Please switch to a different card or payment method. Contact the issuing bank for clarification.',
		'woocommerce'
	),
	account_frozen: __( 'The bank account has been frozen.', 'woocommerce' ),
	issuer_unavailable: __(
		'The issuing bank is currently unavailable. Our system will automatically try again on your next payout date, or you can switch to a different payout method.',
		'woocommerce'
	),
	invalid_currency: __(
		'The bank was unable to process this transfer because of its currency. This is probably because the bank account cannot accept payments in that currency.',
		'woocommerce'
	),
	incorrect_account_type: __(
		'The bank account type is incorrect. This value can only be checking or savings in most countries. In Japan, it can only be futsu or toza.',
		'woocommerce'
	),
	incorrect_account_holder_details: __(
		'The bank could not process this transfer. Please check that the entered bank account details match the corresponding account bank statement exactly.',
		'woocommerce'
	),
	bank_ownership_changed: __(
		'The destination bank account is no longer valid because its branch has changed ownership.',
		'woocommerce'
	),
	exceeds_count_limit: __(
		'The selected card has exceeded its card usage frequency limit. Please switch to a different card or payment method. Contact the issuing bank for clarification.',
		'woocommerce'
	),
	incorrect_account_holder_address: __(
		'Your bank notified us that the bank account holder address on file is incorrect.',
		'woocommerce'
	),
	incorrect_account_holder_tax_id: __(
		'Your bank notified us that the bank account holder tax ID on file is incorrect.',
		'woocommerce'
	),
	invalid_account_number_length: __(
		'Your bank notified us that the bank account number is too long.',
		'woocommerce'
	),
};

const OverviewItem = ( {
	label,
	value,
	valueClassName,
}: {
	label: string;
	value: ReactNode;
	valueClassName?: string | false;
} ) => (
	<li className="woocommerce-woopayments-payout-overview__item">
		<div className="woocommerce-woopayments-payout-overview__label">
			{ label }
		</div>
		<div
			className={ clsx(
				'woocommerce-woopayments-payout-overview__value',
				valueClassName
			) }
		>
			{ value }
		</div>
	</li>
);

const PayoutDateItem = ( { payout }: { payout: WooPaymentsDeposit } ) => {
	let label: string = __( 'Payout date', 'woocommerce' );
	if ( payout.automatic === false ) {
		label = __( 'Instant payout date', 'woocommerce' );
	}
	if ( payout.type === 'withdrawal' ) {
		label = __( 'Withdrawal date', 'woocommerce' );
	}

	return (
		<OverviewItem
			label={ `${ label }: ${ formatPayoutSiteDate( payout ) }` }
			value={
				// Client 11.1.0 `deposits/details/index.tsx:48-66`: core's status indicator dot and label.
				<StatusIndicator
					className="woocommerce-woopayments-payout-overview__status"
					order={ { status: payout.status } }
					orderStatusMap={ {
						[ payout.status ]: getPayoutStatusLabel( payout ),
					} }
				/>
			}
		/>
	);
};

// Client 11.1.0 `deposits/details/index.tsx:146-243`.
const PayoutOverview = ( { payout }: { payout: WooPaymentsDeposit } ) => {
	const isWithdrawal = payout.type === 'withdrawal';
	const fee = payout.fee || 0;
	const failureReason =
		( payout.failure_code &&
			payoutFailureMessages[ payout.failure_code ] ) ||
		payout.failure_message ||
		__( 'Unknown', 'woocommerce' );

	return (
		<>
			{ payout.automatic === false ? (
				<ul
					className="woocommerce-woopayments-payout-overview woocommerce-woopayments-payout-overview--instant"
					aria-label={
						isWithdrawal
							? __( 'Withdrawal overview', 'woocommerce' )
							: __( 'Payout overview', 'woocommerce' )
					}
				>
					<PayoutDateItem payout={ payout } />
					<OverviewItem
						label={
							isWithdrawal
								? __( 'Withdrawal amount', 'woocommerce' )
								: __( 'Payout amount', 'woocommerce' )
						}
						value={ formatExplicitCurrency(
							payout.amount + fee,
							payout.currency
						) }
					/>
					<OverviewItem
						label={ sprintf(
							/* translators: %s - amount representing the fee percentage */
							__( '%s service fee', 'woocommerce' ),
							`${ payout.fee_percentage || 0 }%`
						) }
						value={ formatWooPaymentsAmount(
							fee,
							payout.currency
						) }
						valueClassName={
							fee > 0 &&
							'woocommerce-woopayments-payout-overview__value--fee'
						}
					/>
					<OverviewItem
						label={
							isWithdrawal
								? __( 'Net withdrawal amount', 'woocommerce' )
								: __( 'Net payout amount', 'woocommerce' )
						}
						value={ formatExplicitCurrency(
							payout.amount,
							payout.currency
						) }
						valueClassName="woocommerce-woopayments-payout-overview__value--net"
					/>
				</ul>
			) : (
				<ul className="woocommerce-woopayments-payout-overview woocommerce-woopayments-payout-overview--automatic">
					<PayoutDateItem payout={ payout } />
					<li className="woocommerce-woopayments-payout-overview__amount">
						{ formatExplicitCurrency(
							payout.amount,
							payout.currency
						) }
					</li>
				</ul>
			) }
			{ payout.status === 'failed' && (
				<Notice status="error" isDismissible={ false }>
					<strong>{ __( 'Failure reason:', 'woocommerce' ) }</strong>{ ' ' }
					{ failureReason }
				</Notice>
			) }
		</>
	);
};

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
					// Client 11.1.0 data/deposits/resolvers.js:44-51.
					(
						dispatch( 'core/notices' ) as unknown as {
							createErrorNotice: ( message: string ) => void;
						}
					 ).createErrorNotice(
						__( 'Error retrieving payout.', 'woocommerce' )
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
	const payoutTitle = isWithdrawal
		? __( 'Withdrawal details', 'woocommerce' )
		: __( 'Payout details', 'woocommerce' );
	const payoutTransactionsTitle = isWithdrawal
		? __( 'Withdrawal transactions', 'woocommerce' )
		: __( 'Payout transactions', 'woocommerce' );
	const bankReferenceId = payout?.bank_reference_key;

	return (
		<section
			className="woocommerce-woopayments-money-movement"
			aria-busy={ isLoading }
		>
			{ /* Client 11.1.0 deposits/details/index.tsx:317. */ }
			<WooPaymentsTestModeNotice currentPage="deposits" isDetailsView />
			<p
				className="screen-reader-text"
				role={ errorMessage ? 'alert' : 'status' }
				aria-live={ errorMessage ? 'assertive' : 'polite' }
			>
				{ liveStatusMessage }
			</p>
			{ /* Client 11.1.0 deposits/details/index.tsx:319-323. */ }
			{ isLoading && <SummaryListPlaceholder numberOfItems={ 2 } /> }
			{ /* Client 11.1.0 deposits/details/index.tsx:135-144; the live region above carries the reason. */ }
			{ errorMessage && (
				<Notice
					status="error"
					isDismissible={ false }
					spokenMessage={ null }
					className="woocommerce-woopayments-payout-details-error"
				>
					<ErrorIcon className="woocommerce-woopayments-payout-details-error__icon" />
					<span>
						{ __(
							'The deposit you are looking for cannot be found.',
							'woocommerce'
						) }
					</span>
				</Notice>
			) }
			{ payout && ! errorMessage && (
				<>
					<PayoutOverview payout={ payout } />
					{ /* Client 11.1.0 deposits/details/index.tsx:245-305: a titled card, the bank account and reference in one row. */ }
					<Card>
						<CardHeader>
							<h2 className="woocommerce-woopayments-payout-details__title">
								{ payoutTitle }
							</h2>
						</CardHeader>
						<CardBody>
							<dl className="woocommerce-woopayments-payout-details">
								<div className="woocommerce-woopayments-payout-details__item">
									<dt>
										{ __( 'Bank account', 'woocommerce' ) }
									</dt>
									<dd>
										{ payout.bankAccount ||
											__(
												'Not available',
												'woocommerce'
											) }
									</dd>
								</div>
								<div className="woocommerce-woopayments-payout-details__item">
									<dt>
										{ __(
											'Bank reference ID',
											'woocommerce'
										) }
									</dt>
									<dd>
										{ bankReferenceId ? (
											<>
												<span className="woocommerce-woopayments-payout-details__reference">
													{ bankReferenceId }
												</span>
												<Button
													icon={ copy }
													size="small"
													onClick={
														copyBankReferenceId
													}
													label={ __(
														'Copy bank reference ID to clipboard',
														'woocommerce'
													) }
												/>
											</>
										) : (
											__( 'Not available', 'woocommerce' )
										) }
									</dd>
								</div>
							</dl>
						</CardBody>
					</Card>
					{ isInstantPayout ? (
						<Card>
							<CardHeader>
								<h2 className="woocommerce-woopayments-payout-details__title">
									{ payoutTransactionsTitle }
								</h2>
							</CardHeader>
							<CardBody>
								{ __(
									"We're unable to show transaction history on instant payouts.",
									'woocommerce'
								) }{ ' ' }
								<ExternalLink href={ INSTANT_PAYOUTS_DOCS_URL }>
									{ __( 'Learn more', 'woocommerce' ) }
								</ExternalLink>
							</CardBody>
						</Card>
					) : (
						// Client 11.1.0 deposits/details/index.tsx:356 and transactions/list/index.tsx:590: the
						// transactions list card, scoped to the payout and titled "Transactions".
						<WooPaymentsTransactionsList
							depositId={ payout.id }
							buildRoute={ buildPayoutTransactionsRoute }
							title={ __( 'Transactions', 'woocommerce' ) }
						/>
					) }
				</>
			) }
		</section>
	);
};

export default WooPaymentsPayoutDetailsPage;
