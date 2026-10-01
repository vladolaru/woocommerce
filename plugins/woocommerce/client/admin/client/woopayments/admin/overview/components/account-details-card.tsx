/**
 * External dependencies
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Flex,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { caution, check, error, info, published } from '@wordpress/icons';
import { addQueryArgs } from '@wordpress/url';
import { recordEvent } from '@woocommerce/tracks';
import type { ReactElement, ReactNode } from 'react';

/**
 * Internal dependencies
 */
import type {
	WooPaymentsOverviewAccountDetails,
	WooPaymentsOverviewAccountDetailsBanner,
	WooPaymentsOverviewAccountFee,
} from '../types';
import { AccountFees } from './account-fees';
import { AccountTools } from './account-tools';
import { HelpPopover } from './help-popover';
import { getStatusChipTypeFromColor, StatusChip } from './status-chip';
import BannerNotice from '~/settings-payments/onboarding/providers/woopayments/components/banner-notice';

const ACCOUNT_DETAILS_SOURCE = {
	from: 'WCPAY_ACCOUNT_DETAILS',
	source: 'wcpay-account-details',
};

// Client 11.1.0 `components/account-details/banner.tsx:22-37` and `utils.ts:36-48`.
const BANNER_STATUS: Record<
	string,
	'success' | 'info' | 'warning' | 'error'
> = {
	green: 'success',
	blue: 'info',
	yellow: 'warning',
	red: 'error',
};
const BANNER_ICONS: Record< string, ReactElement > = {
	published,
	caution,
	error,
	info,
	check,
};

const AccountDetailsBanner = ( {
	banner,
}: {
	banner?: WooPaymentsOverviewAccountDetailsBanner | null;
} ) => {
	if ( ! banner?.text ) {
		return null;
	}

	return (
		<BannerNotice
			className="woocommerce-woopayments-account-details__banner"
			status={
				BANNER_STATUS[ String( banner.background_color ) ] ?? 'info'
			}
			icon={ BANNER_ICONS[ String( banner.icon ) ] ?? info }
			isDismissible={ false }
		>
			{ banner.text }
			{ banner.cta_text && banner.cta_link && (
				<>
					{ ' ' }
					<ExternalLink href={ banner.cta_link }>
						{ banner.cta_text }
					</ExternalLink>
				</>
			) }
		</BannerNotice>
	);
};

// Client 11.1.0 `components/account-details/index.tsx:32-43`.
const AccountDetailsShell = ( {
	header,
	children,
}: {
	header?: ReactNode;
	children: ReactNode;
} ) => (
	<Card className="woocommerce-woopayments-account-details">
		<CardHeader className="woocommerce-woopayments-account-details__header">
			<h2
				className="woocommerce-woopayments-overview-card__title"
				tabIndex={ -1 }
			>
				{ __( 'Account details', 'woocommerce' ) }
			</h2>
			{ header }
		</CardHeader>
		<CardBody>{ children }</CardBody>
	</Card>
);

export const AccountDetailsCard = ( {
	accountDetails,
	accountFees = [],
	accountLink,
	isTestModeOnboarding = false,
	onboardingUrl = '',
}: {
	accountDetails?: WooPaymentsOverviewAccountDetails | null;
	accountFees?: WooPaymentsOverviewAccountFee[];
	accountLink?: string;
	isTestModeOnboarding?: boolean;
	onboardingUrl?: string;
} ) => {
	// Client 11.1.0 `components/account-details/index.tsx:45-52,96-97`.
	if ( ! accountDetails ) {
		return (
			<AccountDetailsShell>
				{ __( 'Error loading account details.', 'woocommerce' ) }
			</AccountDetailsShell>
		);
	}

	// Client 11.1.0 `components/account-details/index.tsx:59-64` and `header-title.tsx:37-55`.
	const editDetailsLink = accountLink
		? addQueryArgs( accountLink, ACCOUNT_DETAILS_SOURCE )
		: '';
	const { account_status: accountStatus, payout_status: payoutStatus } =
		accountDetails;

	return (
		<AccountDetailsShell
			header={
				<>
					{ accountStatus.text && (
						<StatusChip
							message={ accountStatus.text }
							type={ getStatusChipTypeFromColor(
								accountStatus.background_color
							) }
						/>
					) }
					{ editDetailsLink && (
						<Button
							className="woocommerce-woopayments-account-details__edit"
							variant="link"
							href={ editDetailsLink }
							target="_blank"
							onClick={ () =>
								recordEvent(
									'wcpay_account_details_link_clicked',
									ACCOUNT_DETAILS_SOURCE
								)
							}
						>
							{ __( 'Edit details', 'woocommerce' ) }
						</Button>
					) }
					<AccountDetailsBanner banner={ accountDetails.banner } />
				</>
			}
		>
			{ /* Client 11.1.0 `components/account-details/payout-status-wrapper.tsx:19-86`. */ }
			<div className="woocommerce-woopayments-account-details__payouts">
				<span>{ __( 'Payouts:', 'woocommerce' ) }</span>
				{ /* The chip and its help sit together, as the client's inner `Flex gap={ 0 }`. */ }
				<Flex
					align="center"
					gap={ 0 }
					justify="flex-start"
					expanded={ false }
				>
					{ payoutStatus.text && (
						<StatusChip
							message={ payoutStatus.text }
							type={ getStatusChipTypeFromColor(
								payoutStatus.background_color
							) }
						/>
					) }
					{ payoutStatus.popover?.text && (
						<HelpPopover
							size={ 24 }
							label={ __(
								'More information about payout status',
								'woocommerce'
							) }
						>
							{ payoutStatus.popover.text }
							{ payoutStatus.popover.cta_text &&
								payoutStatus.popover.cta_link && (
									<>
										{ ' ' }
										<ExternalLink
											href={
												payoutStatus.popover.cta_link
											}
										>
											{ payoutStatus.popover.cta_text }
										</ExternalLink>
									</>
								) }
						</HelpPopover>
					) }
				</Flex>
			</div>
			{ isTestModeOnboarding && (
				<AccountTools onboardingUrl={ onboardingUrl } />
			) }
			<AccountFees accountFees={ accountFees } />
		</AccountDetailsShell>
	);
};
