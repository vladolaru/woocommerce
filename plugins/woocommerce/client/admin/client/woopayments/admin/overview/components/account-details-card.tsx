/**
 * External dependencies
 */
import { Button, Dropdown, ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { help } from '@wordpress/icons';
import { addQueryArgs } from '@wordpress/url';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import type {
	WooPaymentsOverviewAccountDetails,
	WooPaymentsOverviewAccountDetailsStatus,
	WooPaymentsOverviewAccountFee,
} from '../types';
import { AccountFees } from './account-fees';

const ACCOUNT_DETAILS_SOURCE = {
	from: 'WCPAY_ACCOUNT_DETAILS',
	source: 'wcpay-account-details',
};

// Client 11.1.0 `components/account-details/payout-status-wrapper.tsx:19-86`.
const PayoutStatusPopover = ( {
	popover,
}: {
	popover: WooPaymentsOverviewAccountDetailsStatus[ 'popover' ];
} ) => {
	if ( ! popover?.text ) {
		return null;
	}

	return (
		<Dropdown
			className="woocommerce-woopayments-account-details__payout-popover"
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					icon={ help }
					size="small"
					label={ __(
						'More information about payout status',
						'woocommerce'
					) }
					aria-expanded={ isOpen }
					onClick={ onToggle }
				/>
			) }
			renderContent={ () => (
				<div className="woocommerce-woopayments-account-details__payout-popover-content">
					{ popover.text }
					{ popover.cta_text && popover.cta_link && (
						<>
							{ ' ' }
							<ExternalLink href={ popover.cta_link }>
								{ popover.cta_text }
							</ExternalLink>
						</>
					) }
				</div>
			) }
		/>
	);
};

export const AccountDetailsCard = ( {
	accountDetails,
	accountFees = [],
	accountLink,
}: {
	accountDetails?: WooPaymentsOverviewAccountDetails | null;
	accountFees?: WooPaymentsOverviewAccountFee[];
	accountLink?: string;
} ) => {
	if ( ! accountDetails ) {
		return null;
	}

	// Client 11.1.0 `components/account-details/index.tsx:59-64` and `header-title.tsx:37-55`.
	const editDetailsLink = accountLink
		? addQueryArgs( accountLink, ACCOUNT_DETAILS_SOURCE )
		: '';

	return (
		<section className="woocommerce-woopayments-overview-card woocommerce-woopayments-account-details">
			<div className="woocommerce-woopayments-account-details__header">
				<h2 tabIndex={ -1 }>
					{ __( 'Account details', 'woocommerce' ) }
				</h2>
				{ editDetailsLink && (
					<ExternalLink
						href={ editDetailsLink }
						onClick={ () =>
							recordEvent(
								'wcpay_account_details_link_clicked',
								ACCOUNT_DETAILS_SOURCE
							)
						}
					>
						{ __( 'Edit details', 'woocommerce' ) }
					</ExternalLink>
				) }
			</div>
			<div className="woocommerce-woopayments-account-details__status-grid">
				<div>
					<h3>{ __( 'Account status', 'woocommerce' ) }</h3>
					<p>{ accountDetails.account_status.text || '-' }</p>
				</div>
				<div>
					<h3>{ __( 'Payout status', 'woocommerce' ) }</h3>
					<div className="woocommerce-woopayments-account-details__payout-status">
						<p>{ accountDetails.payout_status.text || '-' }</p>
						<PayoutStatusPopover
							popover={ accountDetails.payout_status.popover }
						/>
					</div>
				</div>
			</div>
			{ accountDetails.banner?.text && (
				<div className="woocommerce-woopayments-account-details__banner">
					<p>{ accountDetails.banner.text }</p>
					{ accountDetails.banner.cta_link &&
						accountDetails.banner.cta_text && (
							<a href={ accountDetails.banner.cta_link }>
								{ accountDetails.banner.cta_text }
							</a>
						) }
				</div>
			) }
			<AccountFees accountFees={ accountFees } />
		</section>
	);
};
