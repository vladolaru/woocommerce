/**
 * External dependencies
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Modal,
	Notice,
} from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { createInterpolateElement, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { recordEvent } from '@woocommerce/tracks';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import type {
	WooPaymentsDeposit,
	WooPaymentsDepositsOverview,
	WooPaymentsInstantBalance,
} from '../types';
import {
	formatWooPaymentsAmount,
	getBalanceCurrencyOptions,
	getAmountForCurrency,
	getInstantBalanceForCurrency,
	getSelectedBalanceCurrency,
} from '../utils';
import { getSettingsPaymentsProviderRouteUrl } from '../../utils';
import { formatExplicitCurrency } from '../../currency';
import { HelpPopover } from './help-popover';

const INSTANT_PAYOUTS_DOCS_URL =
	'https://woocommerce.com/document/woopayments/payouts/instant-payouts/';
// Client 11.1.0 `components/account-balances/strings.ts:11-16`.
const PAYOUT_SCHEDULE_DOCS_URL =
	'https://woocommerce.com/document/woopayments/payouts/payout-schedule/';
const NEGATIVE_BALANCE_DOCS_URL =
	'https://woocommerce.com/document/woopayments/fees/account-showing-negative-balance/';

const docsLink = ( href: string ) => (
	<ExternalLink href={ href }>
		<></>
	</ExternalLink>
);

const NegativeBalanceHint = () =>
	createInterpolateElement(
		__( 'Negative account balance? <a>Discover why.</a>', 'woocommerce' ),
		{ a: docsLink( NEGATIVE_BALANCE_DOCS_URL ) }
	);

// Client 11.1.0 `components/account-balances/balance-block.tsx` with its `balance-tooltip.tsx` help.
const BalanceBlock = ( {
	id,
	title,
	amount,
	currency,
	help,
}: {
	id: string;
	title: string;
	amount: number;
	currency: string;
	help: ReactNode;
} ) => (
	<div className="woocommerce-woopayments-overview__balance">
		<div className="woocommerce-woopayments-overview__balance-title">
			<span id={ id }>{ title }</span>
			<HelpPopover
				label={ sprintf(
					/* translators: %s: Balance name, like "Total balance". */
					__( '%s tooltip', 'woocommerce' ),
					title
				) }
			>
				{ help }
			</HelpPopover>
		</div>
		<p
			className="woocommerce-woopayments-overview__balance-amount"
			aria-labelledby={ id }
		>
			{ formatWooPaymentsAmount( amount, currency ) }
		</p>
	</div>
);

const InstantPayoutModal = ( {
	instantBalance,
	isSubmitting,
	onClose,
	onSubmit,
}: {
	instantBalance: WooPaymentsInstantBalance;
	isSubmitting: boolean;
	onClose: () => void;
	onSubmit: () => void;
} ) => {
	const feePercentage = `${ instantBalance.fee_percentage }%`;

	return (
		<Modal
			title={ __( 'Instant payout', 'woocommerce' ) }
			onRequestClose={ onClose }
			className="woocommerce-woopayments-instant-payout-modal"
		>
			<p>
				{ sprintf(
					/* translators: %s: Instant payout fee percentage. */
					__(
						'Need cash in a hurry? Instant payouts are available within 30 minutes for a nominal %s service fee.',
						'woocommerce'
					),
					feePercentage
				) }{ ' ' }
				<ExternalLink href={ INSTANT_PAYOUTS_DOCS_URL }>
					{ __( 'Learn more', 'woocommerce' ) }
				</ExternalLink>
			</p>
			<ul>
				<li className="woocommerce-woopayments-instant-payout-modal__balance">
					{ __(
						'Balance available for instant payout:',
						'woocommerce'
					) }{ ' ' }
					<span>
						{ formatWooPaymentsAmount(
							instantBalance.amount,
							instantBalance.currency
						) }
					</span>
				</li>
				<li className="woocommerce-woopayments-instant-payout-modal__fee">
					{ sprintf(
						/* translators: %s: Instant payout fee percentage. */
						__( '%s service fee:', 'woocommerce' ),
						feePercentage
					) }{ ' ' }
					<span>
						-
						{ formatWooPaymentsAmount(
							instantBalance.fee,
							instantBalance.currency
						) }
					</span>
				</li>
				<li className="woocommerce-woopayments-instant-payout-modal__net">
					{ __( 'Net payout amount:', 'woocommerce' ) }{ ' ' }
					<span>
						{ formatExplicitCurrency(
							instantBalance.net,
							instantBalance.currency
						) }
					</span>
				</li>
			</ul>

			<div className="woocommerce-woopayments-instant-payout-modal__footer">
				<Button
					variant="secondary"
					onClick={ onClose }
					__next40pxDefaultSize
				>
					{ __( 'Cancel', 'woocommerce' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ onSubmit }
					isBusy={ isSubmitting }
					disabled={ isSubmitting }
					__next40pxDefaultSize
				>
					{ sprintf(
						/* translators: %s: Net instant payout amount. */
						__( 'Pay out %s now', 'woocommerce' ),
						formatExplicitCurrency(
							instantBalance.net,
							instantBalance.currency
						)
					) }
				</Button>
			</div>
		</Modal>
	);
};

export const AccountBalancesCard = ( {
	isLoading,
	errorMessage,
	overview,
	selectedCurrency,
	onCurrencyChange,
	onInstantPayoutSubmit,
	instantDepositsPreviouslyEligible = false,
}: {
	isLoading: boolean;
	errorMessage: string | null;
	overview: WooPaymentsDepositsOverview | null;
	selectedCurrency?: string;
	onCurrencyChange?: ( currency: string ) => void;
	onInstantPayoutSubmit?: (
		currency: string
	) => Promise< WooPaymentsDeposit >;
	instantDepositsPreviouslyEligible?: boolean;
} ) => {
	const [ isInstantPayoutModalOpen, setIsInstantPayoutModalOpen ] =
		useState( false );
	const [ isInstantPayoutSubmitting, setIsInstantPayoutSubmitting ] =
		useState( false );
	const headingId = 'woocommerce-woopayments-balance-heading';
	const currencySelectId = 'woocommerce-woopayments-balance-currency';
	const statusMessage =
		( isLoading && __( 'Loading balance…', 'woocommerce' ) ) ||
		errorMessage ||
		'';

	if ( ! isLoading && ! errorMessage && ! overview ) {
		return null;
	}

	const currency = overview
		? getSelectedBalanceCurrency( overview, selectedCurrency )
		: '';
	const currencyOptions = overview
		? getBalanceCurrencyOptions( overview )
		: [];
	const available = overview
		? getAmountForCurrency( overview.balance?.available, currency )
		: 0;
	const pending = overview
		? getAmountForCurrency( overview.balance?.pending, currency )
		: 0;
	const total = available + pending;
	const hasBalanceData = ! isLoading && ! errorMessage && !! overview;
	const instantBalance = hasBalanceData
		? getInstantBalanceForCurrency( overview, currency )
		: null;
	const hasInstantBalance = !! instantBalance && instantBalance.amount > 0;
	const submitInstantPayout = async () => {
		if ( ! instantBalance || ! onInstantPayoutSubmit ) {
			return;
		}

		setIsInstantPayoutSubmitting( true );

		try {
			const deposit = await onInstantPayoutSubmit(
				instantBalance.currency
			);
			const depositAmount = formatWooPaymentsAmount(
				deposit.amount,
				deposit.currency || instantBalance.currency
			);

			setIsInstantPayoutModalOpen( false );
			dispatch( 'core/notices' ).createSuccessNotice(
				sprintf(
					/* translators: %s: Instant payout amount. */
					__( 'Instant payout for %s in transit.', 'woocommerce' ),
					depositAmount
				),
				{
					actions: [
						{
							label: __( 'View details', 'woocommerce' ),
							url: getSettingsPaymentsProviderRouteUrl(
								`/woopayments/payouts/details?id=${ encodeURIComponent(
									deposit.id
								) }`
							),
						},
					],
				}
			);
		} catch ( error ) {
			dispatch( 'core/notices' ).createErrorNotice(
				__( 'Error creating instant payout.', 'woocommerce' )
			);
		} finally {
			setIsInstantPayoutSubmitting( false );
		}
	};

	return (
		<Card
			as="section"
			className="woocommerce-woopayments-overview__balances-card"
			aria-labelledby={ headingId }
			aria-busy={ isLoading }
		>
			<CardHeader>
				<h2
					id={ headingId }
					className="woocommerce-woopayments-overview-card__title"
					tabIndex={ -1 }
				>
					{ __( 'Balance', 'woocommerce' ) }
				</h2>
				{ hasBalanceData && currencyOptions.length > 1 && (
					<div className="woocommerce-woopayments-overview__currency-selector">
						<label htmlFor={ currencySelectId }>
							{ __( 'Balance currency', 'woocommerce' ) }
						</label>
						<select
							id={ currencySelectId }
							value={ currency }
							onChange={ ( event ) => {
								onCurrencyChange?.( event.target.value );
								// Client 11.1.0 `components/welcome/currency-select.tsx:99-103`.
								recordEvent(
									'wcpay_overview_currency_select_change',
									{
										selected_currency:
											event.target.value.toLowerCase(),
									}
								);
							} }
						>
							{ currencyOptions.map( ( currencyOption ) => (
								<option
									key={ currencyOption }
									value={ currencyOption }
								>
									{ currencyOption.toUpperCase() }
								</option>
							) ) }
						</select>
					</div>
				) }
			</CardHeader>
			<p
				className={
					hasBalanceData
						? 'screen-reader-text'
						: 'woocommerce-woopayments-overview__status'
				}
				role={ errorMessage ? 'alert' : 'status' }
				aria-live={ errorMessage ? 'assertive' : 'polite' }
			>
				{ statusMessage }
			</p>
			{ hasBalanceData && (
				<>
					<CardBody className="woocommerce-woopayments-overview__balances">
						<BalanceBlock
							id={ `woocommerce-woopayments-balance-${ currency }-total` }
							title={ __( 'Total balance', 'woocommerce' ) }
							amount={ total }
							currency={ currency }
							help={
								<>
									<p>
										{ createInterpolateElement(
											__(
												'<b>Total balance</b> combines both pending funds (transactions under processing) and available funds (ready for payout). <a>Learn more</a>',
												'woocommerce'
											),
											{
												b: <b />,
												a: docsLink(
													PAYOUT_SCHEDULE_DOCS_URL
												),
											}
										) }
									</p>
									<p className="woocommerce-woopayments-overview__balance-formula">
										{ __(
											'Total balance = Available funds + Pending funds',
											'woocommerce'
										) }
									</p>
									{ total < 0 && (
										<p>
											<NegativeBalanceHint />
										</p>
									) }
								</>
							}
						/>
						<BalanceBlock
							id={ `woocommerce-woopayments-balance-${ currency }-available` }
							title={ __( 'Available funds', 'woocommerce' ) }
							amount={ available }
							currency={ currency }
							help={
								<>
									<p>
										{ createInterpolateElement(
											__(
												'<b>Available funds</b> have completed processing and are ready to be dispatched to your bank account. <a>Learn more</a>',
												'woocommerce'
											),
											{
												b: <b />,
												a: docsLink(
													PAYOUT_SCHEDULE_DOCS_URL
												),
											}
										) }
									</p>
									{ available < 0 && (
										<p>
											<NegativeBalanceHint />
										</p>
									) }
								</>
							}
						/>
					</CardBody>
					{ hasInstantBalance && (
						<CardBody className="woocommerce-woopayments-overview__instant-payout">
							<p>
								{ sprintf(
									/* translators: 1: Available instant payout amount, 2: Instant payout fee percentage. */
									__(
										'Get %1$s via instant payout. Funds are typically in your bank account within 30 mins. Fee: %2$s%%.',
										'woocommerce'
									),
									formatWooPaymentsAmount(
										instantBalance.amount,
										instantBalance.currency
									),
									String( instantBalance.fee_percentage )
								) }
							</p>
							<Button
								variant="primary"
								onClick={ () =>
									setIsInstantPayoutModalOpen( true )
								}
								disabled={ ! onInstantPayoutSubmit }
								__next40pxDefaultSize
							>
								{ sprintf(
									/* translators: %s: Available instant payout amount. */
									__( 'Get %s now', 'woocommerce' ),
									formatWooPaymentsAmount(
										instantBalance.amount,
										instantBalance.currency
									)
								) }
							</Button>
						</CardBody>
					) }
					{ /* Client 11.1.0 `components/account-balances/index.tsx:226-251`. */ }
					{ instantDepositsPreviouslyEligible &&
						( ! instantBalance || instantBalance.amount === 0 ) && (
							<Notice
								className="woocommerce-woopayments-overview__instant-payout-unavailable"
								status="warning"
								isDismissible={ false }
							>
								{ createInterpolateElement(
									__(
										'Instant payouts are currently unavailable for your account. <a>Learn about eligibility requirements</a>',
										'woocommerce'
									),
									{
										a: (
											<ExternalLink
												href={
													INSTANT_PAYOUTS_DOCS_URL
												}
											>
												<></>
											</ExternalLink>
										),
									}
								) }
							</Notice>
						) }
					{ isInstantPayoutModalOpen && instantBalance && (
						<InstantPayoutModal
							instantBalance={ instantBalance }
							isSubmitting={ isInstantPayoutSubmitting }
							onClose={ () =>
								setIsInstantPayoutModalOpen( false )
							}
							onSubmit={ submitInstantPayout }
						/>
					) }
				</>
			) }
		</Card>
	);
};
